<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class AudioMixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->root.'/files', 0700);
        config(['filesystems.disks.audio-mix' => ['driver' => 'local', 'root' => $this->root.'/files']]);
    }

    protected function ffmpeg(array $arguments): string
    {
        $process = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-y', ...$arguments]);
        $this->assertProcessSucceeded($process);

        return $process->output();
    }

    protected function path(string $name): string
    {
        return Storage::disk('audio-mix')->path($name);
    }

    protected function media(string $name = 'source.mkv')
    {
        return FFMpeg::disk('audio-mix')->open($name);
    }

    protected function fixture(bool $audio = true): void
    {
        $this->useRealFFMpeg();
        $arguments = ['-f', 'lavfi', '-i', 'color=red:s=48x32:r=10:d=3'];
        if ($audio) {
            array_push($arguments, '-itsoffset', '0.2', '-f', 'lavfi', '-i', 'aevalsrc=0.1|-0.2:s=32000:d=1.8');
        }
        $this->ffmpeg([...$arguments, '-c:v', 'ffv1', '-c:a', 'pcm_f64le', $this->path('source.mkv')]);
        $this->ffmpeg(['-f', 'lavfi', '-i', 'aevalsrc=if(lt(t\,0.25)\,0.2\,-0.4)|if(lt(t\,0.25)\,0.4\,0.2):s=48000:d=0.5',
            '-c:a', 'pcm_f64le', $this->path('music.wav')]);
    }

    protected function samples(string $name): array
    {
        return array_values(unpack('e*', $this->ffmpeg(['-i', $this->path($name), '-map', '0:a:0', '-c:a', 'pcm_f64le', '-f', 'f64le', 'pipe:1'])));
    }

    protected function assertSample(array $samples, float $at, float $left, float $right): void
    {
        $offset = (int) round($at * 48000) * 2;
        $this->assertEqualsWithDelta($left, $samples[$offset], 0.0001);
        $this->assertEqualsWithDelta($right, $samples[$offset + 1], 0.0001);
    }

    public function test_inspection_is_pure(): void
    {
        Process::fake();
        $source = $this->media();
        $music = $this->media('music.wav');
        $operation = $source->backgroundMusic($music);
        $this->assertSame($operation->inspect(), $operation->inspect());
        $this->assertContains('-stream_loop', $operation->inspect());
        $this->assertContains('[source-duration]', $operation->inspect());
        $this->assertNotContains('-stream_loop', $source->mixAudio($music)->inspect());
        $this->assertNotContains('-stream_loop', $operation->loop(false)->inspect());
        $delayed = $source->mixAudio($music);
        $this->assertSame($delayed, $delayed->at(0.625));
        $this->assertSame($delayed->inspect(), $delayed->inspect());
        $this->assertSame($source->mixAudio($music)->inspect(), $delayed->at(0)->inspect());
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidSettingsProvider(): array
    {
        return [
            'negative offset' => [fn (Media $source, Media $music) => $source->mixAudio($music)->at(-0.1)],
            'NaN offset' => [fn (Media $source, Media $music) => $source->mixAudio($music)->at(NAN)],
            'infinite offset' => [fn (Media $source, Media $music) => $source->mixAudio($music)->at(INF)],
            'negative infinite offset' => [fn (Media $source, Media $music) => $source->mixAudio($music)->at(-INF)],
            'NaN volume' => [fn (Media $source, Media $music) => $source->mixAudio($music, volume: NAN)],
            'negative original volume' => [fn (Media $source, Media $music) => $source->mixAudio($music, originalVolume: -1)],
            'infinite volume' => [fn (Media $source, Media $music) => $source->mixAudio($music, volume: INF)],
            'copied audio' => [fn (Media $source, Media $music) => $source->mixAudio($music)->audio('copy')->inspect()],
            'remote music' => [fn (Media $source) => $source->backgroundMusic(FFMpeg::disk()->openUrl('https://media.example/music.mp3'))->inspect()],
        ];
    }

    #[DataProvider('invalidSettingsProvider')]
    public function test_conflicting_settings_fail_before_io(Closure $invalid): void
    {
        Process::fake();

        $this->assertThrows(fn () => $invalid($this->media(), $this->media('music.wav')), InvalidArgumentException::class);

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_offsets_delay_only_added_samples_and_loop_without_repeating_the_delay(bool $loop): void
    {
        $this->fixture();
        $name = $loop ? 'delayed-loop.mkv' : 'delayed-once.mkv';
        $result = $this->media()->mixAudio($this->media('music.wav'), volume: 0.25, originalVolume: 0.5)
            ->at(0.625)->loop($loop)->video('copy')->audio('pcm_f64le')->save($name);
        $commands = $result->processes()->pluck('command')->all();
        $this->assertCount($loop ? 2 : 1, $commands);
        $this->assertSame($loop ? 'audio-mix-loop.wav' : '[output:0]', end($commands[0]));
        $samples = $this->samples($name);
        $this->assertCount(3 * 48000 * 2, $samples);
        $this->assertSame(3.0, $this->media($name)->probe()->duration());
        $this->assertSample($samples, 0.1, 0, 0);
        $this->assertSample($samples, 0.3, 0.05, -0.1);
        $this->assertSample($samples, 0.625 - 1 / 48000, 0.05, -0.1);
        $this->assertSample($samples, 0.625, 0.1, 0);
        $this->assertSample($samples, 0.9, -0.05, -0.05);
        $this->assertSample($samples, 1.125 - 1 / 48000, -0.05, -0.05);
        $this->assertSample($samples, 1.125, $loop ? 0.1 : 0.05, $loop ? 0 : -0.1);
        $this->assertSample($samples, 1.3, $loop ? 0.1 : 0.05, $loop ? 0 : -0.1);
        $this->assertSample($samples, 2.7, $loop ? 0.05 : 0, $loop ? 0.1 : 0);
        $this->assertSame(
            $this->ffmpeg(['-i', $this->path('source.mkv'), '-map', '0:v', '-f', 'framemd5', '-']),
            $this->ffmpeg(['-i', $this->path($name), '-map', '0:v', '-f', 'framemd5', '-']),
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function offsetProvider(): array
    {
        return [
            'inside the presentation' => [0.625],
            'at the end' => [3.0],
            'beyond the end' => [4.0],
            'largest float' => [PHP_FLOAT_MAX],
        ];
    }

    #[DataProvider('offsetProvider')]
    public function test_offsets_beyond_the_presentation_add_no_audio_and_silent_sources_keep_the_delay(float $offset): void
    {
        $this->fixture(audio: false);
        $name = 'silent-offset-'.$offset.'.wav';
        $this->media()->backgroundMusic($this->media('music.wav'), volume: 0.25)->at($offset)
            ->withoutVideo()->audio('pcm_f64le')->save($name);
        $samples = $this->samples($name);
        $this->assertCount(3 * 48000 * 2, $samples);
        $this->assertSample($samples, 0.1, 0, 0);
        $this->assertSample($samples, 0.625 - 1 / 48000, 0, 0);
        $this->assertSample($samples, 0.625, $offset < 3 ? 0.05 : 0, $offset < 3 ? 0.1 : 0);
        $this->assertSample($samples, 2.7, $offset < 3 ? 0.05 : 0, $offset < 3 ? 0.1 : 0);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_looped_music_preserves_original_audio_delay_gain_video_and_primary_duration(): void
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
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_one_shot_music_pads_short_tracks_and_trims_long_tracks_without_gain_pumping(): void
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

    public function test_silent_video_and_audio_only_sources_receive_music_without_inventing_original_audio(): void
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
        $this->media('speech.wav')->mixAudio($music, volume: 0.25)->loop()->audio('pcm_f64le')->save('audio-only.wav');
        $samples = $this->samples('audio-only.wav');
        $this->assertCount(1.5 * 48000 * 2, $samples);
        $this->assertSample($samples, 0.1, 0.15, -0.1);
        $this->assertSample($samples, 1.3, 0, -0.15);
        $this->assertFalse($this->media('audio-only.wav')->probe()->hasVideo());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_encoded_mono_music_loops_throughout_the_presentation_and_supports_aac_output(): void
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
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidMixProvider(): array
    {
        return [
            'music without audio' => ['source.mkv', 'no-audio.mkv', null, 'mapping'],
            'shifted source' => ['shifted.mkv', 'music.wav', null, 'metadata'],
            'unknown filter' => ['source.mkv', 'music.wav', 'not_a_real_filter', null],
        ];
    }

    #[DataProvider('invalidMixProvider')]
    public function test_invalid_audio_and_failed_filters_preserve_inputs_and_existing_destinations(
        string $source,
        string $music,
        ?string $filter,
        ?string $category,
    ): void {
        $this->fixture();
        $hash = hash_file('sha256', $this->path('source.mkv'));
        Storage::disk('audio-mix')->put('retained.mkv', 'existing destination');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=blue:s=48x32:r=10:d=1', '-c:v', 'ffv1', $this->path('no-audio.mkv')]);
        $this->ffmpeg(['-i', $this->path('source.mkv'), '-c', 'copy', '-output_ts_offset', '1', $this->path('shifted.mkv')]);
        $invalid = $this->media($source)->backgroundMusic($this->media($music));
        if ($filter !== null) {
            $invalid = $invalid->audioFilter($filter);
        }
        $this->assertThrows(fn () => $invalid->video('copy')->audio('pcm_f64le')->save('retained.mkv'), function (MediaException $exception) use ($category) {
            if ($category !== null) {
                $this->assertSame($category, $exception->category);
            }

            return true;
        });
        $this->assertSame('existing destination', Storage::disk('audio-mix')->get('retained.mkv'));
        $this->assertSame($hash, hash_file('sha256', $this->path('source.mkv')));
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['analysis'])]
    #[TestWith(['encoding'])]
    public function test_cancellation_preserves_inputs_and_existing_destinations(string $phase): void
    {
        $this->fixture();
        $hash = hash_file('sha256', $this->path('source.mkv'));
        Storage::disk('audio-mix')->put('retained.mkv', 'existing destination');
        $this->assertThrows(function () use ($phase) {
            $this->media()->backgroundMusic($this->media('music.wav'))->video('copy')->audio('pcm_f64le')
                ->onProgress(function ($progress) use ($phase) {
                    if ($progress->phase === $phase) {
                        throw new ExportCancelled('Stop mixing.');
                    }
                })->save('retained.mkv');
        }, ExportCancelled::class);
        $this->assertSame('existing destination', Storage::disk('audio-mix')->get('retained.mkv'));
        $this->assertSame($hash, hash_file('sha256', $this->path('source.mkv')));
        $this->assertWorkspacesAreCleanedUp();
    }
}
