<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;
use PHPUnit\Framework\Attributes\Test;
use FFMpeg\FFProbe\DataMapping\Stream;
use ProtoneMedia\LaravelFFMpeg\Support\StreamParser;

class StreamParserTest extends TestCase
{
    #[Test]
    /** @test */
    public function it_can_extract_the_average_frame_rate()
    {
        $this->assertEquals(25, StreamParser::new(new Stream(['avg_frame_rate' => 25]))->getFrameRate());
        $this->assertEquals(25, StreamParser::new(new Stream(['avg_frame_rate' => '25/1']))->getFrameRate());
        $this->assertEquals(25, StreamParser::new(new Stream(['avg_frame_rate' => '250/10']))->getFrameRate());
        $this->assertEquals(25, StreamParser::new(new Stream(['avg_frame_rate' => '50/2']))->getFrameRate());
    }

    #[Test]
    /** @test */
    public function it_returns_null_for_invalid_average_frame_rate_values()
    {
        $this->assertNull(StreamParser::new(new Stream(['avg_frame_rate' => '']))->getFrameRate());
        $this->assertNull(StreamParser::new(new Stream(['avg_frame_rate' => 'foo/bar']))->getFrameRate());
        $this->assertNull(StreamParser::new(new Stream(['avg_frame_rate' => '25/1/2']))->getFrameRate());
        $this->assertNull(StreamParser::new(new Stream(['avg_frame_rate' => '25/0']))->getFrameRate());
    }
}
