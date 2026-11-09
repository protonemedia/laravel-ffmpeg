<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingWaveformPeaks;

class NativeWaveformPeaksTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-peaks-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/work', 0700);
        mkdir($this->root.'/disk', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/work',
            'filesystems.disks.peaks-fixtures' => ['driver' => 'local', 'root' => $this->root.'/disk']]);
        $probe = getenv('FFMPEG_9_FFPROBE');
        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for native peaks acceptance.');
        }
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg']);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function wav(string $name, array $channels, int $rate = 8000): Media
    {
        $pcm = '';
        foreach (array_keys($channels[0]) as $frame) {
            foreach ($channels as $samples) {
                $pcm .= pack('v', $samples[$frame] & 65535);
            }
        }
        $count = count($channels);
        $header = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, $count, $rate, $rate * $count * 2, $count * 2, 16)
            .'data'.pack('V', strlen($pcm));
        Storage::disk('peaks-fixtures')->put($name, $header.$pcm);

        return new Media('peaks-fixtures', $name);
    }

    private function pending(Media $media, int $samples = 4): PendingWaveformPeaks
    {
        return $media->waveformPeaks($samples);
    }

    private function clean(): void
    {
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    #[Test]
    public function asymmetric_signed_little_endian_extrema_keep_unequal_channels_and_partial_bucket(): void
    {
        $media = $this->wav('asymmetric.wav', [
            [-32768, -1234, -257, -1, 1, 257, 1234, 32767, -256, 256, 128],
            [100, 200, 300, 400, -100, -200, -300, -400, -5, -6, -7],
        ]);
        $original = Storage::disk('peaks-fixtures')->get('asymmetric.wav');
        $peaks = $this->pending($media)->get();
        $this->assertSame([4, 4, 3], $peaks->bucketFrames);
        $this->assertSame([[[-32768, -1], [1, 32767], [-256, 256]], [[100, 400], [-400, -100], [-7, -5]]], $peaks->channels);
        $this->assertSame(8000, $peaks->sampleRate);
        $this->assertSame(11, $peaks->frames);
        $this->assertSame(11 / 8000, $peaks->duration());
        $result = $this->pending($media)->save('peaks.json');
        $this->assertSame('waveform-peaks', $result->file()->role);
        $this->assertSame($peaks->toArray(), json_decode(Storage::disk('peaks-fixtures')->get('peaks.json'), true));
        $this->assertCount(1, $result->processes());
        $this->assertSame($original, Storage::disk('peaks-fixtures')->get('asymmetric.wav'));
        $this->clean();
    }

    #[Test]
    public function separate_channels_keep_non_default_layouts_without_remixing(): void
    {
        // Quad and a custom layout differ from FFmpeg's default four-channel layout (4.0).
        foreach (['quad', 'FL+FR+LFE+BC'] as $index => $layout) {
            $name = 'layout-'.$index.'.wav';
            $result = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-f', 'lavfi', '-i',
                'aevalsrc=0.1|0.2|0.3|0.4:c='.$layout.':s=8000:d=0.1', '-c:a', 'pcm_s16le', Storage::disk('peaks-fixtures')->path($name)]);
            $this->assertTrue($result->successful(), $result->errorOutput());
            $peaks = $this->pending(new Media('peaks-fixtures', $name))->buckets(1)->get();
            $this->assertSame([[[3277, 3277]], [[6554, 6554]], [[9830, 9830]], [[13107, 13107]]], $peaks->channels, $layout);
        }
        $this->clean();
    }

    #[Test]
    public function mono_is_an_equal_weight_mix_not_a_merge_of_channel_extrema(): void
    {
        $media = $this->wav('stereo.wav', [[1000, -1000, 10000, -10000, 2000], [-1000, 1000, -2000, 2000, -2000]]);
        $peaks = $this->pending($media)->mono()->get();
        $this->assertSame('mono', $peaks->channelMode);
        $this->assertSame(2, $peaks->inputChannels);
        $this->assertSame([[[-4000, 4000], [0, 0]]], $peaks->channels);
        $this->clean();
    }

    #[Test]
    public function requested_counts_partition_frames_without_padding_or_losing_boundaries(): void
    {
        $media = $this->wav('ramp.wav', [range(1, 11)]);
        $peaks = $this->pending($media)->buckets(3)->get();
        $this->assertSame([4, 4, 3], $peaks->bucketFrames);
        $this->assertSame([[[1, 4], [5, 8], [9, 11]]], $peaks->channels);
        $this->assertNull($peaks->samplesPerBucket);
        $this->assertSame([4 / 8000, 4 / 8000, 3 / 8000], $peaks->toArray()['bucketDurations']);
        $this->assertSame(array_fill(0, 11, 1), $this->pending($media)->buckets(100)->get()->bucketFrames);
        $this->clean();
    }

    #[Test]
    public function read_chunk_boundaries_preserve_channel_alignment_and_bucket_extrema(): void
    {
        $media = $this->wav('chunks.wav', [range(1, 4101), range(-4101, -1)]);
        $peaks = $this->pending($media, 2050)->get();
        $this->assertSame([2050, 2050, 1], $peaks->bucketFrames);
        $this->assertSame([[[1, 2050], [2051, 4100], [4101, 4101]], [[-4101, -2052], [-2051, -2], [-1, -1]]], $peaks->channels);
        $this->clean();
    }

    #[Test]
    public function silence_filters_and_accurate_clips_describe_decoded_output_frames(): void
    {
        $media = $this->wav('ramp.wav', [range(100, 110)]);
        $silent = $this->pending($media)->audioFilter('volume=0')->get();
        $this->assertSame([[[0, 0], [0, 0], [0, 0]]], $silent->channels);
        $clip = $this->pending($media)->audioFilter('volume=2:precision=fixed')->clip(2 / 8000, duration: 6 / 8000)->get();
        $this->assertSame(6, $clip->frames);
        $this->assertSame([4, 2], $clip->bucketFrames);
        $this->assertSame([[[204, 210], [212, 214]]], $clip->channels);
        $empty = $this->pending($media)->clip(1.0, duration: 1.0)->get();
        $this->assertSame(0, $empty->frames);
        $this->assertSame([[]], $empty->channels);
        $this->clean();
    }

    #[Test]
    public function selected_audio_track_is_decoded_and_a_missing_track_fails_cleanly(): void
    {
        $first = $this->wav('first.wav', [[100, 200, 300, 400, 500]]);
        $this->wav('second.wav', [[-500, -400, -300, -200, -100]], 16000);
        $process = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-i', $this->root.'/disk/first.wav',
            '-i', $this->root.'/disk/second.wav', '-map', '0:a', '-map', '1:a', '-c:a', 'pcm_s16le', $this->root.'/disk/tracks.nut']);
        $this->assertTrue($process->successful(), $process->errorOutput());
        $peaks = $this->pending(new Media('peaks-fixtures', 'tracks.nut'))->audioTrack(1)->get();
        $this->assertSame(1, $peaks->audioTrack);
        $this->assertSame(16000, $peaks->sampleRate);
        $this->assertSame(5 / 16000, $peaks->duration());
        $this->assertSame([[[-500, -200], [-100, -100]]], $peaks->channels);
        try {
            $this->pending($first)->audioTrack(1)->get();
            $this->fail('Missing track accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('mapping', $exception->category);
        }
        $this->clean();
    }

    #[Test]
    public function publication_uses_a_stream_and_false_returns_fail_without_leaking_workspaces(): void
    {
        $media = $this->wav('source.wav', [[-10, 20, -30, 40]]);
        $disk = Mockery::mock(Filesystem::class);
        $publishedStream = null;
        $disk->shouldReceive('put')->once()->with('peaks.json', Mockery::on(function ($stream) use (&$publishedStream) {
            $publishedStream = $stream;

            return is_resource($stream) && json_decode(stream_get_contents($stream), true)['channels'] === [[[-30, 40]]];
        }), [])->andReturn(false);
        try {
            $this->pending($media)->toDisk($disk)->save('peaks.json');
            $this->fail('False publication accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('publication', $exception->phase);
            $this->assertSame('transfer', $exception->category);
        }
        $this->assertNotNull($publishedStream);
        $this->assertFalse(is_resource($publishedStream));
        $this->clean();
    }

    #[Test]
    public function per_track_audio_settings_are_rejected_before_io(): void
    {
        foreach ([fn ($a) => $a->filter('volume=2'), fn ($a) => $a->sampleRate(16000)] as $configure) {
            try {
                (new Media('peaks-fixtures', 'missing.wav'))->waveformPeaks()->mono()->audioStream(0, $configure)->inspect();
                $this->fail('Per-track audio settings accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('audioFilter()', $exception->getMessage());
            }
        }
        try {
            (new Media('peaks-fixtures', 'missing.wav'))->waveformPeaks()->output('extra.json', fn ($output) => null);
            $this->fail('A second peaks output was accepted.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function aggregate_memory_is_bounded_and_filter_failures_clean_up(): void
    {
        $media = $this->wav('long.wav', [array_fill(0, 100001, 1)]);
        try {
            $this->pending($media, 1)->get();
            $this->fail('Unbounded bucket count accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('output_limit', $exception->category);
        }
        $this->assertSame([[[1, 1], [1, 1]]], $this->pending($media)->buckets(2)->get()->channels);
        try {
            $this->pending($media)->audioFilter('not_a_real_audio_filter')->get();
            $this->fail('Invalid filter accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('encoding', $exception->phase);
        }
        $this->clean();
    }
}
