<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSPlaylistGenerator;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;

class HLSPlaylistGeneratorTest extends TestCase
{
    #[Test]
    /** @test */
    public function it_throws_an_exception_when_the_segment_playlist_guide_is_missing()
    {
        $this->fakeLocalVideoFile();

        $driver = (new \ProtoneMedia\LaravelFFMpeg\MediaOpener)->open('video.mp4')->getDriver();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Segment playlist not found');

        (new HLSPlaylistGenerator)->get([
            Media::make('local', 'adaptive_0_250.m3u8'),
        ], $driver);
    }
}
