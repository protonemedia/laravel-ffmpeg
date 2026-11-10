<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;

/**
 * Compiles the watermark and cover art overlays of an export.
 */
trait CompilesOverlays
{
    /**
     * Ensure cover art is combined only with the inputs, outputs, and settings it supports.
     */
    protected function ensureCoverArtCanCompile(?string $graph, array $outputs): void
    {
        if ($this->coverInput === null) {
            return;
        }

        if ($graph !== null) {
            throw new InvalidArgumentException('Cover art does not support a complex filter graph.');
        }

        if ($this->watermarks !== []) {
            throw new InvalidArgumentException('Cover art cannot be combined with watermarks.');
        }

        if (count($this->inputs) !== 2) {
            throw new InvalidArgumentException('Cover art requires exactly one source input.');
        }

        if (count($outputs) !== 1) {
            throw new InvalidArgumentException('Cover art requires exactly one output.');
        }

        if ($this->outputRole !== 'media') {
            throw new InvalidArgumentException("The output role [{$this->outputRole}] does not support cover art.");
        }

        if ($this->twoPass) {
            throw new InvalidArgumentException('Cover art does not support two-pass encoding.');
        }
    }

    /**
     * Ensure watermarks are combined only with the inputs and output roles they support.
     */
    protected function ensureWatermarksCanCompile(?string $graph): void
    {
        if ($this->watermarks === []) {
            return;
        }

        if ($graph !== null) {
            throw new InvalidArgumentException(
                'Watermarks do not support filterComplex(); compose the overlay in the graph instead.',
            );
        }

        if (count($this->inputs) !== count($this->watermarks) + 1) {
            throw new InvalidArgumentException('Watermarks require exactly one source input besides their images.');
        }

        if (! in_array($this->outputRole, ['media', 'hls-playlist'], true)) {
            throw new InvalidArgumentException("The output role [{$this->outputRole}] does not support watermarks.");
        }
    }

    /**
     * Apply the settings that two-pass encoding, cover art, and watermarks require of the output.
     */
    protected function prepareOutput(ExportOutput $output): ExportOutput
    {
        if ($this->twoPass && $output->outputConfiguration()['videoStreams'] !== []) {
            throw new InvalidArgumentException('Per-track video settings do not support two-pass encoding.');
        }

        if ($this->coverInput !== null) {
            $output = $this->coverArtOutput($output);
        }

        if ($this->watermarks !== []) {
            $output = $this->watermarkedOutput($output);
        }

        return $output;
    }

