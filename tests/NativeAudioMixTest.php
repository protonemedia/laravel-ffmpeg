<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeAudioMixTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-audio-mix-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/files', 0700);
        mkdir($this->root.'/workspaces', 0700);
        config([
            'laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces',
            'filesystems.disks.audio-mix' => ['driver' => 'local', 'root' => $this->root.'/files'],
        ]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): void
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for native audio-mix acceptance.');
        }
        config(['laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg', 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
    }

    private function ffmpeg(array $arguments): string
    {
        $process = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-y', ...$arguments]);
        $this->assertTrue($process->successful(), $process->errorOutput());

        return $process->output();
    }

    private function path(string $name): string
    {
        return Storage::disk('audio-mix')->path($name);
    }

    private function media(string $name = 'source.mkv')
    {
        return FFMpeg::disk('audio-mix')->open($name);
    }

    private function fixture(bool $audio = true): void
    {
        $this->native();
        $arguments = ['-f', 'lavfi', '-i', 'color=red:s=48x32:r=10:d=3'];
        if ($audio) {
            array_push($arguments, '-itsoffset', '0.2', '-f', 'lavfi', '-i', 'aevalsrc=0.1|-0.2:s=32000:d=1.8');
        }
        $this->ffmpeg([...$arguments, '-c:v', 'ffv1', '-c:a', 'pcm_f64le', $this->path('source.mkv')]);
        $this->ffmpeg(['-f', 'lavfi', '-i', 'aevalsrc=if(lt(t\,0.25)\,0.2\,-0.4)|if(lt(t\,0.25)\,0.4\,0.2):s=48000:d=0.5',
            '-c:a', 'pcm_f64le', $this->path('music.wav')]);
    }

    private function samples(string $name): array
    {
        return array_values(unpack('e*', $this->ffmpeg(['-i', $this->path($name), '-map', '0:a:0', '-c:a', 'pcm_f64le', '-f', 'f64le', 'pipe:1'])));
    }

    private function assertSample(array $samples, float $at, float $left, float $right): void
    {
        $offset = (int) round($at * 48000) * 2;
        $this->assertEqualsWithDelta($left, $samples[$offset], 0.0001);
        $this->assertEqualsWithDelta($right, $samples[$offset + 1], 0.0001);
    }

    #[Test]
    public function inspection_is_pure_and_conflicting_settings_fail_before_io(): void
    {
        Process::fake();
        $source = $this->media();
        $music = $this->media('music.wav');
        $operation = $source->backgroundMusic($music);
        $this->assertSame($operation->inspect(), $operation->inspect());
        $this->assertContains('-stream_loop', $operation->inspect());
        $this->assertContains('[source-duration]', $operation->inspect());
        $this->assertSame('audio-mix', $operation->outputConfiguration()['disk']);
        $this->assertNotContains('-stream_loop', $source->mixAudio($music)->inspect());
        $this->assertNotContains('-stream_loop', $operation->loopAudio(false)->inspect());
        foreach ([
            fn () => $source->mixAudio($music, volume: NAN),
            fn () => $source->mixAudio($music, originalVolume: -1),
            fn () => $source->mixAudio($music, volume: INF),
            fn () => $source->mixAudio($music)->audio('copy')->inspect(),
            fn () => $source->mixAudio($music)->map('0:v')->inspect(),
            fn () => $source->mixAudio($music)->clip(0, duration: 1)->inspect(),
            fn () => $source->mixAudio($music)->withoutAudio()->inspect(),
            fn () => $source->mixAudio($music)->filterComplex('[0:a]anull[a]'),
            fn () => $source->mixAudio($music)->addInput($music),
            fn () => $source->mixAudio($music)->output('second.mp4', fn () => null),
            fn () => $source->mixAudio($music)->watermark($music),
            fn () => $source->mixAudio($music)->twoPass(),
            fn () => $source->backgroundMusic(FFMpeg::disk()->openUrl('https://media.example/music.mp3'))->inspect(),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Expected an unsupported audio-mix configuration to fail.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function looped_music_preserves_original_audio_delay_gain_video_and_primary_duration(): void
    {
        $this->fixture();
        $sourceHash = hash_file('sha256', $this->path('source.mkv'));
        $musicHash = hash_file('sha256', $this->path('music.wav'));
        Storage::disk('memory')->put('music.wav', file_get_contents($this->path('music.wav')));
        $result = $this->media()->backgroundMusic(FFMpeg::disk('memory')->open('music.wav'), volume: 0.25)
            ->video('copy')->audio('pcm_f64le')->save('mixed.mkv');
        $this->assertSame('audio-mix', $result->file()->disk);
        $this->assertCount(2, $result->processes());
        $this->assertSame(3.0, $this->media('mixed.mkv')->probe()->duration());
        $this->assertSame(2, $this->media('mixed.mkv')->probe()->audio()->channels());
        $this->assertSame(48000, $this->media('mixed.mkv')->probe()->audio()->sampleRate());
        $samples = $this->samples('mixed.mkv');
        $this->assertCount(3 * 48000 * 2, $samples);
        $this->assertSample($samples, 0.1, 0.05, 0.1);
        $this->assertSample($samples, 0.3, 0.0, -0.15);
        $this->assertSample($samples, 0.6, 0.15, -0.1);
        $this->assertSample($samples, 1 - 1 / 48000, 0, -0.15);
        $this->assertSample($samples, 1 + 1 / 48000, 0.15, -0.1);
        $this->assertSample($samples, 1.3, 0.0, -0.15);
        $this->assertSample($samples, 2.1, 0.05, 0.1);
        $this->assertSample($samples, 2.8, -0.1, 0.05);
        $original = $this->ffmpeg(['-i', $this->path('source.mkv'), '-map', '0:v', '-f', 'framemd5', '-']);
        $encoded = $this->ffmpeg(['-i', $this->path('mixed.mkv'), '-map', '0:v', '-f', 'framemd5', '-']);
        $this->assertSame($original, $encoded);
        $this->assertSame($sourceHash, hash_file('sha256', $this->path('source.mkv')));
        $this->assertSame($musicHash, hash_file('sha256', $this->path('music.wav')));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function one_shot_music_pads_short_tracks_and_trims_long_tracks_without_gain_pumping(): void
    {
        $this->fixture();
        $source = $this->media();
        $music = $this->media('music.wav');
        $result = $source->mixAudio($music, volume: 0.25, originalVolume: 0.5, normalize: true)
            ->video('copy')->audio('pcm_f64le')->save('normalized.mkv');
        $this->assertCount(1, $result->processes());
        $samples = $this->samples('normalized.mkv');
        $this->assertSample($samples, 0.1, 0.025, 0.05);
        $this->assertSample($samples, 0.3, -0.025, -0.025);
        $this->assertSample($samples, 0.8, 0.025, -0.05);
        $this->assertSample($samples, 1.8, 0.025, -0.05);
        $this->assertSample($samples, 2.5, 0, 0);
        $this->assertCount(3 * 48000 * 2, $samples);

        $this->ffmpeg(['-f', 'lavfi', '-i', 'aevalsrc=0.3|-0.1:s=48000:d=5', '-c:a', 'pcm_f64le', $this->path('long.wav')]);
        $source->mixAudio($this->media('long.wav'), volume: 0.5, originalVolume: 0)
            ->withoutVideo()->audio('pcm_f64le')->volume(0.5)->save('trimmed.wav');
        $samples = $this->samples('trimmed.wav');
        $this->assertCount(3 * 48000 * 2, $samples);
        $this->assertSample($samples, 0.1, 0.075, -0.025);
        $this->assertSample($samples, 2.9, 0.075, -0.025);
    }

    #[Test]
    public function silent_video_and_audio_only_sources_receive_music_without_inventing_original_audio(): void
    {
        $this->fixture(audio: false);
        $music = $this->media('music.wav');
        $this->media()->backgroundMusic($music)->video('copy')->audio('pcm_f64le')->save('silent-with-music.mkv');
        $samples = $this->samples('silent-with-music.mkv');
        $this->assertCount(3 * 48000 * 2, $samples);
        $this->assertSample($samples, 0.1, 0.04, 0.08);
        $this->assertSample($samples, 2.8, -0.08, 0.04);
        $this->assertSame('ffv1', $this->media('silent-with-music.mkv')->probe()->video()->codec());

        $this->ffmpeg(['-f', 'lavfi', '-i', 'aevalsrc=0.1|-0.2:s=48000:d=1.5', '-c:a', 'pcm_f64le', $this->path('speech.wav')]);
        $this->media('speech.wav')->mixAudio($music, volume: 0.25)->loopAudio()->audio('pcm_f64le')->save('audio-only.wav');
        $samples = $this->samples('audio-only.wav');
        $this->assertCount(1.5 * 48000 * 2, $samples);
        $this->assertSample($samples, 0.1, 0.15, -0.1);
        $this->assertSample($samples, 1.3, 0, -0.15);
        $this->assertFalse($this->media('audio-only.wav')->probe()->hasVideo());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function encoded_mono_music_loops_throughout_the_presentation_and_supports_aac_output(): void
    {
        $this->fixture(audio: false);
        $this->ffmpeg(['-f', 'lavfi', '-i', 'sine=frequency=997:sample_rate=44100:duration=0.2',
            '-c:a', 'aac', $this->path('music.m4a')]);
        $this->media()->backgroundMusic($this->media('music.m4a'))->video('libx264')->audio('aac')
            ->save('encoded.mp4');
        $metadata = $this->media('encoded.mp4')->probe();
        $this->assertEqualsWithDelta(3, $metadata->duration(), 0.03);
        $this->assertSame('h264', $metadata->video()->codec());
        $this->assertSame('aac', $metadata->audio()->codec());
        $this->assertSame(2, $metadata->audio()->channels());
        $samples = $this->samples('encoded.mp4');
        foreach ([0.1, 0.2, 0.4, 0.6, 1.1, 2.1, 2.8] as $at) {
            $offset = (int) round($at * 48000) * 2;
            $window = array_slice($samples, $offset, 960);
            $rms = sqrt(array_sum(array_map(fn ($value) => $value * $value, $window)) / count($window));
            $this->assertGreaterThan(0.005, $rms, 'Music must continue through repeated encoded input boundaries.');
            $this->assertLessThan(0.03, $rms);
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function invalid_audio_failed_filters_and_cancellation_preserve_inputs_and_existing_destinations(): void
    {
        $this->fixture();
        $source = $this->media();
        $music = $this->media('music.wav');
        $hash = hash_file('sha256', $this->path('source.mkv'));
        Storage::disk('audio-mix')->put('retained.mkv', 'existing destination');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=blue:s=48x32:r=10:d=1', '-c:v', 'ffv1', $this->path('no-audio.mkv')]);
        $this->ffmpeg(['-i', $this->path('source.mkv'), '-c', 'copy', '-output_ts_offset', '1', $this->path('shifted.mkv')]);
        foreach ([
            $source->backgroundMusic($this->media('no-audio.mkv')),
            $this->media('shifted.mkv')->backgroundMusic($music),
            $source->backgroundMusic($music)->audioFilter('not_a_real_filter'),
        ] as $invalid) {
            try {
                $invalid->video('copy')->audio('pcm_f64le')->save('retained.mkv');
                $this->fail('Expected invalid audio or filter to fail.');
            } catch (MediaException) {
                $this->assertSame('existing destination', Storage::disk('audio-mix')->get('retained.mkv'));
            }
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
        foreach (['analysis', 'encoding'] as $phase) {
            try {
                $source->backgroundMusic($music)->video('copy')->audio('pcm_f64le')
                    ->onProgress(function ($progress) use ($phase) {
                        if ($progress->phase === $phase) {
                            throw new ExportCancelled('Stop mixing.');
                        }
                    })->save('retained.mkv');
                $this->fail('Expected cancellation.');
            } catch (ExportCancelled) {
                $this->assertSame('existing destination', Storage::disk('audio-mix')->get('retained.mkv'));
            }
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
        $this->assertSame($hash, hash_file('sha256', $this->path('source.mkv')));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }
}
