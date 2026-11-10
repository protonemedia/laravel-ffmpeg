<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\LiveHlsResult;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Symfony\Component\Process\Process as Server;

#[RequiresEnv('FFMPEG_RTSP_SERVER')]
class RtspServerTest extends TestCase
{
    /**
     * The MediaMTX server shared by every test in the class.
     */
    protected static ?Server $server = null;

    /**
     * The RTSP port of the shared MediaMTX server.
     */
    protected static int $port;

    /**
     * The path of the real FFmpeg binary.
     */
    protected string $binary;

    /**
     * The running publishers, keyed by their path on the server.
     *
     * @var array<string, InvokedProcess>
     */
    protected array $publishers = [];

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->binary = $this->useRealFFMpeg();

        config([
            'laravel-ffmpeg.redact_credentials' => true,
            'filesystems.disks.rtsp' => ['driver' => 'local', 'root' => $this->root.'/output'],
        ]);

        static::$server ??= $this->startServer();
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        foreach (array_keys($this->publishers) as $path) {
            $this->unpublish($path);
        }

        parent::tearDown();
    }

    /**
     * Stop the shared server after the last test of the class.
     */
    public static function tearDownAfterClass(): void
    {
        static::$server?->stop(1);
        static::$server = null;

        parent::tearDownAfterClass();
    }

    #[TestWith(['tcp'])]
    #[TestWith(['udp'])]
    public function test_snapshots_authenticate_without_leaking_the_password(string $transport): void
    {
        $this->publish('secure');
        static::$server->getIncrementalOutput();

        $frame = FFMpeg::openRtsp($this->url('secure', 'camera-user:camera-pa55%40word'), $transport, timeout: 10)
            ->frame()
            ->toDisk('rtsp');
        $inspection = json_encode($frame->inspect(), JSON_UNESCAPED_SLASHES);
        $result = $frame->save('snapshot.png');

        $video = FFMpeg::disk('rtsp')
            ->open('snapshot.png')
            ->probe()
            ->video();

        $this->assertSame('png', $video->codec());
        $this->assertSame(160, $video->width());
        $this->assertSame(120, $video->height());
        $this->assertStringContainsString(
            "is reading from path 'secure', with ".strtoupper($transport),
            static::$server->getIncrementalOutput(),
        );
        $this->assertCredentialsAreRedacted($inspection);
        $this->assertCredentialsAreRedacted(json_encode($result->processes(), JSON_UNESCAPED_SLASHES));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_wrong_credentials_fail_with_a_sanitized_exception(): void
    {
        $this->publish('secure');

        $frame = FFMpeg::openRtsp($this->url('secure', 'camera-user:guess-pa55%40word'), timeout: 10)
            ->frame()
            ->toDisk('rtsp');

        $this->assertCredentialsAreRedacted(json_encode($frame->inspect(), JSON_UNESCAPED_SLASHES));

        $this->assertThrows(function () use ($frame) {
            $frame->save('snapshot.png');
        }, function (MediaException $exception) {
            $this->assertSame('Cannot capture the RTSP snapshot.', $exception->getMessage());
            $this->assertSame('staging', $exception->phase);
            $this->assertSame('capture', $exception->category);
            $this->assertStringContainsString('401 Unauthorized', $exception->diagnostics);
            $this->assertStringContainsString('[rtsp]', $exception->diagnostics);
            $this->assertCredentialsAreRedacted($exception->getMessage());
            $this->assertCredentialsAreRedacted($exception->diagnostics);

            return true;
        });
        $this->assertSame([], Storage::disk('rtsp')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_live_hls_publishes_a_sliding_playlist_until_the_publisher_stops(): void
    {
        $this->publish('open');
        $disk = Storage::disk('rtsp');

        // MediaMTX sends its first RTCP sender report about ten seconds after a reader joins,
        // so the session must outlive it. Stopping the publisher afterwards closes the reader,
        // which FFmpeg reads as a clean EOF that completes the session.
        $live = FFMpeg::liveHls($this->url('open'), segmentDuration: 1, window: 2)
            ->toDisk('rtsp')
            ->timeout(25);
        $live->onPublish(function (LiveHlsResult $snapshot) {
            if ($snapshot->publishedSegments >= 12) {
                $this->unpublish('open');
            }
        });
        $result = $live->run('camera');

        $this->assertSame('completed', $result->status);
        $this->assertSame(1, $result->attempts);
        $this->assertGreaterThanOrEqual(12, $result->publishedSegments);
        $this->assertCount(2, $result->segments);
        $this->assertMatchesRegularExpression(<<<'REGEX'
            /\A#EXTM3U
            #EXT-X-VERSION:3
            #EXT-X-TARGETDURATION:2
            #EXT-X-MEDIA-SEQUENCE:\d+
            #EXT-X-DISCONTINUITY-SEQUENCE:0
            (#EXTINF:[0-9.]+,
            live-[0-9a-f]{24}-[0-9]{12}\.ts
            ){2}#EXT-X-ENDLIST
            \z/
            REGEX, $disk->get($result->playlist));

        foreach ($result->segments as $segment) {
            $this->assertStringContainsString(basename($segment), $disk->get($result->playlist));
        }

        $metadata = FFMpeg::disk('rtsp')
            ->open($result->segments[0])
            ->probe();

        $this->assertSame('h264', $metadata->video()->codec());
        $this->assertSame('aac', $metadata->audio()->codec());
        $this->assertEqualsWithDelta(1, $metadata->duration(), 0.5);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_live_hls_reconnects_when_the_publisher_returns(): void
    {
        $this->publish('open');
        $disk = Storage::disk('rtsp');
        $sequences = [];
        $restarted = false;

        $live = FFMpeg::liveHls($this->url('open'), segmentDuration: 1, window: 3)
            ->toDisk('rtsp')
            ->retry(maxRetries: 3, delay: 0.2, reconnectOnEof: true)
            ->timeout(20);
        $live->onPublish(function (LiveHlsResult $snapshot) use ($live, &$sequences, &$restarted) {
            $sequences[] = $snapshot->lastSequence;

            // The encoder ends on the EOF while we wait for the new publisher, so the
            // reconnect after the delay finds the path ready again.
            if (! $restarted) {
                $this->unpublish('open');
                $this->publish('open');
                $restarted = true;
            } elseif ($snapshot->attempts === 2) {
                $live->stop();
            }
        });
        $result = $live->run('camera');
        $ordered = array_unique($sequences);
        sort($ordered);

        $this->assertSame('cancelled', $result->status);
        $this->assertSame(2, $result->attempts);
        $this->assertSame($ordered, $sequences);
        $this->assertStringContainsString("#EXT-X-DISCONTINUITY\n", $disk->get($result->playlist));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_live_hls_fails_cleanly_when_the_publisher_does_not_return(): void
    {
        $this->publish('open');
        $disk = Storage::disk('rtsp');

        $live = FFMpeg::liveHls($this->url('open'), segmentDuration: 1, window: 3)
            ->toDisk('rtsp')
            ->retry(maxRetries: 1, delay: 0.2, reconnectOnEof: true)
            ->timeout(15);
        $live->onPublish(function () {
            $this->unpublish('open');
        });

        // The reconnect finds no stream on the path, and the failed attempt exhausts the
        // retry budget, so the session ends with the last playlist left unfinished.

        $this->assertThrows(function () use ($live) {
            $live->run('camera');
        }, function (MediaException $exception) use ($disk) {
            $this->assertSame('Live source exhausted its reconnect policy after 2 attempts.', $exception->getMessage());
            $this->assertSame('live', $exception->phase);
            $this->assertSame('source', $exception->category);
            $this->assertContains(['disk' => 'rtsp', 'path' => 'camera/index.m3u8'], $exception->published);
            $this->assertEqualsCanonicalizing($disk->allFiles('camera'), array_column($exception->published, 'path'));

            return true;
        });
        $this->assertStringNotContainsString('#EXT-X-ENDLIST', $disk->get('camera/index.m3u8'));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Start a MediaMTX server with one public and one authenticated path.
     */
    protected function startServer(): Server
    {
        $port = static::$port = $this->freeTcpPort();
        $rtp = $this->freeUdpPortPair();
        $rtcp = $rtp + 1;
        $config = $this->fixturePath('mediamtx.yml');

        // Only the RTSP server is enabled, on loopback ports that are free right now. Any
        // local client may publish, but the "secure" path requires the camera user to read.
        file_put_contents($config, <<<YAML
            logLevel: info
            rtsp: true
            rtspTransports: [tcp, udp]
            rtspAddress: 127.0.0.1:{$port}
            rtpAddress: 127.0.0.1:{$rtp}
            rtcpAddress: 127.0.0.1:{$rtcp}
            rtmp: false
            hls: false
            webrtc: false
            srt: false
            moq: false
            api: false
            metrics: false
            pprof: false
            playback: false
            authInternalUsers:
              - user: any
                ips: [127.0.0.1]
                permissions:
                  - action: publish
                  - action: read
                    path: open
              - user: camera-user
                pass: camera-pa55@word
                ips: [127.0.0.1]
                permissions:
                  - action: read
                    path: secure
            paths:
              open:
              secure:
            YAML);

        $server = new Server([getenv('FFMPEG_RTSP_SERVER'), $config], dirname($config), timeout: null);
        $server->start();

        $deadline = microtime(true) + 5;
        while (! ($socket = @fsockopen('127.0.0.1', static::$port, timeout: 0.1)) && $server->isRunning()
            && microtime(true) < $deadline) {
            usleep(20000);
        }

        $this->assertNotFalse($socket, $server->getOutput().$server->getErrorOutput());

        fclose($socket);

        return $server;
    }

    /**
     * Get a loopback TCP port that is free right now.
     */
    protected function freeTcpPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    /**
     * Get an even loopback UDP port that is free right now, together with the port after it.
     */
    protected function freeUdpPortPair(): int
    {
        // MediaMTX requires an even RTP port with the RTCP port right after it.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $rtp = stream_socket_server('udp://127.0.0.1:0', flags: STREAM_SERVER_BIND);
            $port = (int) substr(strrchr(stream_socket_get_name($rtp, false), ':'), 1);
            $rtcp = $port % 2 === 0
                ? @stream_socket_server('udp://127.0.0.1:'.($port + 1), flags: STREAM_SERVER_BIND)
                : false;
            fclose($rtp);

            if ($rtcp !== false) {
                fclose($rtcp);

                return $port;
            }
        }

        $this->fail('Cannot find a free pair of UDP ports.');
    }

    /**
     * Get the RTSP URL of the given path, with optional user information.
     */
    protected function url(string $path, ?string $credentials = null): string
    {
        return 'rtsp://'.($credentials === null ? '' : $credentials.'@').'127.0.0.1:'.static::$port.'/'.$path;
    }

    /**
     * Start publishing a looping test stream to the given path and wait until it is readable.
     */
    protected function publish(string $path): void
    {
        $needle = "is publishing to path '{$path}'";
        $published = substr_count(static::$server->getOutput(), $needle);

        $this->publishers[$path] = Process::start([
            $this->binary, '-hide_banner', '-nostdin', '-loglevel', 'error', '-re',
            '-f', 'lavfi', '-i', 'testsrc2=size=160x120:rate=10',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency', '-g', '5', '-threads', '1',
            '-c:a', 'aac', '-f', 'rtsp', '-rtsp_transport', 'tcp', $this->url($path),
        ]);

        $deadline = microtime(true) + 5;
        while (substr_count(static::$server->getOutput(), $needle) === $published
            && $this->publishers[$path]->running() && microtime(true) < $deadline) {
            usleep(20000);
        }

        $this->assertGreaterThan(
            $published,
            substr_count(static::$server->getOutput(), $needle),
            $this->publishers[$path]->latestErrorOutput(),
        );
    }

    /**
     * Stop publishing to the given path if it is still published.
     */
    protected function unpublish(string $path): void
    {
        if (! isset($this->publishers[$path])) {
            return;
        }

        $publisher = $this->publishers[$path];
        unset($this->publishers[$path]);

        // Killing the publisher drops its connection the way a camera falls off the network,
        // and skips the graceful RTSP teardown that would slow down every test.
        if ($publisher->running()) {
            $publisher->signal(SIGKILL);
        }

        $publisher->wait();
    }

    /**
     * Assert that the given text contains no part of the camera passwords.
     */
    protected function assertCredentialsAreRedacted(string $text): void
    {
        $this->assertStringNotContainsString('pa55', $text);
        $this->assertStringNotContainsString('camera-user:', $text);
    }
}