    /**
     * Validate the cover art output and copy its source video, audio, metadata, and chapters.
     */
    protected function coverArtOutput(ExportOutput $output): ExportOutput
    {
        $settings = $output->outputConfiguration();
        if (! in_array(
            strtolower(pathinfo($output->destination()['path'], PATHINFO_EXTENSION)),
            ['mp4', 'm4a', 'mp3'],
            true,
        )) {
            throw new InvalidArgumentException('Cover art requires an MP4, M4A, or MP3 destination.');
        }

        if ($output->hasMaps()) {
            throw new InvalidArgumentException(
                'Cover art outputs select their streams automatically and take no maps.',
            );
        }

        if ($settings['omitVideo'] || $settings['omitAudio']) {
            throw new InvalidArgumentException('Cover art outputs must keep the source video and audio.');
        }

        if ($settings['videoFilters'] !== [] || $settings['audioFilters'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support filters.');
        }

        if ($settings['videoStreams'] !== [] || $settings['audioStreams'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support per-track settings.');
        }

        if ($settings['outputOptions'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support output options.');
        }

        if ($settings['dispositions'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support disposition overrides.');
        }

        if ($settings['clip'] !== null) {
            throw new InvalidArgumentException('Cover art outputs do not support clipping.');
        }

        if (! in_array($settings['video'], [[], ['codec' => 'copy']], true)
            || ! in_array($settings['audio'], [[], ['codec' => 'copy']], true)) {
            throw new InvalidArgumentException('Cover art outputs copy video and audio without encoding settings.');
        }

        $settings['video'] = $settings['audio'] = ['codec' => 'copy'];
        $settings['metadataSource'] ??= 0;
        $settings['chapterSource'] ??= 0;

        return new ExportOutput($output->destination()['path'], $output->filesystem())->configure($settings);
    }

    /**
     * Validate the watermarked output and take its metadata and chapters from the source.
     */
    protected function watermarkedOutput(ExportOutput $output): ExportOutput
    {
        $settings = $output->outputConfiguration();
        if ($output->hasMaps()) {
            throw new InvalidArgumentException(
                'Watermarked outputs select their video automatically and take no maps.',
            );
        }

        if ($settings['omitVideo']) {
            throw new InvalidArgumentException('Watermarked outputs must keep their video.');
        }

        if (($settings['video']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Watermarked outputs must transcode video, not copy it.');
        }

        $settings['metadataSource'] ??= 0;
        $settings['chapterSource'] ??= $settings['clip'] === null ? 0 : -1;

        return new ExportOutput($output->destination()['path'], $output->filesystem())->configure($settings);
    }

    /**
     * Route the output's main video through the watermark overlays instead of its own filter.
     *
     * @return array{0: list<string>, 1: list<string>} The rewritten output options and the graph branches to add.
     */
    protected function compileWatermarks(array $options, int|string $index): array
    {
        $filter = 'null';
        $processed = [];
        for ($i = 0; $i < count($options); $i++) {
            // The per-track chain of the one mapped video stream already includes the
            // output filters and comes after them, so the last chain we see wins.
            if ($options[$i] === '-filter:v' || $options[$i] === '-filter:v:0') {
                $filter = $options[++$i];
            } elseif ($options[$i] === '-map' && str_starts_with($options[$i + 1], '0:V:0')) {
                $processed[] = '-map';
                $processed[] = "[watermark_out{$index}]";
                $i++;
            } else {
                $processed[] = $options[$i];
            }
        }

        return [$processed, $this->overlayWatermarks($filter, $index)];
    }

    /**
     * Build the graph branches that filter the main video and overlay each watermark on it.
     *
     * @return list<string>
     */
    protected function overlayWatermarks(string $filter, int|string $index): array
    {
        $branches = ["[0:V:0]{$filter}[watermark_base{$index}_0]"];
        foreach ($this->watermarks as $n => $mark) {
            [
                'input' => $input,
                'horizontal' => [$left, $x],
                'vertical' => [$top, $y],
                'width' => $width,
                'height' => $height,
                'opacity' => $opacity,
                'loop' => $loop
            ] = $mark;
            $imageFilters = $width === null && $height === null
                ? ''
                : 'scale=w='.($width ?? -1).':h='.($height ?? -1).',';
            $label = "watermark_image{$index}_{$n}";
            $branches[] = "[{$input}:v:0]{$imageFilters}format=rgba,colorchannelmixer=aa={$opacity}[{$label}]";
            $horizontal = match ($left) {
                'right' => "main_w-overlay_w-{$x}", 'center' => '(main_w-overlay_w)/2', default => (string) $x,
            };
            $vertical = match ($top) {
                'bottom' => "main_h-overlay_h-{$y}", 'center' => '(main_h-overlay_h)/2', default => (string) $y,
            };
            $out = $n === count($this->watermarks) - 1 ? "watermark_out{$index}" : "watermark_base{$index}_".($n + 1);
            // A looping mark never ends, so we will let the main video bound it. Without
            // a loop, every stream keeps its own length.
            $shortest = $loop ? 1 : 0;
            $branches[] = "[watermark_base{$index}_{$n}][{$label}]overlay=x={$horizontal}:y={$vertical}"
                .":eof_action=repeat:repeatlast=1:shortest={$shortest}[{$out}]";
        }

        return $branches;
    }

    /**
     * Validate the cover art and watermark inputs and resolve the facts command() needs for them.
     */
    protected function prepareOverlays(array $metadata): void
    {
        if ($this->coverInput !== null) {
            $image = $metadata[$this->coverInput];
            if (! in_array($image->get('format.format_name'), ['png_pipe', 'jpeg_pipe'], true)
                || $image->streams()->count() !== 1) {
                throw MediaException::probing('Cover art requires a standalone PNG or JPEG image.', 'cover-art');
            }

            if ($metadata[0]->streams()->contains(
                fn ($stream): bool => (int) $stream->get('disposition.attached_pic') === 1,
            )) {
                throw MediaException::probing('Cover art does not replace existing attached pictures.', 'cover-art');
            }

            if ($metadata[0]->streams()->contains(
                fn ($stream): bool => ! in_array($stream->get('codec_type'), ['video', 'audio'], true),
            )) {
                throw MediaException::probing(
                    'Cover art accepts sources containing only motion video and audio.',
                    'cover-art',
                );
            }

            $this->coverVideoTrack = $metadata[0]->streams()
                ->filter(fn ($stream): bool => $stream->get('codec_type') === 'video')
                ->count();
        }

        foreach ($this->watermarks as $mark) {
            $image = $metadata[$mark['input']];
            $gif = $image->get('format.format_name') === 'gif' && $image->video()?->get('codec_name') === 'gif';
            if ((! $gif && ! in_array($image->get('format.format_name'), ['png_pipe', 'jpeg_pipe'], true))
                || $image->streams()->count() !== 1) {
                throw MediaException::probing('Watermarks require a standalone PNG, JPEG, or GIF image.', 'watermark');
            }

            if (! $gif && $mark['loop']) {
                throw MediaException::probing('Only GIF watermarks can loop().', 'watermark');
            }
        }
    }
}
