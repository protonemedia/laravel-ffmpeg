<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ProtoneMedia\LaravelFFMpeg\PendingFrame;
use ProtoneMedia\LaravelFFMpeg\PendingFrames;
use ProtoneMedia\LaravelFFMpeg\PendingStill;
use ProtoneMedia\LaravelFFMpeg\PendingStoryboard;
use ProtoneMedia\LaravelFFMpeg\PendingWaveform;
use ReflectionClass;

class BuilderShapeTest extends TestCase
{
    /** Builders hold the export pipeline instead of extending it, and expose only what they support. */
    public static function builders(): array
    {
        $images = ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'twoPass',
            'audio', 'audioOptions', 'audioStream', 'audioFilter', 'volume', 'delayAudio', 'map', 'mapVideo', 'mapAudio',
            'withoutAudio', 'withoutVideo', 'metadata', 'metadataFrom', 'withoutMetadata', 'streamMetadata', 'disposition', 'chaptersFrom'];

        return [
            'frame' => [PendingFrame::class, [...$images, 'clip']],
            'frames' => [PendingFrames::class, $images],
            'storyboard' => [PendingStoryboard::class, $images],
            'waveform' => [PendingWaveform::class, [...array_diff($images, ['audioFilter', 'volume', 'delayAudio'])]],
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
