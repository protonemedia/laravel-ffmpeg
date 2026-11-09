<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ProtoneMedia\LaravelFFMpeg\PendingStill;
use ReflectionClass;

class BuilderShapeTest extends TestCase
{
    /** Builders hold the export pipeline instead of extending it, and expose only what they support. */
    public static function builders(): array
    {
        return [
            'still' => [PendingStill::class, ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'twoPass', 'clip',
                'audio', 'audioOptions', 'audioStream', 'audioFilter', 'volume', 'delayAudio', 'mapAudio', 'withoutAudio', 'withoutVideo']],
        ];
    }

    #[Test]
    #[DataProvider('builders')]
    public function builders_are_final_compositions_without_unsupported_methods(string $class, array $unsupported): void
    {
        $reflection = new ReflectionClass($class);
        $this->assertTrue($reflection->isFinal());
        $this->assertFalse($reflection->getParentClass());
        foreach ($unsupported as $method) {
            $this->assertFalse($reflection->hasMethod($method), $class.' exposes '.$method.'().');
        }
    }
}
