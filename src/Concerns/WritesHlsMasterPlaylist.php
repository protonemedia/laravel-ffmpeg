<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Stream;

/**
 * Writes the master playlist of an HLS export.
 */
trait WritesHlsMasterPlaylist
{
    /**
     * Get the stream attributes of the variant, without its bandwidth.
     */
    protected function streamAttributes(int $index, string $path, ?Stream $video): string
    {
        $info = $this->isSubtitle($index) ? [] : $this->playlistLines(dirname($path)."/master-{$index}.m3u8");
        $attributes = '';

        foreach ($info as $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $attributes = trim(preg_replace('/(?:^|,)(?:AVERAGE-)?BANDWIDTH=[0-9]+/', '', substr($line, 18)), ',');
            }
        }

        if (! $video instanceof Stream) {
            return $attributes;
        }

        return $this->withVideoAttributes($attributes, $video);
    }

    /**
     * Replace the coded RESOLUTION with the display size and add the FRAME-RATE of the given video.
     */
    protected function withVideoAttributes(string $attributes, Stream $video): string
    {
        $resolution = $this->displayResolution($video);

        if ($resolution !== null) {
            $attributes = preg_replace('/(?<=^|,)RESOLUTION=[0-9]+x[0-9]+/', "RESOLUTION={$resolution}", $attributes);
        }

        $frameRate = $video->frameRate();

        if ($frameRate === null) {
            return $attributes;
        }

        return $attributes.($attributes === '' ? '' : ',').'FRAME-RATE='.sprintf('%.3f', $frameRate);
    }

    /**
     * Get the size the given video displays at, with its pixels stretched to square ones.
     */
    protected function displayResolution(Stream $video): ?string
    {
        $width = $video->width();
        $height = $video->height();

        if ($width === null || $height === null) {
            return null;
        }

        if (! preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/', (string) $video->get('sample_aspect_ratio'), $ratio)) {
            return "{$width}x{$height}";
        }

        $horizontal = (int) $ratio[1];
        $vertical = (int) $ratio[2];

        // Players stretch anamorphic pixels along a single axis, widening wide pixels and
        // heightening narrow ones, so the display size never drops any coded pixels.
        if ($horizontal >= $vertical) {
            $width = (int) round($width * $horizontal / $vertical);
        } else {
            $height = (int) round($height * $vertical / $horizontal);
        }

        return "{$width}x{$height}";
    }

    /**
     * Build the master playlist lines, timestamping subtitle renditions along the way.
     *
     * @param  array<int|string, array<string, mixed>>  $streamInfo
     * @return list<string>
     */
    protected function masterPlaylistLines(array $streamInfo, string $masterPath): array
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:'.($this->segmentType === 'fmp4' ? '7' : '3')];

        foreach ($this->renditions as $name => $rendition) {
            if ($rendition['type'] === 'SUBTITLES') {
                $overhead = $this->timestampSubtitleRendition($name, $rendition['group'], $streamInfo);
                $streamInfo[$name]['peak'] += $overhead;
                $streamInfo[$name]['average'] += $overhead;
            }

            $lines[] = $this->mediaTag($rendition)
                .',URI="'.HlsPresentation::relativeUrl($streamInfo[$name]['path'], $masterPath).'"';
        }

        foreach ($this->variants as $name => $variant) {
            if (isset($this->renditions[$name])) {
                continue;
            }

            array_push($lines, ...$this->streamLines($name, $variant, $streamInfo, $masterPath));
        }

        return $lines;
    }

    /**
     * Build the EXT-X-MEDIA tag of an alternate rendition, without its URI.
     */
    protected function mediaTag(array $rendition): string
    {
        $tag = "#EXT-X-MEDIA:TYPE={$rendition['type']},GROUP-ID=\"{$rendition['group']}\""
            .",NAME=\"{$rendition['name']}\""
            .',DEFAULT='.($rendition['default'] ? 'YES' : 'NO')
            .',AUTOSELECT='.($rendition['autoselect'] ? 'YES' : 'NO');

        if ($rendition['language'] !== null) {
            $tag .= ",LANGUAGE=\"{$rendition['language']}\"";
        }

        if ($rendition['type'] === 'SUBTITLES') {
            $tag .= ',FORCED='.($rendition['forced'] ? 'YES' : 'NO');
        } elseif ($rendition['channels'] !== null) {
            $tag .= ",CHANNELS=\"{$rendition['channels']}\"";
        }

        return $tag;
    }

    /**
     * Build the EXT-X-STREAM-INF tag and URI of a variant, including its rendition groups.
     *
     * @param  array<int|string, array<string, mixed>>  $streamInfo
     * @return array{0: string, 1: string}
     */
    protected function streamLines(int|string $name, HlsVariant $variant, array $streamInfo, string $masterPath): array
    {
        $info = $streamInfo[$name];
        $groups = '';

        foreach ($variant->groups() as $type => $group) {
            if ($group === null) {
                continue;
            }

            $groups .= ",{$type}=\"{$group}\"";
            $members = collect($this->renditions)
                ->filter(fn (array $item): bool => $item['type'] === $type && $item['group'] === $group)
                ->keys()
                ->all();
            $info['peak'] += max(array_map(fn (int|string $member): int => $streamInfo[$member]['peak'], $members));
            $info['average'] += max(array_map(
                fn (int|string $member): int => $streamInfo[$member]['average'],
                $members,
            ));

            if ($type === 'AUDIO') {
                $info['attributes'] = $this->withAudioCodecs($info['attributes'], [$name, ...$members], $streamInfo);
            }
        }

        return [
            "#EXT-X-STREAM-INF:BANDWIDTH={$info['peak']},AVERAGE-BANDWIDTH={$info['average']}"
                .($info['attributes'] === '' ? '' : ",{$info['attributes']}").$groups,
            HlsPresentation::relativeUrl($info['path'], $masterPath),
        ];
    }

    /**
     * Replace the CODECS attribute with the codecs of the variant and its audio renditions.
     *
     * @param  list<int|string>  $members
     * @param  array<int|string, array<string, mixed>>  $streamInfo
     */
    protected function withAudioCodecs(string $attributes, array $members, array $streamInfo): string
    {
        $codecs = [];

        foreach ($members as $member) {
            if (preg_match('/(?:^|,)CODECS="([^"]+)"/', $streamInfo[$member]['attributes'], $match)) {
                array_push($codecs, ...explode(',', $match[1]));
            }
        }

        $attributes = trim(preg_replace('/(?:^|,)CODECS="[^"]+"/', '', $attributes), ',');

        if ($codecs !== []) {
            $attributes .= ($attributes === '' ? '' : ',').'CODECS="'.implode(',', array_unique($codecs)).'"';
        }

        return $attributes;
    }
}
