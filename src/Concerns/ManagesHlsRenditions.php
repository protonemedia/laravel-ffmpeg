<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

/**
 * Manages the variants and alternate renditions of an HLS export.
 */
trait ManagesHlsRenditions
{
    /**
     * Define a variant of the master playlist.
     *
     * @param  callable(HlsVariant): mixed  $configure
     */
    public function variant(
        string $name,
        callable $configure,
        ?string $playlist = null,
        ?string $segments = null,
        ?string $init = null,
    ): static {
        $this->export->assertOutputMutable();

        $this->ensureSimpleName($name, 'HLS variant name');

        if (isset($this->variants[$name])) {
            throw new InvalidArgumentException("The HLS variant [{$name}] is already defined.");
        }

        $paths = ['playlist' => $playlist, 'segments' => $segments, 'init' => $init];

        foreach ($paths as $type => $path) {
            if ($path === null) {
                continue;
            }

            $path = new ExportOutput($path, $this->export->filesystem())->destination()['path'];

            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), match ($type) {
                'playlist' => ['m3u8'], 'init' => ['mp4'], default => ['ts', 'm4s', 'vtt'],
            }, true)) {
                throw new InvalidArgumentException(match ($type) {
                    'playlist' => 'The HLS variant playlist must have an m3u8 extension.',
                    'init' => 'The HLS initialization file must have an mp4 extension.',
                    default => 'The HLS segment path must have a ts, m4s, or vtt extension.',
                });
            }

            if ($type === 'segments'
                && (! preg_match('/\A[^%]*%(?:0[1-9])?d[^%]*\z/', $path) || str_contains(dirname($path), '%'))) {
                throw new InvalidArgumentException(
                    'The HLS segment filename must contain one %d or %01d through %09d placeholder.'
                );
            }

            $paths[$type] = $path;
        }

        $variant = new HlsVariant;

        $configure($variant);

        $this->variants[$name] = $variant;
        $this->variantPaths[$name] = $paths;

        return $this;
    }

    /**
     * Define an alternate audio rendition.
     *
     * The rendition is encoded once and any number of variants may reference it through HlsVariant::audioGroup().
     */
    public function audioRendition(
        string $group,
        string $name,
        callable $configure,
        ?string $language = null,
        bool $default = false,
        bool $autoselect = true,
        ?string $channels = null,
        ?string $playlist = null,
        ?string $segments = null,
        ?string $init = null,
    ): static {
        return $this->rendition(
            'AUDIO',
            $group,
            $name,
            $configure,
            $language,
            $default,
            $autoselect,
            false,
            $channels,
            $playlist,
            $segments,
            $init,
        );
    }

    /**
     * Define an alternate subtitle rendition as one WebVTT segment for the whole presentation.
     *
     * The subtitle stream is selected with an explicit subtitle map.
     */
    public function subtitleRendition(
        string $group,
        string $name,
        callable $configure,
        ?string $language = null,
        bool $default = false,
        bool $autoselect = true,
        bool $forced = false,
        ?string $playlist = null,
        ?string $segments = null,
    ): static {
        return $this->rendition(
            'SUBTITLES',
            $group,
            $name,
            $configure,
            $language,
            $default,
            $autoselect,
            $forced,
            null,
            $playlist,
            $segments,
            null,
        );
    }

    /**
     * Define an alternate rendition of the given type as a variant with its own name.
     */
    protected function rendition(
        string $type,
        string $group,
        string $name,
        callable $configure,
        ?string $language,
        bool $default,
        bool $autoselect,
        bool $forced,
        ?string $channels,
        ?string $playlist,
        ?string $segments,
        ?string $init,
    ): static {
        $this->export->assertOutputMutable();
        $this->ensureSimpleName($group, 'HLS rendition group');
        $this->ensureRenditionNameIsValid($name);
        $this->ensureRenditionAttributesAreValid($language, $channels, $default, $autoselect);
        $this->ensureRenditionIsUnique($type, $group, $name, $default);

        $id = 'rendition-'.strtolower($type)."-{$group}-".count($this->renditions);

        $this->variant($id, $configure, $playlist, $segments, $init);

        $this->renditions[$id] = [
            'type' => $type,
            'group' => $group,
            'name' => $name,
            'language' => $language,
            'default' => $default,
            'autoselect' => $autoselect,
            'forced' => $forced,
            'channels' => $channels,
        ];

        return $this;
    }

    /**
     * Ensure the given rendition name can be quoted in a playlist tag.
     */
    protected function ensureRenditionNameIsValid(string $name): void
    {
        if ($name === '' || ! preg_match('//u', $name) || preg_match('/["\\\\\x00-\x1F\x7F-\x9F]/u', $name)) {
            throw new InvalidArgumentException(
                'The HLS rendition name must be non-empty UTF-8 text without quotes, backslashes, or control characters.'
            );
        }
    }

    /**
     * Ensure the language, channels and selection flags of a rendition are valid.
     */
    protected function ensureRenditionAttributesAreValid(
        ?string $language,
        ?string $channels,
        bool $default,
        bool $autoselect,
    ): void {
        if ($language !== null && ! preg_match('/\A[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*\z/', $language)) {
            throw new InvalidArgumentException('The HLS rendition language must be a language tag.');
        }

        if ($channels !== null && ! preg_match('/\A[1-9][0-9]*(?:\/[A-Z0-9]+)?\z/', $channels)) {
            throw new InvalidArgumentException(
                'The HLS rendition channels must be a positive channel count with an optional parameter list.'
            );
        }

        if ($default && ! $autoselect) {
            throw new InvalidArgumentException('A default HLS rendition must also be autoselected.');
        }
    }

    /**
     * Ensure the group has no rendition with the given name and at most one default rendition.
     */
    protected function ensureRenditionIsUnique(string $type, string $group, string $name, bool $default): void
    {
        foreach ($this->renditions as $rendition) {
            if ($rendition['type'] !== $type || $rendition['group'] !== $group) {
                continue;
            }

            if ($rendition['name'] === $name) {
                throw new InvalidArgumentException(
                    "The HLS rendition group [{$group}] already has a rendition with this name."
                );
            }

            if ($default && $rendition['default']) {
                throw new InvalidArgumentException(
                    "The HLS rendition group [{$group}] already has a default rendition."
                );
            }
        }
    }

    /**
     * Ensure the given name is a simple path component that cannot traverse directories.
     */
    protected function ensureSimpleName(string $name, string $subject): void
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $name)) {
            throw new InvalidArgumentException("The {$subject} must be a simple path component.");
        }
    }

    /**
     * Configure the playlist output of the given variant or alternate rendition.
     */
    protected function variantOutput(int|string $name, HlsVariant $variant, string $directory): ExportOutput
    {
        $rendition = $this->renditions[$name] ?? null;
        $subtitle = ($rendition['type'] ?? null) === 'SUBTITLES';

        $this->ensureVariantPathsMatchPackaging($name, $subtitle);

        $settings = $variant->inherit($this->export->outputConfiguration());
        $settings = $rendition === null
            ? $this->variantSettings($variant, $settings)
            : $this->renditionSettings($variant, $settings, $subtitle);

        return new ExportOutput(
            $this->variantPaths[$name]['playlist'] ?? "{$directory}{$name}/index.m3u8",
            $this->export->filesystem(),
        )->configure($settings);
    }

    /**
     * Ensure the custom segment and initialization paths match the selected packaging.
     */
    protected function ensureVariantPathsMatchPackaging(int|string $name, bool $subtitle): void
    {
        $segments = $this->variantPaths[$name]['segments'];
        $extension = $this->segmentExtension($subtitle);

        if ($segments !== null && strtolower(pathinfo($segments, PATHINFO_EXTENSION)) !== $extension) {
            throw new InvalidArgumentException(
                "The HLS segment path must have the [{$extension}] extension of the selected packaging."
            );
        }

        if ($this->variantPaths[$name]['init'] !== null && $this->segmentType !== 'fmp4') {
            throw new InvalidArgumentException('The HLS initialization file requires fMP4 packaging.');
        }
    }

    /**
     * Restrict the output settings of an alternate rendition to its own stream.
     */
    protected function renditionSettings(HlsVariant $variant, array $settings, bool $subtitle): array
    {
        if ($settings['maps'] === []) {
            throw new InvalidArgumentException('An HLS alternate rendition requires an explicit map.');
        }

        if (array_any($variant->groups(), fn ($group): bool => $group !== null)) {
            throw new InvalidArgumentException('An HLS alternate rendition cannot reference rendition groups.');
        }

        $settings['omitVideo'] = false;
        $settings['omitAudio'] = false;
        $settings['video'] = [];
        $settings['videoFilters'] = [];

        if ($subtitle) {
            $settings['audio'] = [];
            $settings['audioFilters'] = [];
        }

        return $settings;
    }

    /**
     * Validate the variant's rendition groups and drop audio they replace.
     */
    protected function variantSettings(HlsVariant $variant, array $settings): array
    {
        foreach ($variant->groups() as $type => $group) {
            if ($group !== null && ! array_any(
                $this->renditions,
                fn ($item): bool => $item['type'] === $type && $item['group'] === $group,
            )) {
                throw new InvalidArgumentException("The HLS rendition group [{$group}] is not defined.");
            }
        }

        if ($variant->groups()['AUDIO'] !== null) {
            $settings['omitAudio'] = $settings['maps'] === [];
            $settings['audio'] = [];
            $settings['audioFilters'] = [];
        }

        return $settings;
    }

    /**
     * Ensure every variant and the renditions it references share one clip.
     *
     * @param  list<ExportOutput>  $outputs
     */
    protected function ensureRenditionClipsMatch(array $outputs): void
    {
        foreach ($this->variants as $name => $variant) {
            if (isset($this->renditions[$name])) {
                continue;
            }

            foreach ($variant->groups() as $type => $group) {
                foreach ($this->renditions as $id => $rendition) {
                    if ($group !== null && $rendition['type'] === $type && $rendition['group'] === $group
                        && $outputs[array_search($name, array_keys($this->variants), true)]->clipSettings()
                            !== $outputs[array_search($id, array_keys($this->variants), true)]->clipSettings()) {
                        throw new InvalidArgumentException(
                            'Variants and their reusable renditions require the same clip.'
                        );
                    }
                }
            }
        }
    }
}
