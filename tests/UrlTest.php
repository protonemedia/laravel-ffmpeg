<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;
use Symfony\Component\Process\Process as Server;

class UrlTest extends TestCase
{
    protected array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(0);
        }

        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function server(bool $tls = false, ?string $fixture = null): string
    {
        $command = [PHP_BINARY, __DIR__.'/src/http-input-server.php', $fixture ?? __DIR__.'/src/guitar.m4a', $this->root.'/requests'];

        if ($tls) {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
            openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $certificate);
            openssl_pkey_export($key, $privateKey);
            file_put_contents($this->root.'/cert.pem', $certificate);
            file_put_contents($this->root.'/key.pem', $privateKey);
            array_push($command, $this->root.'/cert.pem', $this->root.'/key.pem');
        }

        $server = new Server($command);
        $this->servers[] = $server;
        $server->start();
        $this->assertTrue($server->waitUntil(fn ($type, $buffer) => $type === Server::OUT && str_contains($buffer, "\n")));

        return ($tls ? 'https://' : 'http://').trim($server->getOutput());
    }

    public function test_direct_url_inspection_is_pure(): void
    {
        Http::preventStrayRequests();
        Process::fake();
        $url = 'https://media.example/audio.m4a?signature=private-signed-value';
        $media = FFMpeg::openUrl($url, inputOptions: ['-probesize', '2048']);
        $command = $media->export()->audio('copy')->inspect();
        $this->assertContains('2048', $command);
        $this->assertContains('https,tls,tcp', $command);
        $this->assertContains('-tls_verify', $command);
        $this->assertContains('15000000', $command);
        $this->assertStringNotContainsString('private-signed-value', json_encode($command));
        ob_start();
        var_dump($media);
        $this->assertStringNotContainsString('private-signed-value', ob_get_clean());
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_direct_urls_reject_headers(): void
    {
        Http::preventStrayRequests();
        Process::fake();

        $this->expectException(InvalidArgumentException::class);
        FFMpeg::openUrl('https://media.example/audio.m4a?signature=private-signed-value', headers: ['Authorization' => 'Bearer secret']);
    }

    #[DataProvider('invalidUrlProvider')]
    public function test_invalid_direct_urls_are_rejected_without_echoing_them(string $invalid): void
    {
        Http::preventStrayRequests();
        Process::fake();
        $this->assertThrows(
            fn () => FFMpeg::openUrl($invalid),
            fn (InvalidArgumentException $exception) => ! str_contains($exception->getMessage(), $invalid),
        );
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'file scheme' => ['file:///tmp/video'],
            'ftp scheme' => ['ftp://media.example/video'],
            'credentials' => ['https://user:password@media.example/video'],
            'newline' => ["https://media.example/\nvideo"],
        ];
    }

    public function test_public_urls_are_probed_and_exported_without_downloading(): void
    {
        $this->useRealFFMpeg();
        config(['laravel-ffmpeg.redact_credentials' => true]);
        Http::preventStrayRequests();
        $url = $this->server().'/public?signature=private-signed-value';
        $media = FFMpeg::openUrl($url);
        $metadata = $media->probe();
        $this->assertSame('[url]', $metadata->get('format.filename'));
        $this->assertTrue($metadata->hasAudio());
        $this->assertGreaterThan(1, $metadata->duration());
        $result = $media->export()->audio('copy')->toDisk('memory')->save('direct.m4a');
        $this->assertTrue(Storage::disk('memory')->exists('direct.m4a'));
        $this->assertStringNotContainsString('private-signed-value', json_encode($result->processes()));
        $this->assertWorkspacesAreCleanedUp();
        Http::assertNothingSent();
    }

    public function test_probe_failures_redact_partial_urls_in_diagnostics(): void
    {
        config(['laravel-ffmpeg.redact_credentials' => true]);
        $media = FFMpeg::openUrl('http://127.0.0.1:8000/public?signature=private-signed-value');

        // A partial URL in FFmpeg's diagnostics must not expose its query value.
        Process::fake(function ($process) {
            if (in_array('-version', $process->command)) {
                return Process::result(output: 'ffprobe version 9.0.2');
            }
            if (in_array('-protocols', $process->command)) {
                return Process::result(output: "Input:\nhttp\ntcp\nOutput:\nhttp\n");
            }

            return Process::result(errorOutput: 'Failed /public?signature=private-signed-value', exitCode: 1);
        });
        $this->assertThrows(
            fn () => $media->probe(),
            fn (MediaException $exception) => ! str_contains($exception->diagnostics, 'private-signed-value')
                && $exception->getPrevious() === null,
        );
    }

    public function test_authenticated_download_inspection_is_lazy_and_redacted(): void
    {
        $this->useRealFFMpeg();
        $base = $this->server();
        $first = FFMpeg::openUrl($base.'/private?signature=private-download-signature', headers: ['Authorization' => 'Bearer first-secret'], download: true, inputOptions: ['-probesize', '2048']);
        $command = $first->export()->inspect();
        $this->assertContains('2048', $command);
        $this->assertStringNotContainsString('first-secret', json_encode($command));
        $this->assertStringNotContainsString('private-download-signature', json_encode($command));
        ob_start();
        var_dump($first);
        $debug = ob_get_clean();
        $this->assertStringNotContainsString('first-secret', $debug);
        $this->assertStringNotContainsString('private-download-signature', $debug);
        $this->assertFileDoesNotExist($this->root.'/requests');
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_authenticated_downloads_keep_their_headers_separate(): void
    {
        $this->useRealFFMpeg();
        $base = $this->server();
        $first = FFMpeg::openUrl($base.'/private?signature=private-download-signature', headers: ['Authorization' => 'Bearer first-secret'], download: true, inputOptions: ['-probesize', '2048']);
        $second = FFMpeg::openUrl($base.'/private', ['Authorization' => 'Bearer second-secret'], download: true);
        $export = $first->export();
        $export->addInput($second);
        $result = $export->map('0:a:0')->map('1:a:0')->audio('copy')
            ->withoutMetadata(chapters: true)->toDisk('memory')->save('both.mkv');
        $metadata = FFMpeg::disk('memory')->open('both.mkv')->probe();
        $this->assertCount(2, $metadata->audioStreams());
        $this->assertStringNotContainsString('first-secret', json_encode($result->processes()));
        $this->assertStringNotContainsString('second-secret', json_encode($result->processes()));
        $this->assertStringNotContainsString($base, json_encode($result->processes()));
        $requests = array_map(fn ($line) => json_decode($line, true), file($this->root.'/requests'));
        $this->assertSame(['first', 'second'], array_column($requests, 'source'));
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith([false, null])]
    #[TestWith([true, 'first'])]
    public function test_url_watermarks_are_fetched_once_without_exposing_the_url(bool $authenticated, ?string $source): void
    {
        $this->useRealFFMpeg();
        $base = $this->server(fixture: __DIR__.'/src/logo.png');
        $url = $base.($authenticated ? '/private' : '/public').'?signature=private-image-signature';
        $logo = FFMpeg::openUrl($url, $authenticated ? ['Authorization' => 'Bearer first-secret'] : [], download: $authenticated);
        $result = FFMpeg::open(new File(__DIR__.'/src/video.mp4'))->export()->clip(0, 0.2)
            ->watermark($logo, fn (Watermark $watermark) => $watermark->flipHorizontally())
            ->video('ffv1')->withoutAudio()->toDisk('memory')->save('logo.mkv');
        $this->assertStringNotContainsString($base, json_encode($result->processes()));
        $this->assertStringNotContainsString('private-image-signature', json_encode($result->processes()));
        $this->assertStringNotContainsString('first-secret', json_encode($result->processes()));
        $this->assertWorkspacesAreCleanedUp();
        $requests = array_map(fn ($line) => json_decode($line, true), file($this->root.'/requests'));
        $this->assertSame([$source], array_column($requests, 'source'));
    }

    #[TestWith(['/redirect'])]
    #[TestWith(['/truncated'])]
    #[TestWith(['/first'])]
    public function test_failed_downloads_do_not_follow_redirects_publish_or_leak_exception_details(string $path): void
    {
        $base = $this->server();

        $this->assertThrows(
            fn () => FFMpeg::openUrl($base.$path.'?signature=hidden-signature', ['Authorization' => 'Bearer wrong-secret'], download: true)
                ->export()->toDisk('memory')->save('never.m4a'),
            fn (MediaException $exception) => $exception->phase === 'staging'
                && $exception->category === 'transfer'
                && $exception->getPrevious() === null
                && ! str_contains((string) $exception, 'hidden-signature')
                && ! str_contains((string) $exception, 'wrong-secret'),
        );
        $this->assertFalse(Storage::disk('memory')->exists('never.m4a'));
        $this->assertWorkspacesAreCleanedUp();

        $this->assertStringNotContainsString('/stolen', file_get_contents($this->root.'/requests'));
    }

    #[DataProvider('invalidHeaderProvider')]
    public function test_download_validation_rejects_header_injection(array $headers): void
    {
        Process::preventStrayProcesses();
        Http::fake(['*' => Http::response(<<<'M3U8'
            #EXTM3U
            https://elsewhere.example/segment.ts
            M3U8, 200)]);

        $this->assertThrows(
            fn () => FFMpeg::openUrl('https://media.example/input', $headers, download: true),
            fn (InvalidArgumentException $exception) => ! str_contains($exception->getMessage(), 'secret'),
        );
    }

    public static function invalidHeaderProvider(): array
    {
        return [
            'newline in name' => [["X-Test\r\nAuthorization" => 'secret']],
            'newline in value' => [['Authorization' => "secret\r\nX-Test: value"]],
            'array value' => [['X-Test' => ['array']]],
        ];
    }

    public function test_download_validation_rejects_staged_manifests_before_probing(): void
    {
        Process::preventStrayProcesses();
        Http::fake(['*' => Http::response(<<<'M3U8'
            #EXTM3U
            https://elsewhere.example/segment.ts
            M3U8, 200)]);

        $this->assertThrows(
            fn () => FFMpeg::openUrl('https://media.example/disguised.mp4', download: true)->probe(),
            fn (MediaException $exception) => $exception->category === 'manifest',
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_missing_https_support_is_reported_lazily_without_contacting_the_url(): void
    {
        Process::fake(function ($process) {
            return Process::result(output: in_array('-version', $process->command)
                ? 'ffprobe version 9.0.2' : "Input:\nfile\nhttp\ntcp\nOutput:\nfile\n");
        });
        $media = FFMpeg::openUrl('https://media.example/input?signature=hidden');
        $this->assertContains('https,tls,tcp', $media->export()->inspect());

        $this->assertThrows(
            fn () => $media->probe(),
            fn (MediaException $exception) => $exception->category === 'capability',
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_https_rejects_an_untrusted_certificate_in_both_transport_modes(bool $download): void
    {
        $this->useRealFFMpeg();
        $url = $this->server(tls: true).'/public';

        $this->assertThrows(
            fn () => FFMpeg::openUrl($url, download: $download)->probe(),
            fn (MediaException $exception) => $exception->phase === ($download ? 'staging' : 'probing')
                && ($download || str_contains(strtolower($exception->diagnostics), 'certificate')),
        );
        $this->assertWorkspacesAreCleanedUp();
    }
}
