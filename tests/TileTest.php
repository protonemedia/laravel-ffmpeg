<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\ImageFormat;
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;
use ProtoneMedia\LaravelFFMpeg\Filters\TileFilter;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class TileTest extends TestCase
{
    /** @test */
    public function it_has_a_tile_filter()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->export()
            ->addFilter(new TileFilter(2, 160, 90, 2, 2))
            ->inFormat(new ImageFormat())
            ->save('2x2_%05d.jpg');

        $this->assertTrue(Storage::disk('local')->has('2x2_00001.jpg'));
        $this->assertTrue(Storage::disk('local')->has('2x2_00002.jpg'));
        $this->assertFalse(Storage::disk('local')->has('2x2_00003.jpg'));
    }

    /** @test */
    public function it_can_generate_thumbnails()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportFramesByInterval(2)
            ->save('thumb_%d.jpg');

        $this->assertTrue(Storage::disk('local')->has('thumb_1.jpg'));
        $this->assertTrue(Storage::disk('local')->has('thumb_2.jpg'));
        $this->assertTrue(Storage::disk('local')->has('thumb_3.jpg'));
        $this->assertTrue(Storage::disk('local')->has('thumb_4.jpg'));
        $this->assertTrue(Storage::disk('local')->has('thumb_5.jpg'));
        $this->assertFalse(Storage::disk('local')->has('thumb_6.jpg'));
    }

    /**
     * @dataProvider provideThumbnailAmount
     * @test
     */
    public function it_can_generate_thumbnails_by_amount(int $amount)
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportFramesByAmount($amount)
            ->save('thumb_%d.jpg');

        $this->assertCount(
            $amount + 1,    // count video3.mp4 as well
            $files = Storage::disk('local')->allFiles(),
            "Requested amount: {$amount}, files found: " . implode(', ', $files)
        );
    }

    /** @test */
    public function it_can_generate_thumbnails_with_a_specified_quality()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportFramesByAmount(1)
            ->save('loseless.png');

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportFramesByAmount(1, null, null, 2)
            ->save('high_quality.jpg');

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportFramesByAmount(1, null, null, 31)
            ->save('low_quality.jpg');

        $this->assertTrue(
            Storage::disk('local')->size('loseless.png') > Storage::disk('local')->size('high_quality.jpg')
        );

        $this->assertTrue(
            Storage::disk('local')->size('high_quality.jpg') > Storage::disk('local')->size('low_quality.jpg')
        );
    }

    public function provideThumbnailAmount()
    {
        return array_map(fn ($i) => [$i], range(1, 10));
    }

    /** @test */
    public function it_has_a_tile_filter_and_can_store_the_vtt_file()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportTile(function (TileFactory $factory) {
                $factory->interval(1)
                    ->scale(160)
                    ->grid(3, 3)
                    ->margin(30)
                    ->padding(15)
                    ->generateVTT('thumbnails.vtt');
            })
            ->save('3x3_%05d.jpg');

        $this->assertTrue(Storage::disk('local')->has('thumbnails.vtt'));
    }

    /** @test */
    public function it_can_generate_the_tiles_and_vtt_on_an_external_disk()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportTile(function (TileFactory $factory) {
                $factory->interval(2)
                    ->grid(2, 2)
                    ->generateVTT('thumbnails.vtt');
            })
            ->toDisk('memory')
            ->save('tile_%05d.jpg');

        $this->assertTrue(Storage::disk('memory')->has('tile_00001.jpg'));
        $this->assertTrue(Storage::disk('memory')->has('tile_00002.jpg'));
        $this->assertTrue(Storage::disk('memory')->has('thumbnails.vtt'));
    }

    /** @test */
    public function it_has_a_tile_factory()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportTile(function (TileFactory $factory) {
                $factory->interval(1)
                    ->scale(160)
                    ->grid(3, 3)
                    ->margin(30)
                    ->padding(15);
            })
            ->save('3x3_%05d.jpg');

        $this->assertTrue(Storage::disk('local')->has('3x3_00001.jpg'));
        $this->assertTrue(Storage::disk('local')->has('3x3_00002.jpg'));
        $this->assertFalse(Storage::disk('local')->has('3x3_00003.jpg'));

        //

        $imageSpecs = getimagesize(
            Storage::disk('local')->path('3x3_00001.jpg')
        );

        $this->assertEquals(570, $imageSpecs[0]); // 30 + 160 + 15 + 160 + 15 + 160 + 30
        $this->assertEquals(360, $imageSpecs[1]); // 30 + 90 + 15 + 90 + 15 + 90 + 30
    }

    /** @test */
    public function it_has_a_tile_factory_that_can_set_the_width_by_the_given_height()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportTile(function (TileFactory $factory) {
                $factory->interval(1)
                    ->scale(null, 50)
                    ->grid(1, 2);
            })
            ->save('1x2_%05d.jpg');

        $this->assertTrue(Storage::disk('local')->has('1x2_00001.jpg'));
        $this->assertTrue(Storage::disk('local')->has('1x2_00002.jpg'));
        $this->assertTrue(Storage::disk('local')->has('1x2_00003.jpg'));
        $this->assertTrue(Storage::disk('local')->has('1x2_00004.jpg'));
        $this->assertTrue(Storage::disk('local')->has('1x2_00005.jpg'));
        $this->assertFalse(Storage::disk('local')->has('1x2_00006.jpg'));

        //

        $imageSpecs = getimagesize(
            Storage::disk('local')->path('1x2_00001.jpg')
        );

        $this->assertEquals(89, $imageSpecs[0]);
        $this->assertEquals(100, $imageSpecs[1]);
    }

    /** @test */
    public function it_can_set_the_quality_of_the_jpeg()
    {
        $this->fakeLongLocalVideoFile();

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportTile(function (TileFactory $factory) {
                $factory->interval(10)
                    ->grid(1, 1)
                    ->quality(2);
            })
            ->save('high_quality.jpg');

        (new MediaOpener())
            ->open('video3.mp4')
            ->exportTile(function (TileFactory $factory) {
                $factory->interval(10)
                    ->grid(1, 1)
                    ->quality(31);
            })
            ->save('low_quality.jpg');

        $this->assertTrue(
            Storage::disk('local')->size('high_quality.jpg') > Storage::disk('local')->size('low_quality.jpg')
        );
    }
}
