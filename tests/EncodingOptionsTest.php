<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\AudioStreamSettings;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\VideoStreamSettings;

class EncodingOptionsTest extends TestCase
{
    protected function destination(): ExportOutput
    {
        return new ExportOutput('output.mkv', 'memory');
    }

    protected function arguments(ExportOutput $output): array
    {
        return $output->outputArguments(fn ($filter) => $filter, $output->maps());
    }

    public function test_partially_published_ffmpeg_config_keeps_the_default_thread_count(): void
    {
        // mergeConfigFrom() merges only the top level, so a published ffmpeg array may lack threads.
        config(['laravel-ffmpeg.ffmpeg' => ['binaries' => 'ffmpeg']]);

        $arguments = $this->arguments($this->destination());

        $this->assertSame('12', $arguments[array_search('-threads', $arguments, true) + 1]);

        config(['laravel-ffmpeg.ffmpeg.threads' => false]);

        $this->assertNotContains('-threads', $this->arguments($this->destination()));
    }

    public function test_stream_settings_are_serializable_snapshots(): void
    {
        $retained = null;
        $output = $this->destination()
            ->audio('aac')
            ->audioOptions(['-ar', '48000'])
            ->audioFilter('volume=0.5')
            ->map('0:a:1')
            ->map('0:a:0')
            ->audioStream(0, function (AudioStreamSettings $audio) use (&$retained) {
                $retained = $audio;
                $audio->codec('flac')
                    ->sampleRate(32000)
                    ->sampleFormat('s16')
                    ->channelLayout('stereo')
                    ->channels(2)
                    ->compressionLevel(5)
                    ->filter('volume=0');
            })
            ->audioStream(1, fn (AudioStreamSettings $audio) => $audio->bitrate('96k'))
            ->videoStream(0, fn (VideoStreamSettings $video) => $video->codec('libx264')
                ->options(['-x264-params', 'keyint=10:scenecut=0'])->color('bt709', 'bt709', 'bt709'));
        $retained->sampleRate(8000);
        $arguments = $this->arguments($output);

        $this->assertSame('32000', $arguments[array_search('-ar:a:0', $arguments, true) + 1]);
        $this->assertSame('volume=0.5,volume=0', $arguments[array_search('-filter:a:0', $arguments, true) + 1]);
        $this->assertSame('96k', $arguments[array_search('-b:a:1', $arguments, true) + 1]);
        $this->assertSame($arguments, $this->arguments(unserialize(serialize($output))));
        $this->assertSame(
            $arguments,
            $this->arguments($this->destination()->configure($output->outputConfiguration())),
        );
        $this->assertSame(['0:a:1', '0:a:0'], $output->maps());

        $inherited = (new HlsVariant)->inherit($output->outputConfiguration());

        $this->assertSame($arguments, $this->arguments($this->destination()->configure($inherited)));
    }

    public function test_accepts_curated_options(): void
    {
        $tokens = ['-cpu-used:v:1', '6', '-deadline:v', 'realtime', '-row-mt:v', '1', '-rc:v', 'vbr',
            '-cq:v', '23', '-rc-lookahead:v', '20', '-spatial-aq:v', '1', '-temporal-aq:v', '1', '-aq-strength:v', '8',
            '-bsf:v', 'h264_mp4toannexb', '-avoid_negative_ts', 'make_zero', '-video_track_timescale', '90000',
            '-frag_duration', '1000000', '-min_frag_duration', '500000', '-flush_packets', '1',
            '-max_muxing_queue_size', '1024', '-cluster_time_limit', '1000', '-write_crc32', '1', '-muxdelay', '0.2'];

        $this->assertSame($tokens, CommandCompiler::options($tokens, 'output'));

        $flags = ['-movflags', '+faststart', '-disposition:a:0', 'default+forced', '-preset', 'ultra-fast',
            '-strict:a', 'experimental'];

        $this->assertSame($flags, CommandCompiler::options($flags, 'output'));
        $this->assertSame(
            ['-hwaccel_device', '/dev/dri/render-D128'],
            CommandCompiler::options(['-hwaccel_device', '/dev/dri/render-D128'], 'input'),
        );
    }

