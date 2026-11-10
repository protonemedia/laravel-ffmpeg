<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class PreservationTest extends TestCase
{
    private string $root;

    private string $ffmpeg;

    protected function setUp(): void
    {
        parent::setUp();
        $probe = getenv('FFMPEG_9_FFPROBE');
        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for preservation acceptance.');
        }
        $this->ffmpeg = dirname($probe).'/ffmpeg';
        $this->root = sys_get_temp_dir().'/native-preservation-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        config(['filesystems.default' => 'memory', 'laravel-ffmpeg.ffmpeg.binaries' => $this->ffmpeg,
            'laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function ffmpeg(array $arguments): string
    {
        $result = Process::run([$this->ffmpeg, '-v', 'error', '-y', ...$arguments]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return $result->output();
    }

    public function test_crop_rotate_and_pad_preserve_order_pixels_and_output_geometry(): void
    {
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=red:size=64x32:duration=0.2,drawbox=x=0:y=0:w=16:h=32:color=blue:t=fill',
            '-c:v', 'ffv1', $this->root.'/colors.mkv']);
        $media = FFMpeg::disk()->open(new File($this->root.'/colors.mkv'));
        $result = $media->frame()->crop(32, 24, x: 0, y: 0)->rotate(90)->pad(40, 48, x: 8, y: 4)->save('geometry.png');
        file_put_contents($this->root.'/geometry.png', Storage::disk('memory')->get($result->file()->path));
        $rgb = $this->ffmpeg(['-i', $this->root.'/geometry.png', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
        $this->assertSame(40 * 48 * 3, strlen($rgb));
        foreach ([[0, 0, 0], [10, 8, 2], [10, 28, 0]] as [$x, $y, $channel]) {
            $pixel = array_values(unpack('C3', substr($rgb, ($y * 40 + $x) * 3, 3)));
            if ($x === 0) {
                $this->assertSame([0, 0, 0], $pixel);
            } else {
                $this->assertGreaterThan(240, $pixel[$channel]);
                $this->assertLessThan(15, $pixel[1]);
                $this->assertLessThan(15, $pixel[2 - $channel]);
            }
        }
        foreach ([fn () => $media->export()->rotate(45), fn () => $media->export()->crop(31, 24),
            fn () => $media->export()->pad(40, 48, background: 'red;movie=/secret'),
            fn () => $media->export()->crop(32, 24)->video('copy')->inspect()] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid geometry was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_scoped_read_only_inputs_and_outputs_keep_their_prefix_and_never_write_the_source(): void
    {
        $this->ffmpeg(['-f', 'lavfi', '-i', 'sine=frequency=611:duration=0.3', '-c:a', 'pcm_s16le', $this->root.'/source.wav']);
        $base = new LocalFilesystemAdapter($this->root.'/storage');
        $scoped = new PathPrefixedAdapter($base, 'tenant-42');
        $writable = new FilesystemAdapter(new Filesystem($scoped), $scoped);
        $writable->put('input.wav', file_get_contents($this->root.'/source.wav'));
        $readOnly = new ReadOnlyFilesystemAdapter($scoped);
        $source = new FilesystemAdapter(new Filesystem($readOnly), $readOnly);
        $media = FFMpeg::disk($source)->open('input.wav');
        $result = $media->export()->audio('flac')->toDisk($writable)->save('exports/audio.flac');
        $this->assertSame('exports/audio.flac', $result->file()->path);
        $this->assertFileExists($this->root.'/storage/tenant-42/exports/audio.flac');
        $this->assertFileDoesNotExist($this->root.'/storage/exports/audio.flac');
        $this->assertSame('flac', FFMpeg::disk($source)->open('exports/audio.flac')->probe()->audio()->codec());
        try {
            $media->export()->audio('flac')->toDisk($source)->save('blocked.flac');
            $this->fail('A read-only adapter accepted publication.');
        } catch (MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertFalse($writable->exists('blocked.flac'));
        }
        try {
            $media->export()->toDisk($writable)->save('../outside.flac');
            $this->fail('A destination escaped the disk root.');
        } catch (PathTraversalDetected) {
            $this->assertFileDoesNotExist($this->root.'/storage/outside.flac');
        }
        $this->assertSame(file_get_contents($this->root.'/source.wav'), $source->get('input.wav'));
    }

    public function test_subtitle_tracks_artwork_and_metadata_survive_explicit_stream_copy(): void
    {
        file_put_contents($this->root.'/captions.srt', "1\n00:00:00,100 --> 00:00:00,800\nBonjour\n");
        file_put_contents($this->root.'/metadata.txt', ";FFMETADATA1\ntitle=Preserved\ncomment=Remove me\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=0\nEND=900\ntitle=Introduction\n");
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=blue:size=16x16', '-frames:v', '1', $this->root.'/cover.png']);
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=size=64x48:rate=10:duration=1',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-i', $this->root.'/captions.srt',
            '-i', $this->root.'/cover.png', '-f', 'ffmetadata', '-i', $this->root.'/metadata.txt',
            '-map', '0:v', '-map', '1:a', '-map', '2:s', '-map', '3:v', '-map_metadata', '4', '-map_chapters', '4',
            '-c:v:0', 'libx264', '-preset', 'ultrafast', '-c:v:1', 'copy', '-disposition:v:1', 'attached_pic',
            '-c:a', 'aac', '-c:s', 'mov_text', '-metadata:s:s:0', 'language=fra', '-disposition:s:0', 'forced',
            $this->root.'/source.mp4']);
        $media = FFMpeg::disk()->open(new File($this->root.'/source.mp4'));
        $media->export()->map('0:V:0')->map('0:a')->map('0:s')->map('0:v:1')->video('copy')->audio('copy')
            ->outputOptions(['-c:s', 'copy'])->save('preserved.mp4');
        $probe = FFMpeg::disk()->open('preserved.mp4')->probe();
        $this->assertSame('Preserved', $probe->tag('title'));
        $this->assertCount(1, $probe->chapters());
        $subtitles = $probe->streams()->filter(fn ($stream) => $stream->type() === 'subtitle')->values();
        $this->assertCount(1, $subtitles);
        $this->assertSame('mov_text', $subtitles[0]->codec());
        $this->assertSame('fra', $subtitles[0]->language());
        $this->assertTrue($subtitles[0]->isForced());
        $this->assertCount(1, $probe->streams()->filter(fn ($stream) => $stream->isCoverArt()));
        $this->assertSame(64, $probe->video()->width());
        $media->export()->mapVideo()->mapAudio()->video('copy')->audio('copy')->withoutMetadata(chapters: true)
            ->metadata('title', 'Replacement')->save('removed.mp4');
        $removed = FFMpeg::disk()->open('removed.mp4')->probe();
        $this->assertSame('Replacement', $removed->tag('title'));
        $this->assertNull($removed->tag('comment'));
        $this->assertCount(0, $removed->chapters());
    }

    public function test_accurate_clips_preserve_vfr_frame_times_and_audio_offsets(): void
    {
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=size=64x48:rate=20:duration=2',
            '-itsoffset', '0.35', '-f', 'lavfi', '-i', 'sine=frequency=733:sample_rate=48000:duration=1.6',
            '-vf', 'select=if(lt(t\,1)\,not(mod(n\,2))\,1)', '-fps_mode', 'vfr',
            '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $this->root.'/vfr.mkv']);
        $media = FFMpeg::disk()->open(new File($this->root.'/vfr.mkv'));
        $media->export()->video('ffv1')->audio('pcm_s16le')->clip(0.1, duration: 1.6)->save('clip.mkv');
        file_put_contents($this->root.'/clip.mkv', Storage::disk('memory')->get('clip.mkv'));
        $frames = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'frame=best_effort_timestamp_time', '-of', 'json', $this->root.'/clip.mkv']);
        $this->assertTrue($frames->successful(), $frames->errorOutput());
        $times = array_map(fn ($frame) => (float) $frame['best_effort_timestamp_time'], json_decode($frames->output(), true)['frames']);
        $gaps = array_map(fn ($a, $b) => round($b - $a, 3), array_slice($times, 0, -1), array_slice($times, 1));
        $this->assertContains(0.1, $gaps);
        $this->assertContains(0.05, $gaps);
        $audio = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'a:0',
            '-show_packets', '-show_entries', 'packet=pts_time', '-of', 'json', $this->root.'/clip.mkv']);
        $this->assertTrue($audio->successful(), $audio->errorOutput());
        $this->assertEqualsWithDelta(0.25, (float) json_decode($audio->output(), true)['packets'][0]['pts_time'], 0.025);
        $this->assertLessThanOrEqual(1.6, end($times));
    }
}
