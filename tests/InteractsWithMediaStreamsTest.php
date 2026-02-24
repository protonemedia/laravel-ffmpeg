<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\FFProbe\DataMapping\Stream;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Drivers\InteractsWithMediaStreams;
use ProtoneMedia\LaravelFFMpeg\Drivers\UnknownDurationException;

class InteractsWithMediaStreamsTest extends TestCase
{
    #[Test]
    /** @test */
    public function it_throws_unknown_duration_exception_when_no_video_or_audio_stream_duration_is_available()
    {
        $driver = new class
        {
            use InteractsWithMediaStreams;

            public function getStreams(): array
            {
                return [new Stream(['codec_type' => 'data'])];
            }

            public function getFormat()
            {
                return new class
                {
                    public function has($key): bool
                    {
                        return false;
                    }
                };
            }
        };

        $this->expectException(UnknownDurationException::class);

        $driver->getDurationInMiliseconds();
    }
}
