<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

#[RequiresEnv('FFMPEG_9_FFPROBE')]
class PreservationTest extends TestCase
{
    protected string $ffmpeg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ffmpeg = $this->useRealFFMpeg();
        config(['filesystems.default' => 'memory']);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->assertWorkspacesAreCleanedUp();
        }
        parent::tearDown();
    }

    protected function ffmpeg(array $arguments): string
    {
        $result = Process::run([$this->ffmpeg, '-v', 'error', '-y', ...$arguments]);
        $this->assertProcessSucceeded($result);

        return $result->output();
    }

    public function test_geometry_helpers_apply_in_call_order(): void
    {
        $this->ffmpeg(['-f', 'lavfi',
            '-i', 'color=red:size=64x32:duration=0.2,drawbox=x=0:y=0:w=16:h=32:color=blue:t=fill',
            '-c:v', 'ffv1', $this->root.'/colors.mkv']);
        $media = FFMpeg::disk()->open(new File($this->root.'/colors.mkv'));
        $result = $media->frame()
            ->crop(32, 24, x: 0, y: 0)
            ->rotate(90)
            ->pad(40, 48, x: 8, y: 4)
            ->save('geometry.png');

        file_put_contents($this->root.'/geometry.png', Storage::disk('memory')->get($result->file()->path));

        $rgb = $this->ffmpeg(['-i', $this->root.'/geometry.png', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);

        [$width, $height] = [40, 48];

        $this->assertSame($width * $height * 3, strlen($rgb));
        foreach ([[0, 0, 0], [10, 8, 2], [10, 28, 0]] as [$x, $y, $channel]) {
            $pixel = array_values(unpack('C3', substr($rgb, ($y * $width + $x) * 3, 3)));
            if ($x === 0) {
                $this->assertSame([0, 0, 0], $pixel);
            } else {
                $this->assertGreaterThan(240, $pixel[$channel]);
                $this->assertLessThan(15, $pixel[1]);
                $this->assertLessThan(15, $pixel[2 - $channel]);
            }
        }
    }

    #[DataProvider('invalidGeometryProvider')]
    public function test_invalid_geometry_is_rejected(Closure $configure): void
    {
        $export = FFMpeg::disk()
            ->open(new File(__DIR__.'/src/video.mp4'))
            ->export();

        $this->expectException(InvalidArgumentException::class);

        $configure($export);
    }

    public static function invalidGeometryProvider(): array
    {
        return [
            'unsupported rotation' => [fn (PendingExport $export) => $export->rotate(45)],
            'odd crop width' => [fn (PendingExport $export) => $export->crop(31, 24)],
            'filter in pad color' => [
                fn (PendingExport $export) => $export->pad(40, 48, background: 'red;movie=/secret'),
            ],
            'crop of copied video' => [fn (PendingExport $export) => $export->crop(32, 24)->video('copy')->inspect()],
        ];
    }

    public function test_scoped_disks_keep_their_prefix(): void
    {
        [$source, $writable] = $this->scopedDisks();
        $result = FFMpeg::disk($source)
            ->open('input.wav')
            ->export()
            ->audio('flac')
            ->toDisk($writable)
            ->save('exports/audio.flac');

        $this->assertSame('exports/audio.flac', $result->file()->path);
        $this->assertFileExists($this->root.'/storage/tenant-42/exports/audio.flac');
        $this->assertFileDoesNotExist($this->root.'/storage/exports/audio.flac');
        $this->assertSame('flac', FFMpeg::disk($source)->open('exports/audio.flac')->probe()->audio()->codec());
        $this->assertSame(file_get_contents($this->root.'/source.wav'), $source->get('input.wav'));
    }

    public function test_read_only_disks_reject_publication(): void
    {
        [$source, $writable] = $this->scopedDisks();

        $export = FFMpeg::disk($source)
            ->open('input.wav')
            ->export()
            ->audio('flac')
            ->toDisk($source);

        $this->assertThrows(
            fn () => $export->save('blocked.flac'),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['publication', 'transfer'],
        );
        $this->assertFalse($writable->exists('blocked.flac'));
        $this->assertSame(file_get_contents($this->root.'/source.wav'), $source->get('input.wav'));
    }

    public function test_destinations_cannot_escape_the_disk_root(): void
    {
        [$source, $writable] = $this->scopedDisks();

        $this->assertThrows(
            fn () => FFMpeg::disk($source)->open('input.wav')->export()->toDisk($writable)->save('../outside.flac'),
            PathTraversalDetected::class,
        );
        $this->assertFileDoesNotExist($this->root.'/storage/outside.flac');
        $this->assertSame(file_get_contents($this->root.'/source.wav'), $source->get('input.wav'));
    }

    /**
     * Get a read-only and a writable disk that are both scoped to the same tenant prefix.
     *
     * @return array{FilesystemAdapter, FilesystemAdapter}
     */
    protected function scopedDisks(): array
    {
        $this->ffmpeg(['-f', 'lavfi', '-i', 'sine=frequency=611:duration=0.3',
            '-c:a', 'pcm_s16le', $this->root.'/source.wav']);
        $base = new LocalFilesystemAdapter($this->root.'/storage');
        $scoped = new PathPrefixedAdapter($base, 'tenant-42');
        $writable = new FilesystemAdapter(new Filesystem($scoped), $scoped);
        $writable->put('input.wav', file_get_contents($this->root.'/source.wav'));
        $readOnly = new ReadOnlyFilesystemAdapter($scoped);

        return [new FilesystemAdapter(new Filesystem($readOnly), $readOnly), $writable];
    }

    public function test_stream_copy_keeps_subtitles_cover_art_and_metadata(): void
    {
        $media = $this->richMedia();

        $media->export()
            ->map('0:V:0')
            ->map('0:a')
            ->map('0:s')
            ->map('0:v:1')
            ->video('copy')
            ->audio('copy')
            ->outputOptions(['-c:s', 'copy'])
            ->save('preserved.mp4');
        $probe = FFMpeg::disk()
            ->open('preserved.mp4')
            ->probe();

        $this->assertSame('Preserved', $probe->tag('title'));
        $this->assertCount(1, $probe->chapters());

        $subtitles = $probe->streams()
            ->filter(fn ($stream) => $stream->type() === 'subtitle')
            ->values();

        $this->assertCount(1, $subtitles);
        $this->assertSame('mov_text', $subtitles[0]->codec());
        $this->assertSame('fra', $subtitles[0]->language());
        $this->assertTrue($subtitles[0]->isForced());
        $this->assertCount(1, $probe->streams()->filter(fn ($stream) => $stream->isCoverArt()));
        $this->assertSame(64, $probe->video()->width());
    }

    public function test_metadata_can_be_removed_and_replaced(): void
    {
        $this->richMedia()
            ->export()
            ->mapVideo()
            ->mapAudio()
            ->video('copy')
            ->audio('copy')
            ->withoutMetadata(chapters: true)
            ->metadata('title', 'Replacement')
            ->save('removed.mp4');

        $removed = FFMpeg::disk()
            ->open('removed.mp4')
            ->probe();

        $this->assertSame('Replacement', $removed->tag('title'));
        $this->assertNull($removed->tag('comment'));
        $this->assertCount(0, $removed->chapters());
    }

    /**
     * Write a video with subtitles, cover art, metadata and a chapter, and open it.
     */
    protected function richMedia(): Media
    {
        file_put_contents($this->root.'/captions.srt', <<<'SRT'
            1
            00:00:00,100 --> 00:00:00,800
            Bonjour

            SRT);
        file_put_contents($this->root.'/metadata.txt', <<<'TXT'
            ;FFMETADATA1
            title=Preserved
            comment=Remove me
            [CHAPTER]
            TIMEBASE=1/1000
            START=0
            END=900
            title=Introduction

            TXT);
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=blue:size=16x16', '-frames:v', '1', $this->root.'/cover.png']);
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=size=64x48:rate=10:duration=1',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-i', $this->root.'/captions.srt',
            '-i', $this->root.'/cover.png', '-f', 'ffmetadata', '-i', $this->root.'/metadata.txt',
            '-map', '0:v', '-map', '1:a', '-map', '2:s', '-map', '3:v', '-map_metadata', '4', '-map_chapters', '4',
            '-c:v:0', 'libx264', '-preset', 'ultrafast', '-c:v:1', 'copy', '-disposition:v:1', 'attached_pic',
            '-c:a', 'aac', '-c:s', 'mov_text', '-metadata:s:s:0', 'language=fra', '-disposition:s:0', 'forced',
            $this->root.'/source.mp4']);

        return FFMpeg::disk()->open(new File($this->root.'/source.mp4'));
    }

    public function test_accurate_clips_keep_variable_frame_timing(): void
    {
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=size=64x48:rate=20:duration=2',
            '-itsoffset', '0.35', '-f', 'lavfi', '-i', 'sine=frequency=733:sample_rate=48000:duration=1.6',
            '-vf', 'select=if(lt(t\,1)\,not(mod(n\,2))\,1)', '-fps_mode', 'vfr',
            '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $this->root.'/vfr.mkv']);
        $media = FFMpeg::disk()->open(new File($this->root.'/vfr.mkv'));
        $media->export()
            ->video('ffv1')
            ->audio('pcm_s16le')
            ->clip(0.1, duration: 1.6)
            ->save('clip.mkv');

        file_put_contents($this->root.'/clip.mkv', Storage::disk('memory')->get('clip.mkv'));

        $frames = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'frame=best_effort_timestamp_time', '-of', 'json', $this->root.'/clip.mkv']);

        $this->assertProcessSucceeded($frames);

        $times = array_map(
            fn ($frame) => (float) $frame['best_effort_timestamp_time'],
            json_decode($frames->output(), true)['frames'],
        );
        $gaps = array_map(fn ($a, $b) => round($b - $a, 3), array_slice($times, 0, -1), array_slice($times, 1));

        $this->assertContains(0.1, $gaps);
        $this->assertContains(0.05, $gaps);

        $audio = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'a:0',
            '-show_packets', '-show_entries', 'packet=pts_time', '-of', 'json', $this->root.'/clip.mkv']);

        $this->assertProcessSucceeded($audio);
        $this->assertEqualsWithDelta(
            0.25,
            (float) json_decode($audio->output(), true)['packets'][0]['pts_time'],
            0.025,
        );
        $this->assertLessThanOrEqual(1.6, end($times));
    }
}
