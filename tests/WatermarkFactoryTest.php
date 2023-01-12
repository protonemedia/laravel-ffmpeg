<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Format\VideoInterface;
use FFMpeg\Media\Video;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFilter;

class WatermarkFactoryTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->fakeLocalLogoFile();
    }

    private function getSecondCommand(WatermarkFactory $watermarkFactory): string
    {
        return $watermarkFactory->get()->apply(
            $this->mock(Video::class),
            $this->mock(VideoInterface::class)
        )[1];
    }

    /** @test */
    public function it_gives_the_complete_path_of_the_watermark_to_the_watermark_filter()
    {
        $factory = new WatermarkFactory();
        $factory->open('logo.png');

        $this->assertInstanceOf(WatermarkFilter::class, $factory->get());

        $this->assertStringContainsString(
            'movie=' . WatermarkFilter::normalizePath(Storage::disk('local')->path('logo.png')) . ' [watermark];',
            $this->getSecondCommand($factory)
        );
    }

    /** @test */
    public function it_downloads_a_remote_logo_to_a_temporary_filesystem()
    {
        $factory = new WatermarkFactory();
        $factory->openUrl('https://ffmpeg.protone.media/logo.png', [
            'Authorization' => 'Basic YWRtaW46MTIzNA==',
        ]);

        $this->assertInstanceOf(WatermarkFilter::class, $factory->get());

        $command = $this->getSecondCommand($factory);

        $this->assertStringContainsString(
            substr(WatermarkFilter::normalizePath(config('laravel-ffmpeg.temporary_files_root')), 1, -1),
            $command
        );

        $this->assertStringContainsString('logo.png', $command);
    }

    /** @test */
    public function it_gives_the_coordinates_to_the_watermark_filter()
    {
        $factory = new WatermarkFactory();
        $factory->open('logo.png')->top(25)->left(50);

        $this->assertStringContainsString(
            "overlay=50:25",
            $this->getSecondCommand($factory)
        );

        // switch from top to bottom, switch from left to right
        $factory->bottom(25)->right(50);

        $this->assertStringContainsString(
            "overlay=main_w - 50 - overlay_w:main_h - 25 - overlay_h",
            $this->getSecondCommand($factory)
        );
    }

    /** @test */
    public function it_gives_the_coordinates_to_a_custom_filter_by_using_the_preset_position()
    {
        $factory = new WatermarkFactory();
        $factory->open('logo.png');

        $factory->horizontalAlignment(WatermarkFactory::LEFT, 50)
            ->verticalAlignment(WatermarkFactory::TOP, 25);

        $this->assertStringContainsString("overlay=50:25", $this->getSecondCommand($factory));

        //

        $factory->horizontalAlignment(WatermarkFactory::CENTER, 50)
            ->verticalAlignment(WatermarkFactory::CENTER, 25);

        $this->assertStringContainsString("overlay=(W-w)/2+50:(H-h)/2+25", $this->getSecondCommand($factory));

        //

        $factory->horizontalAlignment(WatermarkFactory::RIGHT, 50)
            ->verticalAlignment(WatermarkFactory::BOTTOM, 25);

        $this->assertStringContainsString("overlay=W-w+50:H-h+25", $this->getSecondCommand($factory));
    }

    /** @test */
    public function it_can_manipulate_the_watermark_image()
    {
        $factory = new WatermarkFactory();

        $factory->open('logo.png')
            ->width(100)
            ->bottom(25)
            ->left(25);

        $command = $this->getSecondCommand($factory);

        $this->assertStringContainsString(
            substr(WatermarkFilter::normalizePath(config('laravel-ffmpeg.temporary_files_root')), 1, -1),
            $command
        );

        $path = $factory->getPath();

        [$width] = getimagesize($path);

        $this->assertEquals(100, $width);
    }

    /** @test */
    public function it_can_normalize_a_windows_path()
    {
        $this->assertEquals(
            'c\:\\\Videos\\\watermarklogo.png',
            WatermarkFilter::normalizeWindowsPath('c:/Videos/watermarklogo.png')
        );
    }
}
