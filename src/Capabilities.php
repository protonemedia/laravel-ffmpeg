<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** A fresh compiled-in capability snapshot; hardware/device usability is not tested. */
readonly class Capabilities implements Arrayable, JsonSerializable
{
    protected array $data;

    public function __construct()
    {
        $workspace = new Workspace(new ProcessRunner(120));
        try {
            $ffmpeg = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
            $ffprobe = config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe');
            $version = $workspace->runner->pairVersion($ffmpeg, $ffprobe, $workspace->path);
            $data = ['versions' => ['ffmpeg' => $version, 'ffprobe' => $version]];
            foreach (['encoders', 'muxers', 'filters', 'bsfs', 'hwaccels'] as $kind) {
                $output = $this->capture($workspace, $ffmpeg, '-'.$kind);
                $data[match ($kind) {
                    'bsfs' => 'bitstream_filters', 'hwaccels' => 'hardware_accelerators', default => $kind,
                }] = $this->parseList($kind, $output);
            }

            $data['protocols'] = $this->parseProtocols($this->capture($workspace, $ffmpeg, '-protocols'));
            $data['ffprobe_protocols'] = $this->parseProtocols($this->capture($workspace, $ffprobe, '-protocols'));
            // Verify an actual write, not just a permission-bit prediction.
            $file = $workspace->path.'/write-check';
            if (@file_put_contents($file, 'ok') !== 2 || ! @unlink($file)) {
                throw MediaException::staging('The temporary media root is not writable.', 'filesystem');
            }

            $data['temporary_root'] = ['writable' => true];
            $data['hardware'] = ['device_usability_tested' => false,
                'note' => 'Compiled-in support only; device, driver, permissions and runtime availability are not tested.'];
            $this->data = $data;
        } finally {
            $workspace->delete();
        }
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function versions(): array
    {
        return $this->data['versions'];
    }

    /** Categories: encoders, muxers, filters, bitstream_filters, hardware_accelerators,
     * input_protocols, output_protocols, ffprobe_input_protocols, ffprobe_output_protocols.
     *
     * @return list<string>
     */
    public function available(string $category): array
    {
        return match ($category) {
            'encoders', 'muxers', 'filters', 'bitstream_filters', 'hardware_accelerators' => $this->data[$category],
            'input_protocols' => $this->data['protocols']['input'],
            'output_protocols' => $this->data['protocols']['output'],
            'ffprobe_input_protocols' => $this->data['ffprobe_protocols']['input'],
            'ffprobe_output_protocols' => $this->data['ffprobe_protocols']['output'],
            default => throw new InvalidArgumentException('Unknown capability category.'),
        };
    }

    /** True means compiled-in, never proof that an encoder can open a hardware device. */
    public function has(string $category, string $name): bool
    {
        return in_array($name, $this->available($category), true);
    }

    /** @param array<string, list<string>> $requirements */
    public function missing(array $requirements): array
    {
        $missing = [];
        foreach ($requirements as $category => $names) {
            if (! is_string($category)) {
                throw new InvalidArgumentException('Capability requirement categories must be strings.');
            }

            if (! is_array($names) || ! array_is_list($names)) {
                throw new InvalidArgumentException("The capability requirement [{$category}] must be a list of names.");
            }

            if (array_any($names, fn (mixed $name): bool => ! is_string($name) || ! preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/', $name))) {
                throw new InvalidArgumentException("The capability requirement [{$category}] contains an invalid name.");
            }

            $absent = array_values(array_diff($names, $this->available($category)));
            if ($absent !== []) {
                $missing[$category] = $absent;
            }
        }

        return $missing;
    }

    public function require(array $requirements): self
    {
        if ($this->missing($requirements) !== []) {
            throw MediaException::probing('Required compiled-in media capabilities are missing.', 'capability');
        }

        return $this;
    }

    protected function capture(Workspace $workspace, string $binary, string $option): string
    {
        $result = $workspace->runner->run([$binary, '-hide_banner', $option], $workspace->path,
            outputLimit: 2097152, timeout: 15);
        if ($result->exitCode !== 0) {
            // Binary output can contain paths, environment details or credentials.
            throw MediaException::probing('Cannot inspect media binary capabilities.', 'capability');
        }

        return $result->output;
    }

    /** @param 'encoders'|'muxers'|'filters'|'bsfs'|'hwaccels' $kind */
    protected function parseList(string $kind, string $output): array
    {
        $header = match ($kind) {
            'encoders' => 'Encoders:', 'muxers' => 'Formats:', 'filters' => 'Filters:',
            'bsfs' => 'Bitstream filters:', 'hwaccels' => 'Hardware acceleration methods:',
        };
        if (! str_starts_with(ltrim($output), $header)) {
            throw MediaException::probing('Invalid media capability listing.', 'capability');
        }

        $pattern = match ($kind) {
            'encoders' => '/^\s+[VAS][A-Z.]{5}\s+([a-zA-Z0-9_,-]+)\s+[^=]/m',
            'muxers' => '/^\s+E\s+([a-zA-Z0-9_,-]+)\s+/m',
            'filters' => '/^\s+[TSC.]{2,3}\s+([a-zA-Z0-9_]+)\s+\S+\s+/m',
            default => '/^([a-zA-Z0-9_]+)\s*$/m',
        };
        preg_match_all($pattern, $output, $matches);
        $names = [];
        foreach ($matches[1] as $name) {
            array_push($names, ...explode(',', $name));
        }

        $names = array_values(array_unique($names));
        sort($names);
        if ($names === [] && $kind !== 'hwaccels') {
            throw MediaException::probing('Empty media capability listing.', 'capability');
        }

        return $names;
    }

    protected function parseProtocols(string $output): array
    {
        if (! preg_match('/Input:\s*(.*?)\s*Output:\s*(.*)\z/s', $output, $matches)) {
            throw MediaException::probing('Invalid media protocol listing.', 'capability');
        }

        $protocols = [];
        foreach (['input' => 1, 'output' => 2] as $direction => $index) {
            $names = preg_split('/\s+/', trim($matches[$index]), flags: PREG_SPLIT_NO_EMPTY);
            if ($names === [] || array_any($names, fn ($name): bool => ! preg_match('/\A[a-zA-Z0-9_]+\z/', $name))) {
                throw MediaException::probing('Invalid media protocol listing.', 'capability');
            }

            sort($names);
            $protocols[$direction] = array_values(array_unique($names));
        }

        return $protocols;
    }
}
