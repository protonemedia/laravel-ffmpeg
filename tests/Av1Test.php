<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class Av1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.local.root' => $this->root.'/disk']);
    }

    /**
     * Open a short video with audio, skipping the test when FFmpeg was built without AV1.
     */
    protected function source(): Media
    {
        $ffmpeg = $this->useRealFFMpeg();

        // The CI build always includes SVT-AV1 and libaom, but a local FFmpeg 9 build may
        // not, so we skip instead of failing when either encoder is missing. SVT-AV1 1.x
        // refuses frames smaller than 64x64, so the source is large enough to downscale.
        $encoders = Process::run([$ffmpeg, '-hide_banner', '-encoders'])->output();

        if (! str_contains($encoders, 'libsvtav1') || ! str_contains($encoders, 'libaom-av1')) {
            $this->markTestSkipped('This FFmpeg build has no SVT-AV1 or libaom encoder.');
        }

        $source = $this->fixturePath('source.mkv');

        if (! is_file($source)) {
            $process = Process::run([
                $ffmpeg, '-v', 'error',
                '-f', 'lavfi', '-i', 'testsrc2=size=320x192:rate=20:duration=1.5',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1.5',
                '-c:v', 'libx264', '-threads', '1', '-c:a', 'aac',
                $source,
            ]);

            $this->assertProcessSucceeded($process);
        }

        Storage::disk('local')->put('source.mkv', file_get_contents($source));

        return FFMpeg::disk('local')->open('source.mkv');
    }

    /**
     * Get the codec name and tag of the first video stream in the given file.
     *
     * @return array{string, string}
     */
    protected function videoCodec(string $path): array
    {
        $process = Process::run([
            config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=codec_name,codec_tag_string',
            '-of', 'csv=p=0',
            $path,
        ]);

        $this->assertProcessSucceeded($process);

        // Playlists list their streams once per program, so the first line is enough.
        return explode(',', strtok(trim($process->output()), "\n"));
    }

    /**
     * Assert that FFmpeg decodes the given file or playlist without a single error.
     */
    protected function assertDecodes(string $path): void
    {
        $this->assertProcessSucceeded(Process::run([
            config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror',
            '-i', $path,
            '-f', 'null', '-',
        ]));
    }

    public function test_svt_av1_exports_decode_as_av1_in_mp4(): void
    {
        $media = $this->source();

        $media->export()
            ->video('libsvtav1', crf: 40, preset: '12')
            ->audio('aac')
            ->save('svt.mp4');

        $path = Storage::disk('local')->path('svt.mp4');

        $this->assertSame(['av1', 'av01'], $this->videoCodec($path));
        $this->assertSame('av1', FFMpeg::disk('local')->open('svt.mp4')->probe()->video()->codec());
        $this->assertDecodes($path);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_libaom_accepts_the_curated_speed_options(): void
    {
        $media = $this->source();

        $command = $media->export()
            ->video('libaom-av1', crf: 45)
            ->videoOptions(['-cpu-used', '8', '-row-mt', '1'])
            ->toDisk('local')
            ->inspect();

        $this->assertSame('8', $command[array_search('-cpu-used:v', $command) + 1]);
        $this->assertSame('1', $command[array_search('-row-mt:v', $command) + 1]);

        $media->export()
            ->video('libaom-av1', crf: 45)
            ->videoOptions(['-cpu-used', '8', '-row-mt', '1'])
            ->audio('aac')
            ->save('aom.mp4');

        $path = Storage::disk('local')->path('aom.mp4');

        $this->assertSame('av1', $this->videoCodec($path)[0]);
        $this->assertDecodes($path);
    }

    public function test_fragmented_hls_variants_advertise_their_av1_codec(): void
    {
        $media = $this->source();

        $result = $media->hls(segmentDuration: 0.5)
            ->fmp4()
            ->variant('small', fn (HlsVariant $variant) => $variant
                ->resize(width: 160)
                ->video('libsvtav1', bitrate: '100k', preset: '12'))
            ->variant('large', fn (HlsVariant $variant) => $variant
                ->video('libsvtav1', bitrate: '200k', preset: '12'))
            ->toDisk('local')
            ->save('hls/master.m3u8');

        $disk = Storage::disk('local');
        $master = $disk->get($result->playlist()->path);

        $this->assertSame(2, preg_match_all('/CODECS="av01\.[0-9]\.[0-9]{2}[MH]\.[0-9]{2}[^"]*"/', $master));

        foreach (['small', 'large'] as $name) {
            $playlist = $disk->path($result->variant($name)->playlist()->path);

            $this->assertStringContainsString('#EXT-X-MAP:URI=', file_get_contents($playlist));
            $this->assertSame(['av1', 'av01'], $this->videoCodec($playlist));
            $this->assertDecodes($playlist);
        }
    }
}
