<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Progress;

/**
 * Verifies the pass statistics, artifacts and destinations of an export.
 */
trait VerifiesArtifacts
{
    /**
     * Ensure the first encoding pass wrote statistics for every two-pass output.
     */
    protected function ensurePassStatisticsExist(Workspace $workspace, array $outputs): void
    {
        foreach ($this->twoPassOutputIndexes($outputs) as $index) {
            $logs = glob("{$workspace->path}/pass-{$index}-*.log") ?: [];

            if (! array_any($logs, fn (string $log): bool => is_file($log) && filesize($log) > 0)) {
                throw new MediaException(
                    "The first encoding pass did not produce statistics for output {$index}.",
                    $this->phase,
                    'artifact',
                );
            }
        }
    }

    /**
     * Validate every artifact, probing its streams when the output needs it.
     *
     * @return list<int> The size in bytes of each artifact.
     */
    protected function validateArtifacts(Workspace $workspace, array $artifacts, array $outputs, array $expected): array
    {
        $sizes = [];

        foreach ($artifacts as $artifact) {
            $workspace->runner->check(Progress::VALIDATION);
            $outputPath = $artifact['path'];
            $index = $artifact['output'];
            $role = $artifact['role'] ?? $this->outputRole;
            $size = $this->validateArtifact($outputPath, $role);
            $settings = $outputs[$index]->outputConfiguration();
            $configuredTracks = $settings['videoStreams'] !== [] || $settings['audioStreams'] !== [];

            if (($artifact['probe'] ?? false)
                || (in_array($role, ['media', 'image'], true)
                    && ($configuredTracks
                        || count($outputs) > 1
                        || count($this->inputs) > 1
                        || $this->filterGraph() !== null))) {
                $this->ensureArtifactStreamsMatch(
                    $workspace,
                    $artifact['probePath'] ?? $outputPath,
                    $expected[$index],
                    $settings,
                );
            }

            $sizes[] = $size;
        }

        return $sizes;
    }

    /**
     * Ensure the encoded streams match the output's maps and per-track settings.
     */
    protected function ensureArtifactStreamsMatch(
        Workspace $workspace,
        string $path,
        array $expected,
        array $settings,
    ): void {
        try {
            $actual = $this->media->probeArtifact($path, $workspace)
                ->streams()
                ->map(fn ($stream) => $stream->get('codec_type'))
                ->all();
        } catch (MediaException $mediaException) {
            if ($mediaException->category === 'timeout') {
                throw $mediaException;
            }

            throw new MediaException(
                'Cannot validate the encoded media.',
                $this->phase,
                'artifact',
                $mediaException->diagnostics,
            );
        }

        if (count($actual) !== count($expected)
            || array_any(
                array_keys($actual),
                fn (int $i): bool => $expected[$i] !== null && $actual[$i] !== $expected[$i],
            )) {
            throw new MediaException('Encoded streams do not match the explicit output maps.', $this->phase, 'mapping');
        }

        foreach (['video' => 'videoStreams', 'audio' => 'audioStreams'] as $type => $key) {
            if ($settings[$key] !== []
                && max(array_keys($settings[$key])) >= collect($actual)
                    ->filter(fn ($selected): bool => $selected === $type)
                    ->count()) {
                throw new MediaException(
                    ucfirst($type)." track settings require existing encoded output {$type} tracks.",
                    $this->phase,
                    'mapping',
                );
            }
        }
    }

    /**
     * Read the bytes of the validated generated file.
     */
    protected function readGeneratedFile(string $path): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new MediaException('Cannot read the generated image.', $this->phase, 'artifact');
        }

        return $bytes;
    }

    /**
     * Validate the given generated file and get its size in bytes.
     *
     * Packaging operations also call this for the files they inventory.
     *
     * @internal
     */
    public function validateArtifact(string $path, string $role = 'media'): int
    {
        clearstatcache(true, $path);

        if (! is_file($path) || ($size = filesize($path)) === false || $size === 0) {
            throw MediaException::validation('FFmpeg did not produce a nonempty media file.', 'artifact');
        }

        if ($role === 'hls-key' && $size !== 16) {
            throw MediaException::validation('An AES-128 HLS key must contain exactly 16 bytes.', 'artifact');
        }

        if ($this->operation instanceof ValidatesArtifacts) {
            $this->operation->artifact($path, $role, $size);
        }

        return $size;
    }

    /**
     * Ensure no two outputs share a destination and no composition output overwrites an input.
     *
     * @internal
     */
    public function validateDestinations(array $outputs): void
    {
        $seen = [];

        foreach ($outputs as $output) {
            $disk = $output->filesystem();
            $path = $output->destination()['path'];
            $identity = (is_string($disk) ? 'disk:'.$disk : 'object:'.spl_object_id($disk)).':'.$path;

            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('Outputs cannot share a destination.');
            }

            $seen[$identity] = true;

            if (count($outputs) > 1 || count($this->inputs) > 1) {
                foreach ($this->inputs as $input) {
                    if (new Media($disk, $path, $input->inputOptions())->identity() === $input->identity()) {
                        throw new InvalidArgumentException('Composition outputs cannot overwrite an input.');
                    }
                }
            }
        }
    }
}
