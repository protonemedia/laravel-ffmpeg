<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;

/**
 * Writes the master playlist of an HLS export.
 */
trait WritesHlsMasterPlaylist
{
    /**
     * Get the stream attributes FFmpeg reported for the variant, without its bandwidth.
     */
    protected function streamAttributes(int $index, string $path): string
    {
        $info = $this->isSubtitle($index) ? [] : $this->playlistLines(dirname($path)."/master-{$index}.m3u8");
        $attributes = '';

        foreach ($info as $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $attributes = trim(preg_replace('/(?:^|,)(?:AVERAGE-)?BANDWIDTH=[0-9]+/', '', substr($line, 18)), ',');
            }
        }

        return $attributes;
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
