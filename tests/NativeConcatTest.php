<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeConcatTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-concat-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE to run native concat acceptance.');
        }

        $binary = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => $binary]);

        return $binary;
    }

    #[Test]
    public function compatible_concat_inspection_is_pure_and_does_not_accept_direct_urls(): void
    {
        Process::fake();
        $first = FFMpeg::disk('missing')->open('first.mp4');
        $second = FFMpeg::disk('missing')->open('second.mp4');
        $command = $first->concat($second)->export()->video('copy')->inspect();
        $this->assertSame('concat', $command[array_search('-f', $command) + 1]);
        $this->assertSame('1', $command[array_search('-safe', $command) + 1]);
        Process::assertNothingRan();

        $this->expectException(InvalidArgumentException::class);
        $first->concat(FFMpeg::disk()->openUrl('https://media.example/second.mp4'));
    }

    #[Test]
    public function copy_concat_preserves_order_and_sources_and_normalization_is_an_explicit_graph(): void
    {
        $binary = $this->native();
        foreach ([['red', '64x48', '0.6'], ['blue', '64x48', '1.1'], ['blue', '80x60', '1.1']] as $index => [$color, $size, $duration]) {
            $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', "color=$color:size=$size:rate=10:duration=$duration",
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-threads', '1', $this->root.'/'.$index.'.mp4']);
            $this->assertTrue($result->successful(), $result->errorOutput());
        }

        $unusual = $this->root."/red 'é\n.mp4";
        rename($this->root.'/0.mp4', $unusual);
        $hash = hash_file('sha256', $unusual);
        $first = FFMpeg::disk()->open(new File($unusual));
        Storage::disk('memory')->put('blue.mp4', file_get_contents($this->root.'/1.mp4'));
        $second = FFMpeg::disk('memory')->open('blue.mp4');
        $combined = $first->concat($second);
        $this->assertEqualsWithDelta(1.7, $combined->probe()->duration(), 0.001);
        $combined->export()->video('copy')->toDisk('memory')->save('copy.mp4');
        $copy = FFMpeg::disk('memory')->open('copy.mp4');
        $this->assertSame('h264', $copy->probe()->video()->codec());
        $this->assertEqualsWithDelta(1.7, $copy->probe()->duration(), 0.001);

        $different = FFMpeg::disk()->open(new File($this->root.'/2.mp4'));
        try {
            $first->concat($different)->export()->video('copy')->toDisk('memory')->save('mismatch.mp4');
            $this->fail('Incompatible geometry was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('concat', $exception->category);
            $this->assertFalse(Storage::disk('memory')->exists('mismatch.mp4'));
        }

        $export = $first->export();
        $export->addInput($different);
        $export->filterComplex('[0:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[first];'
            .'[1:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[second];'
            .'[first][second]concat=n=2:v=1:a=0[joined]')
            ->map('[joined]')->video('libx264', crf: 20)->withoutMetadata(chapters: true)
            ->toDisk('memory')->save('normalized.mp4');
        $normalized = FFMpeg::disk('memory')->open('normalized.mp4');
        $metadata = $normalized->probe();
        $this->assertSame(128, $metadata->video()->get('width'));
        $this->assertSame(96, $metadata->video()->get('height'));
        $this->assertEqualsWithDelta(1.7, $metadata->duration(), 0.001);

        foreach ([$copy, $normalized] as $media) {
            foreach ([[0.2, 'red'], [1.0, 'blue']] as [$at, $channel]) {
                $image = imagecreatefromstring($media->frame(at: $at)->get());
                $pixel = imagecolorsforindex($image, imagecolorat($image, 10, 10));
                $this->assertGreaterThan(200, $pixel[$channel]);
                $this->assertLessThan(40, $pixel[$channel === 'red' ? 'blue' : 'red']);
                imagedestroy($image);
            }
        }

        $this->assertSame($hash, hash_file('sha256', $unusual));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function audio_concat_preserves_sample_order_and_rejects_channel_layout_mismatches(): void
    {
        $binary = $this->native();
        foreach (['0.2:s=8000:d=0.35', '0.7:s=8000:d=0.65', '0.7|0.7:s=8000:d=0.2'] as $index => $signal) {
            $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'aevalsrc='.$signal,
                '-c:a', 'pcm_s16le', $this->root.'/'.$index.'.wav']);
            $this->assertTrue($result->successful(), $result->errorOutput());
        }

        $first = FFMpeg::disk()->open(new File($this->root.'/0.wav'));
        $second = FFMpeg::disk()->open(new File($this->root.'/1.wav'));
        $first->concat($second)->export()->audio('copy')->toDisk('memory')->save('joined.wav');
        file_put_contents($this->root.'/joined.wav', Storage::disk('memory')->get('joined.wav'));
        $decoded = Process::run([$binary, '-v', 'error', '-i', $this->root.'/joined.wav', '-f', 's16le', 'pipe:1']);
        $this->assertTrue($decoded->successful(), $decoded->errorOutput());
        $this->assertSame(16000, strlen($decoded->output())); // 8000 mono 16-bit samples.
        $this->assertEqualsWithDelta(6554, unpack('v', substr($decoded->output(), 200, 2))[1], 1);
        $this->assertEqualsWithDelta(22938, unpack('v', substr($decoded->output(), 8000, 2))[1], 1);

        try {
            $first->concat(FFMpeg::disk()->open(new File($this->root.'/2.wav')))
                ->export()->audio('copy')->toDisk('memory')->save('mismatch.wav');
            $this->fail('A different channel layout was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('concat', $exception->category);
            $this->assertFalse(Storage::disk('memory')->exists('mismatch.wav'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function an_explicit_normalization_graph_preserves_audio_delays_across_different_inputs(): void
    {
        $binary = $this->native();
        foreach ([['red', '64x48', '10', '0.6', '0.1', '0.2|0.4:s=48000:d=0.5'],
            ['blue', '80x60', '20', '1.1', '0.15', '0.7:s=8000:d=0.95']] as $index => [$color, $size, $rate, $duration, $delay, $signal]) {
            $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', "color=$color:size=$size:rate=$rate:duration=$duration",
                '-itsoffset', $delay, '-f', 'lavfi', '-i', 'aevalsrc='.$signal,
                '-c:v', 'libx264', '-bf', '0', '-threads', '1', '-c:a', 'pcm_s16le', '-copyts', '-fps_mode:v', 'passthrough', $this->root.'/'.$index.'.mov']);
            $this->assertTrue($result->successful(), $result->errorOutput());
        }

        $first = FFMpeg::disk()->open(new File($this->root.'/0.mov'));
        $export = $first->export();
        $export->addInput(FFMpeg::disk()->open(new File($this->root.'/1.mov')));
        $export->filterComplex('[0:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[v0];'
            .'[1:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[v1];'
            .'[0:a]pan=mono|c0=c0,aresample=16000:async=1:first_pts=0,aformat=sample_fmts=s16,apad=whole_dur=0.6,atrim=duration=0.6[a0];'
            .'[1:a]pan=mono|c0=c0,aresample=16000:async=1:first_pts=0,aformat=sample_fmts=s16,apad=whole_dur=1.1,atrim=duration=1.1[a1];'
            .'[v0][a0][v1][a1]concat=n=2:v=1:a=1[video][audio]')
            ->map('[video]')->map('[audio]')->video('libx264', crf: 20)->audio('pcm_s16le')
            ->withoutMetadata(chapters: true)->toDisk('memory')->save('normalized.mov');

        $media = FFMpeg::disk('memory')->open('normalized.mov');
        $metadata = $media->probe();
        $this->assertSame(128, $metadata->video()->get('width'));
        $this->assertSame(96, $metadata->video()->get('height'));
        $this->assertSame('10/1', $metadata->video()->get('r_frame_rate'));
        $this->assertSame('16000', $metadata->audioStreams()->first()->get('sample_rate'));
        $this->assertSame(1, $metadata->audioStreams()->first()->get('channels'));
        $this->assertEqualsWithDelta(1.7, $metadata->duration(), 0.001);

        file_put_contents($this->root.'/normalized.mov', Storage::disk('memory')->get('normalized.mov'));
        $decoded = Process::run([$binary, '-v', 'error', '-i', $this->root.'/normalized.mov', '-map', '0:a', '-f', 's16le', 'pipe:1']);
        $this->assertTrue($decoded->successful(), $decoded->errorOutput());
        $this->assertSame(54400, strlen($decoded->output())); // 1.7 seconds of 16000 Hz mono PCM.
        foreach ([[0.05, 0], [0.2, 6554], [0.65, 0], [0.85, 22938]] as [$at, $sample]) {
            $this->assertEqualsWithDelta($sample, unpack('v', substr($decoded->output(), (int) ($at * 16000) * 2, 2))[1], 2);
        }

        foreach ([[0.2, 'red'], [0.9, 'blue']] as [$at, $channel]) {
            $image = imagecreatefromstring($media->frame(at: $at)->get());
            $pixel = imagecolorsforindex($image, imagecolorat($image, 10, 10));
            $this->assertGreaterThan(200, $pixel[$channel]);
            $this->assertLessThan(40, $pixel[$channel === 'red' ? 'blue' : 'red']);
            imagedestroy($image);
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function copy_concat_retains_each_segments_audio_delay_relative_to_video(): void
    {
        $binary = $this->native();
        foreach ([['red', '0.6', '0.1', '0.5'], ['blue', '1.1', '0.15', '0.95']] as $index => [$color, $duration, $delay, $audio]) {
            $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', "color=$color:size=64x48:rate=10:duration=$duration",
                '-itsoffset', $delay, '-f', 'lavfi', '-i', 'sine=sample_rate=8000:duration='.$audio,
                '-c:v', 'libx264', '-bf', '0', '-threads', '1', '-c:a', 'pcm_s16le', '-copyts', '-fps_mode:v', 'passthrough', $this->root.'/'.$index.'.mov']);
            $this->assertTrue($result->successful(), $result->errorOutput());
        }

        $first = FFMpeg::disk()->open(new File($this->root.'/0.mov'));
        $second = FFMpeg::disk()->open(new File($this->root.'/1.mov'));
        $this->assertEqualsWithDelta(0, $first->probe()->video()->get('start_time'), 0.001);
        $this->assertEqualsWithDelta(0.1, $first->probe()->audioStreams()->first()->get('start_time'), 0.001);
        $first->concat($second)->export()->map('0')->video('copy')->audio('copy')
            ->toDisk('memory')->save('delayed.mkv');
        file_put_contents($this->root.'/delayed.mkv', Storage::disk('memory')->get('delayed.mkv'));
        $packets = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'a',
            '-show_packets', '-show_entries', 'packet=pts_time', '-of', 'json', $this->root.'/delayed.mkv']);
        $this->assertTrue($packets->successful(), $packets->errorOutput());
        $times = array_map('floatval', array_column(json_decode($packets->output(), true)['packets'], 'pts_time'));
        $this->assertEqualsWithDelta(0.1, $times[0], 0.001);
        $this->assertContains(0.75, $times); // Second video's 0.6-second boundary plus its 0.15-second delay.

        $shifted = Process::run([$binary, '-v', 'error', '-i', $this->root.'/0.mov', '-c', 'copy', '-output_ts_offset', '1', $this->root.'/late.mov']);
        $this->assertTrue($shifted->successful(), $shifted->errorOutput());
        try {
            $first->concat(FFMpeg::disk()->open(new File($this->root.'/late.mov')))
                ->export()->video('copy')->audio('copy')->toDisk('memory')->save('late.mov');
            $this->fail('A nonzero presentation origin was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('concat', $exception->category);
            $this->assertFalse(Storage::disk('memory')->exists('late.mov'));
        }

        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }
}
