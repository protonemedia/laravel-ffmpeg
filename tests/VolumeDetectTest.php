<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class VolumeDetectTest extends TestCase
{
    /** @test */
    public function it_can_fetch_the_results_from_volume_detection()
    {
        $this->fakeLocalVideoFile();

        $response = FFMpeg::open('video.mp4')
            ->export()
            ->addFilter(['-filter:a', 'volumedetect', '-f', 'null'])
            ->getResponse();

        $this->assertArrayHasKey('err', $response);
        $this->assertArrayHasKey('out', $response);

        $this->assertStringContainsString('Parsed_volumedetect_0', implode('', $response['err']));
    }
}
