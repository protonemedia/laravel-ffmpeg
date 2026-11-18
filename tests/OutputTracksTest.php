<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class OutputTracksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->root.'/files', 0700);
        config(['filesystems.disks.output-tracks' => ['driver' => 'local', 'root' => $this->root.'/files']]);
    }

    protected function path(string $name): string
    {
        return Storage::disk('output-tracks')->path($name);
    }

    protected function media(string $name)
    {
        return FFMpeg::disk('output-tracks')->open($name);
    }

    protected function ffmpeg(array $arguments): string
    {
        $result = Process::run([$this->useRealFFMpeg(), '-v', 'error', '-y', ...$arguments]);

        $this->assertProcessSucceeded($result);

        return $result->output();
    }

    protected function fixture(): void
    {
        $this->ffmpeg(
            [
                '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=10:d=1', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
                '-c:v', 'libx264', '-threads', '1', '-c:a', 'aac', $this->path('source.mp4'),
            ],
        );

        foreach (['png', 'jpg'] as $extension) {
            $this->ffmpeg(
                [
                    '-f', 'lavfi', '-i', 'color=red:s=32x32', '-frames:v', '1', '-threads', '1',
                    $this->path('art.'.$extension),
                ],
            );
        }
    }

    protected function decoded(string $name, string $map): string
    {
        return $this->ffmpeg(['-i', $this->path($name), '-map', $map, '-f', 'framemd5', '-']);
    }

    #[TestWith(['png', 'png'])]
    #[TestWith(['jpg', 'mjpeg'])]
    public function test_attaches_png_and_jpeg_without_changing_motion_or_audio(string $extension, string $codec): void
    {
        $this->fixture();
        $name = 'covered-'.$extension.'.mp4';
        $this->media('source.mp4')
            ->export()
            ->coverArt($this->media('art.'.$extension))
            ->save($name);
        $streams = $this->media($name)
            ->probe()
            ->streams();

        $this->assertCount(3, $streams);

        $cover = $streams->first(fn ($stream) => $stream->get('disposition.attached_pic') === 1);

        $this->assertNotNull($cover);
        $this->assertSame($codec, $cover->get('codec_name'));
        $this->assertSame(32, $cover->get('width'));
        $this->assertSame($this->decoded('source.mp4', '0:V:0'), $this->decoded($name, '0:V:0'));
        $this->assertSame($this->decoded('source.mp4', '0:a:0'), $this->decoded($name, '0:a:0'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_cover_art_supports_audio_only_and_rejects_existing_art(): void
    {
        $this->fixture();
        $this->ffmpeg(['-i', $this->path('source.mp4'), '-vn', '-c:a', 'copy', $this->path('audio.m4a')]);
        $this->media('audio.m4a')
            ->export()
            ->coverArt($this->media('art.png'))
            ->save('album.m4a');

        $this->assertCount(2, $this->media('album.m4a')->probe()->streams());
        $this->assertSame($this->decoded('audio.m4a', '0:a:0'), $this->decoded('album.m4a', '0:a:0'));
        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('does not replace');

        $this->media('album.m4a')
            ->export()
            ->coverArt($this->media('art.jpg'))
            ->save('replacement.m4a');
    }

    public function test_keeps_all_motion_tracks_in_mp4(): void
    {
        $this->fixture();
        $this->ffmpeg(['-i', $this->path('source.mp4'), '-map', '0:v:0', '-map', '0:v:0', '-map', '0:a:0',
            '-c', 'copy', $this->path('multi.mp4')]);
        $this->media('multi.mp4')
            ->export()
            ->coverArt($this->media('art.png'))
            ->save('covered-multi.mp4');

        $this->assertCount(4, $this->media('covered-multi.mp4')->probe()->streams());

        foreach ([0, 1] as $track) {
            $this->assertSame(
                $this->decoded('multi.mp4', '0:V:'.$track),
                $this->decoded('covered-multi.mp4', '0:V:'.$track),
            );
        }
    }

    public function test_cover_art_rejects_mov(): void
    {
        $this->fixture();

        $this->expectException(InvalidArgumentException::class);

        $this->media('source.mp4')
            ->export()
            ->coverArt($this->media('art.png'))
            ->save('unsupported.mov');
    }

    #[TestWith(['png'])]
    #[TestWith(['jpg'])]
    public function test_mp3_cover_art_preserves_decoded_audio(string $extension): void
    {
        $this->fixture();
        // The minimal FFmpeg 9 acceptance build has an MP3 decoder but no encoder.
        // Use the system encoder only to generate input; all library work uses 9.
        $fixture = Process::run(
            [
                'ffmpeg', '-v', 'error', '-y', '-i', $this->path('source.mp4'), '-vn', '-c:a', 'libmp3lame',
                $this->path('audio.mp3'),
            ],
        );

        if (! $fixture->successful()) {
            $this->markTestSkipped('A system FFmpeg with libmp3lame is needed to generate the MP3 fixture.');
        }

        $name = 'album-'.$extension.'.mp3';
        $this->media('audio.mp3')
            ->export()
            ->coverArt($this->media('art.'.$extension))
            ->save($name);

        $this->assertCount(2, $this->media($name)->probe()->streams());
        $this->assertSame($this->decoded('audio.mp3', '0:a:0'), $this->decoded($name, '0:a:0'));
    }

    public static function ambiguousCoverProvider(): array
    {
        return [
            'explicit map' => [fn ($export) => $export->map('0:v:0')],
            'filter graph' => [fn ($export) => $export->filterComplex('[0:v]null[out]')],
            'video codec' => [fn ($export) => $export->video('libx264')],
            'resize' => [fn ($export) => $export->resize(32)],
        ];
    }

    #[DataProvider('ambiguousCoverProvider')]
    public function test_rejects_ambiguous_cover_configuration(Closure $configure): void
    {
        $this->fixture();

        $this->expectException(InvalidArgumentException::class);

        $configure($this->media('source.mp4')->export()->coverArt($this->media('art.png')))->inspect();
    }

    public function test_rejects_non_image_cover_art(): void
    {
        $this->fixture();

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('standalone PNG or JPEG');

        $this->media('source.mp4')
            ->export()
            ->coverArt($this->media('source.mp4'))
            ->save('invalid.mp4');
    }

    public function test_encodes_independent_video_tracks_in_mapping_order_in_one_mp4(): void
    {
        $this->fixture();
        $this->media('source.mp4')
            ->export()
            ->map('0:a:0')
            ->map('0:v:0')
            ->map('0:v:0')
            ->audio('copy')
            ->videoStream(0, fn ($stream) => $stream->codec('libx264')->crf(12)->preset('ultrafast'))
            ->videoStream(1, fn ($stream) => $stream->codec('mpeg4')->bitrate('80k'))
            ->save('tracks.mp4');
        $streams = $this->media('tracks.mp4')
            ->probe()
            ->streams();

        $this->assertSame(
            ['audio', 'video', 'video'],
            $streams->map(fn ($stream) => $stream->get('codec_type'))->all(),
        );

        $videos = $streams->filter(fn ($stream) => $stream->get('codec_type') === 'video')
            ->values();

        $this->assertSame(['h264', 'mpeg4'], $videos->map(fn ($stream) => $stream->get('codec_name'))->all());

        foreach ([0, 1] as $track) {
            $raw = $this->ffmpeg(
                ['-i', $this->path('tracks.mp4'), '-map', '0:v:'.$track, '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-'],
            );
            $this->assertSame(96 * 64 * 3 * 10, strlen($raw));
        }

        $this->assertSame($this->decoded('source.mp4', '0:a:0'), $this->decoded('tracks.mp4', '0:a:0'));
    }

    public function test_split_scaled_graph_outputs_encode_distinct_geometries_and_bitrates_in_one_mp4(): void
    {
        $this->fixture();
        $result = $this->media('source.mp4')
            ->export()
            ->filterComplex('[0:v:0]split=2[large][small];[large]scale=96:64[high];[small]scale=48:32[low]')
            ->map('[high]')
            ->map('0:a:0')
            ->map('[low]')
            ->audio('copy')
            ->videoStream(0, fn ($video) => $video->codec('libx264')->bitrate('400k')->preset('ultrafast'))
            ->videoStream(1, fn ($video) => $video->codec('mpeg4')->bitrate('40k'))
            ->save('scaled-tracks.mp4');

        $this->assertCount(1, $result->files());

        $streams = $this->media('scaled-tracks.mp4')
            ->probe()
            ->streams();

        $this->assertCount(3, $streams);
        $this->assertSame(
            ['video', 'audio', 'video'],
            $streams->map(fn ($stream) => $stream->get('codec_type'))->all(),
        );

        $videos = $streams->filter(fn ($stream) => $stream->get('codec_type') === 'video')
            ->values();

        $this->assertSame(['h264', 'mpeg4'], $videos->map(fn ($stream) => $stream->get('codec_name'))->all());

        foreach ([[96, 64], [48, 32]] as $track => [$width, $height]) {
            $this->assertSame($width, $videos[$track]->get('width'));
            $this->assertSame($height, $videos[$track]->get('height'));
            $this->assertGreaterThan(0, (int) $videos[$track]->get('bit_rate'));
            $frames = $this->ffmpeg(
                [
                    '-i', $this->path('scaled-tracks.mp4'), '-map', '0:v:'.$track, '-pix_fmt', 'rgb24', '-f',
                    'rawvideo', '-',
                ],
            );
            $this->assertSame($width * $height * 3 * 10, strlen($frames));
        }

        $this->assertNotSame($videos[0]->get('bit_rate'), $videos[1]->get('bit_rate'));
        $this->assertSame($this->decoded('source.mp4', '0:a:0'), $this->decoded('scaled-tracks.mp4', '0:a:0'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_graph_pad_types_defer_missing_video_track_validation_until_before_publication(): void
    {
        $this->fixture();

        $this->assertThrows(function () {
            $this->media('source.mp4')
                ->export()
                ->filterComplex('[0:a:0]anull[audio]')
                ->map('[audio]')
                ->videoStream(0, fn ($video) => $video->codec('libx264'))
                ->save('no-video.m4a');
        }, function (MediaException $exception) {
            $this->assertSame('validation', $exception->phase);
            $this->assertSame('mapping', $exception->category);
            $this->assertStringContainsString('existing encoded output video tracks', $exception->getMessage());

            return true;
        });
        $this->assertFalse(Storage::disk('output-tracks')->exists('no-video.m4a'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_rejects_missing_video_track_and_conflicting_rate_control(): void
    {
        $this->fixture();

        $this->assertThrows(function () {
            $this->media('source.mp4')
                ->export()
                ->videoStream(0, fn ($s) => $s->crf(20)->bitrate('100k'))
                ->inspect();
        }, InvalidArgumentException::class);
        $this->assertThrows(function () {
            $this->media('source.mp4')
                ->export()
                ->videoStream(1, fn ($s) => $s->codec('libx264'))
                ->save('missing.mp4');
        }, function (MediaException $exception) {
            $this->assertSame('probing', $exception->phase);
            $this->assertSame('mapping', $exception->category);
            $this->assertStringContainsString('existing output video tracks', $exception->getMessage());

            return true;
        });
        $this->assertFalse(Storage::disk('output-tracks')->exists('missing.mp4'));
    }

    public static function invalidTrackProvider(): array
    {
        return [
            'negative index' => [fn ($e) => $e->videoStream(-1, fn ($s) => $s->codec('libx264'))],
            'zero bitrate' => [fn ($e) => $e->videoStream(0, fn ($s) => $s->bitrate(0))],
            'infinite crf' => [fn ($e) => $e->videoStream(0, fn ($s) => $s->crf(INF))],
            'invalid preset' => [fn ($e) => $e->videoStream(0, fn ($s) => $s->preset('bad preset'))],
            'copy with crf' => [fn ($e) => $e->videoStream(0, fn ($s) => $s->codec('copy')->crf(10))->inspect()],
            'copy with resize' => [fn ($e) => $e->videoStream(0, fn ($s) => $s->codec('copy'))->resize(32)->inspect()],
            'duplicate index' => [
                fn ($e) => $e->videoStream(
                    0,
                    fn ($s) => $s->codec('libx264'),
                )->videoStream(0, fn ($s) => $s->codec('mpeg4')),
            ],
        ];
    }

    #[DataProvider('invalidTrackProvider')]
    public function test_validates_video_track_indexes_settings_and_copy_conflicts(Closure $configure): void
    {
        $this->expectException(InvalidArgumentException::class);

        $configure($this->media('unopened.mp4')->export());
    }

    #[TestWith([32, null, 32, 48])]
    #[TestWith([null, 48, 32, 48])]
    #[TestWith([40, 60, 40, 60])]
    public function test_autorotated_portrait_resizes_display_geometry(
        ?int $width,
        ?int $height,
        int $expectedWidth,
        int $expectedHeight,
    ): void {
        $this->fixture();
        $this->ffmpeg(
            ['-display_rotation:v:0', '90', '-i', $this->path('source.mp4'), '-c', 'copy', $this->path('rotated.mp4')],
        );

        $this->assertSame(90, $this->media('rotated.mp4')->probe()->video()->get('side_data_list.0.rotation'));

        $this->media('rotated.mp4')
            ->export()
            ->video('libx264')
            ->audio('copy')
            ->resize($width, $height)
            ->save('portrait.mp4');
        $video = $this->media('portrait.mp4')
            ->probe()
            ->video();

        $this->assertSame($expectedWidth, $video->get('width'));
        $this->assertSame($expectedHeight, $video->get('height'));
        $this->assertSame('1:1', $video->get('sample_aspect_ratio'));
        $this->assertSame($expectedWidth * $expectedHeight * 3 * 10, strlen($this->ffmpeg([
            '-i', $this->path('portrait.mp4'), '-map', '0:v:0', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-',
        ])));
    }

    public function test_explicit_subtitle_map_extracts_webvtt_without_new_api(): void
    {
        $this->fixture();
        Storage::disk('output-tracks')->put('captions.srt', <<<'SRT'
            1
            00:00:00,200 --> 00:00:00,800
            Hello portrait!


            SRT);
        $this->ffmpeg(['-i', $this->path('source.mp4'), '-i', $this->path('captions.srt'), '-map', '0', '-map', '1:s:0',
            '-c', 'copy', '-c:s', 'mov_text', $this->path('subtitled.mp4')]);
        $this->media('subtitled.mp4')
            ->export()
            ->map('0:s:0')
            ->outputOptions(['-c:s', 'webvtt'])
            ->save('captions.vtt');
        $text = Storage::disk('output-tracks')->get('captions.vtt');

        $this->assertStringStartsWith('WEBVTT', $text);
        $this->assertStringContainsString('00:00.200 --> 00:00.800', $text);
        $this->assertStringContainsString('Hello portrait!', $text);
        $this->assertSame('webvtt', $this->media('captions.vtt')->probe()->streams()->first()->get('codec_name'));
    }
}