    #[DataProvider('unsafeOptionProvider')]
    public function test_rejects_unsafe_options(string $scope, array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommandCompiler::options($options, $scope);
    }

    public static function unsafeOptionProvider(): array
    {
        return [
            'x264 stats file' => ['output', ['-x264-params', 'stats=/tmp/stats']],
            'x265 csv file' => ['output', ['-x265-params', 'csv=/tmp/stats']],
            'x265 analysis file' => ['output', ['-x265-params', 'analysis-save=1']],
            'repeated x264 key' => ['output', ['-x264-params', 'keyint=10:keyint=20']],
            'x264 zones' => ['output', ['-x264-params', 'keyint=10:zones=0,10,q=20']],
            'tracing bitstream filter' => ['output', ['-bsf:v', 'trace_headers']],
            'bitstream filter file' => ['output', ['-bsf:v', 'h264_metadata=sei_user_data=/tmp/file']],
            'trailing overwrite flag' => ['output', ['-bsf:v', 'h264_mp4toannexb', '-y']],
            'video option on audio' => ['output', ['-cpu-used:a', '6']],
            'audio option on video' => ['output', ['-sample_fmt:v', 's16']],
            'strict on video' => ['output', ['-strict:v', 'experimental']],
            'strict below experimental' => ['output', ['-strict:a', 'unofficial']],
            'muxer option on a stream' => ['output', ['-frag_duration:v', '1000']],
            'unknown color transfer' => ['output', ['-color_trc', 'unknown']],
            'file value' => ['output', ['-sample_fmt', 'file:/tmp/file']],
            'segment list sidecar' => ['output', ['-segment_list', 'sidecar']],
            'progress target' => ['output', ['-progress', 'pipe:2']],
            'encoder option on input' => ['input', ['-x264-params', 'keyint=10']],
            'device option' => ['global', ['-init_hw_device', 'cuda=gpu:0,primary_ctx=1']],
            'device path' => ['global', ['-init_hw_device', 'vaapi=gpu:/tmp/file']],
            'duplicate device name' => ['global', ['-init_hw_device', 'cuda=gpu:0', '-init_hw_device', 'cuda=gpu:1']],
            'unknown parent device' => ['global', ['-init_hw_device', 'qsv=derived@missing']],
            'unknown filter device' => ['global', ['-filter_hw_device', 'missing']],
            // Option-shaped values would be found by later argv token searches.
            'input option as device' => ['input', ['-hwaccel_device', '-i']],
            'option as output format' => ['input', ['-hwaccel_output_format', '-t']],
            'option as preset' => ['output', ['-preset', '-ss']],
            'option as disposition' => ['output', ['-disposition:a:0', '-filter_complex']],
        ];
    }

