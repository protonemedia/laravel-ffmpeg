<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\LegacyFilterMapping;

class LegacyFilterMappingTest extends TestCase
{
    #[Test]
    /** @test */
    public function it_normalizes_the_input_mapping_to_an_integer()
    {
        $mapping = new LegacyFilterMapping('[v12]', '[out]', '-vf', 'scale=640:360');

        $this->assertSame(12, $mapping->normalizeIn());
    }
}
