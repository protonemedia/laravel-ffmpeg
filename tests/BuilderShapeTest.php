<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ProtoneMedia\LaravelFFMpeg\PendingAudioMix;
use ProtoneMedia\LaravelFFMpeg\PendingConcat;
use ProtoneMedia\LaravelFFMpeg\PendingDashExport;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\PendingFrame;
use ProtoneMedia\LaravelFFMpeg\PendingFrames;
use ProtoneMedia\LaravelFFMpeg\PendingGif;
use ProtoneMedia\LaravelFFMpeg\PendingHlsExport;
use ProtoneMedia\LaravelFFMpeg\PendingLoudness;
use ProtoneMedia\LaravelFFMpeg\PendingSpeed;
use ProtoneMedia\LaravelFFMpeg\PendingStill;
use ProtoneMedia\LaravelFFMpeg\PendingStoryboard;
use ProtoneMedia\LaravelFFMpeg\PendingWaveform;
use ProtoneMedia\LaravelFFMpeg\PendingWaveformPeaks;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class BuilderShapeTest extends TestCase
{
    /** Builders hold the export pipeline instead of extending it, and expose only what they support. */
    public static function builders(): array
    {
        $images = ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'twoPass',
            'audio', 'audioOptions', 'audioStream', 'audioFilter', 'volume', 'delayAudio', 'map', 'mapVideo', 'mapAudio',
            'withoutAudio', 'withoutVideo', 'metadata', 'metadataFrom', 'withoutMetadata', 'streamMetadata', 'disposition', 'chaptersFrom'];

        return [
            'mixAudio' => [PendingAudioMix::class, ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'twoPass', 'clip',
                'map', 'mapVideo', 'mapAudio', 'withoutAudio', 'audioStream']],
            'concatTranscoded' => [PendingConcat::class, ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt',
                'videoStream', 'audioStream', 'color', 'map', 'mapVideo', 'mapAudio', 'withoutVideo', 'videoFilter', 'resize', 'fit',
                'audioFilter', 'volume', 'delayAudio', 'fadeIn', 'fadeOut', 'drawText', 'resource', 'metadataFrom', 'chaptersFrom', 'withoutMetadata']],
            'frame' => [PendingFrame::class, [...$images, 'clip']],
            'frames' => [PendingFrames::class, $images],
            'storyboard' => [PendingStoryboard::class, $images],
            'waveformPeaks' => [PendingWaveformPeaks::class, ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'twoPass',
                'audio', 'audioOptions', 'audioStream', 'map', 'mapVideo', 'mapAudio', 'withoutAudio', 'withoutVideo',
                'video', 'videoOptions', 'videoStream', 'videoFilter', 'resize', 'drawText', 'resource', 'metadata', 'withoutMetadata', 'disposition']],
            'waveform' => [PendingWaveform::class, [...array_diff($images, ['audioFilter', 'volume', 'delayAudio'])]],
            'gif' => [PendingGif::class, ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'twoPass',
                'video', 'videoOptions', 'videoStream', 'color', 'audio', 'audioOptions', 'audioStream', 'audioFilter', 'volume', 'delayAudio',
                'map', 'mapVideo', 'mapAudio', 'withoutAudio', 'withoutVideo', 'outputOptions', 'drawText', 'burnSubtitles', 'lut3d', 'fadeIn', 'fadeOut']],
            'speed' => [PendingSpeed::class, ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'clip']],
            'normalizeLoudness' => [PendingLoudness::class, ['output', 'addInput', 'filterComplex', 'watermark', 'coverArt', 'twoPass',
                'globalOptions', 'map', 'mapVideo', 'mapAudio', 'withoutAudio', 'withoutVideo', 'audioStream', 'video', 'videoOptions',
                'videoStream', 'videoFilter', 'resize', 'drawText', 'resource', 'textResource', 'subtitleResource']],
            'dash' => [PendingDashExport::class, ['output', 'twoPass', 'coverArt', 'watermark', 'filterComplex', 'delayAudio',
                'withoutAudio', 'withoutVideo', 'videoStream', 'audioStream']],
            'hls' => [PendingHlsExport::class, ['output', 'coverArt']],
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

        // Forwarders must accept exactly what the pipeline accepts.
        $describe = fn (ReflectionMethod $method) => array_map(fn (ReflectionParameter $parameter) => [
            $parameter->getName(), (string) $parameter->getType(), $parameter->isVariadic(),
            $parameter->isDefaultValueAvailable() ? var_export($parameter->getDefaultValue(), true) : null,
        ], $method->getParameters());
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || ! method_exists(PendingExport::class, $method->getName())
                || ! (new ReflectionMethod(PendingExport::class, $method->getName()))->isPublic()) {
                continue;
            }
            $this->assertSame($describe(new ReflectionMethod(PendingExport::class, $method->getName())), $describe($method),
                $class.'::'.$method->getName().'() differs from PendingExport.');
        }
    }

    #[Test]
    public function no_package_class_extends_another(): void
    {
        $classes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__).'/src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'ProtoneMedia\\LaravelFFMpeg\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(dirname(__DIR__).'/src/')));
            if (! class_exists($class)) {
                continue;
            }
            $classes++;
            $parent = (new ReflectionClass($class))->getParentClass();
            $this->assertFalse($parent !== false && str_starts_with($parent->name, 'ProtoneMedia\\LaravelFFMpeg\\'), $class.' extends '.($parent ? $parent->name : '').'.');
        }
        $this->assertGreaterThan(30, $classes);
        $this->assertTrue((new ReflectionClass(PendingExport::class))->isFinal());
    }
}