    #[DataProvider('incompatibleTrackProvider')]
    public function test_rejects_settings_that_conflict_with_stream_copy(Closure $configure): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->arguments($configure($this->destination()));
    }

    public static function incompatibleTrackProvider(): array
    {
        return [
            'filter on a copied track' => [
                fn (ExportOutput $output) => $output->audioStream(
                    0,
                    fn ($audio) => $audio->codec('copy')->filter('volume=0'),
                ),
            ],
            'sample rate on copied audio' => [
                fn (ExportOutput $output) => $output->audio('copy')
                    ->audioStream(0, fn ($audio) => $audio->sampleRate(48000)),
            ],
            'sample format on copied audio' => [
                fn (ExportOutput $output) => $output->audio('copy')->outputOptions(['-sample_fmt:a:0', 's16']),
            ],
            'encoder options on copied video' => [
                fn (ExportOutput $output) => $output->video('copy')->outputOptions(['-x264-params:v', 'keyint=10']),
            ],
            'negative track' => [
                fn (ExportOutput $output) => $output->audioStream(-1, fn ($audio) => $audio->codec('aac')),
            ],
            'track configured twice' => [
                fn (ExportOutput $output) => $output->audioStream(0, fn ($audio) => null)
                    ->audioStream(0, fn ($audio) => null),
            ],
        ];
    }

    public function test_allows_bitstream_remux_of_copied_video(): void
    {
        $this->assertContains('h264_mp4toannexb', $this->arguments($this->destination()->video('copy')->audio('copy')
            ->outputOptions(['-bsf:v', 'h264_mp4toannexb'])));
    }

    public function test_named_hardware_is_global_and_precedes_inputs(): void
    {
        $global = [
            '-init_hw_device', 'vaapi=base:/dev/dri/renderD128',
            '-init_hw_device', 'qsv=derived@base',
            '-filter_hw_device', 'derived',
        ];

        $this->assertSame($global, CommandCompiler::options($global, 'global'));

        $command = CommandCompiler::compile(
            'ffmpeg',
            [['protocols' => 'file', 'options' => [], 'path' => 'input']],
            [],
            $global,
        );

        $this->assertLessThan(array_search('-i', $command, true), array_search('-init_hw_device', $command, true));
    }

    protected function realFixture(): void
    {
        $this->useRealFFMpeg();
        mkdir($this->root.'/files', 0700);
        config(['filesystems.disks.encoding-options' => ['driver' => 'local', 'root' => $this->root.'/files']]);
        $this->runTool(['-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=10:d=1', '-f', 'lavfi',
            '-i', 'sine=frequency=440:sample_rate=44100:duration=1', '-f', 'lavfi',
            '-i', 'sine=frequency=880:sample_rate=48000:duration=1', '-map', '0:v', '-map', '1:a', '-map', '2:a',
            '-c:v', 'libx264', '-threads', '1', '-c:a', 'pcm_s16le', '-ac:a:1', '2', $this->path('source.mkv')]);
    }

    protected function path(string $name): string
    {
        return Storage::disk('encoding-options')->path($name);
    }

    protected function media(string $name)
    {
        return FFMpeg::disk('encoding-options')->open($name);
    }

    protected function runTool(array $arguments): string
    {
        $result = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-y', ...$arguments]);
        $this->assertProcessSucceeded($result);

        return $result->output();
    }

    public function test_real_per_track_encoding_settings(): void
    {
        $this->realFixture();

        $this->media('source.mkv')
            ->export()
            ->mapVideo()
            ->mapAudio(track: 1)
            ->mapAudio(track: 0)
            ->video('libx264')
            ->videoOptions(['-x264-params', 'keyint=10:scenecut=0'])
            ->color('bt709', 'bt709', 'bt709')
            ->audioStream(0, fn ($audio) => $audio->codec('flac')->sampleRate(32000)->channels(2)->sampleFormat('s16')
                ->channelLayout('stereo')->compressionLevel(5)->filter('volume=0'))
            ->audioStream(1, fn ($audio) => $audio->codec('pcm_s16le')->sampleRate(22050)->channels(1))
            ->save('encoded.mkv');
        $streams = $this->media('encoded.mkv')
            ->probe()
            ->streams();

        $this->assertCount(3, $streams);
        $this->assertSame('bt709', $streams[0]->get('color_primaries'));
        $this->assertSame('bt709', $streams[0]->get('color_transfer'));
        $this->assertSame('bt709', $streams[0]->get('color_space'));
        $this->assertSame('tv', $streams[0]->get('color_range'));
        $this->assertSame('flac', $streams[1]->get('codec_name'));
        $this->assertSame('32000', $streams[1]->get('sample_rate'));
        $this->assertSame(2, $streams[1]->get('channels'));
        $this->assertSame('pcm_s16le', $streams[2]->get('codec_name'));
        $this->assertSame('22050', $streams[2]->get('sample_rate'));
        $this->assertSame(1, $streams[2]->get('channels'));

        $muted = $this->runTool(['-i', $this->path('encoded.mkv'), '-map', '0:a:0', '-f', 's16le', '-']);
        $audible = $this->runTool(['-i', $this->path('encoded.mkv'), '-map', '0:a:1', '-f', 's16le', '-']);

        $this->assertNotEmpty($muted);
        $this->assertSame('', trim($muted, "\0"));
        $this->assertNotSame('', trim($audible, "\0"));

        $original = $this->runTool(['-i', $this->path('source.mkv'), '-map', '0:a:0', '-ar', '22050', '-ac', '1',
            '-f', 's16le', '-']);
        $other = $this->runTool(['-i', $this->path('source.mkv'), '-map', '0:a:1', '-ar', '22050', '-ac', '1',
            '-f', 's16le', '-']);

        $this->assertSame(hash('sha256', $original), hash('sha256', $audible));
        $this->assertNotSame(hash('sha256', $other), hash('sha256', $audible));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_x265_private_settings(): void
    {
        $this->realFixture();

        $this->media('source.mkv')
            ->export()
            ->withoutAudio()
            ->video('libx265', crf: 30, preset: 'ultrafast')
            ->videoOptions(['-x265-params', 'keyint=10:scenecut=0:pools=1:frame-threads=1:log-level=0'])
            ->save('hevc.mp4');

        $this->assertSame('hevc', $this->media('hevc.mp4')->probe()->streams()[0]->get('codec_name'));
    }

    public function test_real_experimental_audio_encoder(): void
    {
        $this->realFixture();

        // FFmpeg's native Vorbis encoder only encodes stereo, and refuses to run unless
        // experimental encoders are allowed.
        $this->media('source.mkv')
            ->export()
            ->withoutVideo()
            ->audio('vorbis')
            ->audioOptions(['-strict', 'experimental', '-ac', '2'])
            ->save('vorbis.ogg');

        $this->assertSame('vorbis', $this->media('vorbis.ogg')->probe()->streams()[0]->get('codec_name'));
    }

    public function test_real_bitstream_remux_keeps_the_decoded_frames(): void
    {
        $this->realFixture();

        $this->media('source.mkv')
            ->export()
            ->withoutAudio()
            ->video('copy')
            ->outputOptions(['-bsf:v', 'h264_mp4toannexb', '-f', 'mpegts', '-muxdelay', '0', '-muxpreload', '0'])
            ->save('remux.ts');
        $hash = fn ($name) => hash('sha256', $this->runTool(['-i', $this->path($name), '-map', '0:v:0',
            '-f', 'rawvideo', '-pix_fmt', 'yuv420p', '-']));

        $this->assertSame($hash('source.mkv'), $hash('remux.ts'));
    }

    public function test_real_hardware_initialization_failure_is_reported_without_output(): void
    {
        $this->realFixture();

        $binary = config('laravel-ffmpeg.ffmpeg.binaries');
        $backends = Process::run([$binary, '-hide_banner', '-hwaccels']);
        $encoders = Process::run([$binary, '-hide_banner', '-encoders']);

        $this->assertTrue(
            $backends->successful() && $encoders->successful(),
            $backends->errorOutput().$encoders->errorOutput(),
        );

        if (preg_match('/^cuda$/m', $backends->output()) && preg_match('/\bh264_nvenc\b/', $encoders->output())) {
            $this->markTestSkipped(
                'This acceptance case requires a build without CUDA/NVENC support, '
                .'not an assumption about GPU availability.'
            );
        }

        $export = $this->media('source.mkv')
            ->export()
            ->globalOptions(['-init_hw_device', 'cuda=gpu:0', '-filter_hw_device', 'gpu'])
            ->video('h264_nvenc');

        $this->assertThrows(
            fn () => $export->save('hardware.mp4'),
            fn (MediaException $exception) => $exception->getMessage() !== '',
        );
        $this->assertFalse(Storage::disk('encoding-options')->exists('hardware.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }
}
