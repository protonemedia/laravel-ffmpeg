<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use ProtoneMedia\LaravelFFMpeg\Exporters\VTTPreviewThumbnailsGenerator;
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;
use Spatie\Snapshots\MatchesSnapshots;

class VTTPreviewThumbnailsGeneratorTest extends TestCase
{
    use MatchesSnapshots;

    /** @test */
    public function it_can_generate_a_vtt_file()
    {
        $tile = TileFactory::make()
            ->interval(5)
            ->grid(5, 5)
            ->width(160)
            ->height(90)
            ->get();

        $generator = new VTTPreviewThumbnailsGenerator($tile, 250, fn () => 'sprite_%d.jpg');

        $this->assertMatchesTextSnapshot($generator->getContents());
    }

    /** @test */
    public function it_can_generate_a_vtt_file_with_a_non_sqaure_grid()
    {
        $tile = TileFactory::make()
            ->interval(10)
            ->grid(2, 3)
            ->width(160)
            ->height(90)
            ->get();

        $generator = new VTTPreviewThumbnailsGenerator($tile, 180, function ($i) {
            return "sprite_{$i}.jpg";
        });

        $this->assertMatchesTextSnapshot($generator->getContents());
    }

    /** @test */
    public function it_can_generate_a_vtt_file_and_keep_the_margin_and_padding_in_account()
    {
        $tile = TileFactory::make()
            ->interval(10)
            ->grid(2, 3)
            ->width(160)
            ->height(90)
            ->margin(5)
            ->padding(15)
            ->get();

        $generator = new VTTPreviewThumbnailsGenerator($tile, 180, function ($i) {
            return "sprite_{$i}.jpg";
        });

        $this->assertMatchesTextSnapshot($generator->getContents());
    }
}
