<?php

namespace ProtoneMedia\LaravelFFMpeg\Http;

use Illuminate\Support\ItemNotFoundException;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use ProtoneMedia\LaravelFFMpeg\Exceptions\HlsResourceNotFoundException;
use Throwable;

/** A trusted, persistable inventory. Never construct this from request input. */
class HlsPresentation
{
    protected array $files = [];

    public function __construct(array $files, protected readonly string $playlist, protected readonly array $keys = [], protected readonly array $variants = [], protected readonly bool $selected = false, protected readonly array $provenance = [])
    {
        foreach ($files as $file) {
            try {
                $canonical = is_string($file['path'] ?? null) ? (new WhitespacePathNormalizer)->normalizePath($file['path']) : null;
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('HLS playback requires canonical inventory paths.', $exception->getCode(), previous: $exception);
            }

            if (! is_string($file['disk'] ?? null) || $file['disk'] === ''
                || ! is_string($file['path'] ?? null) || $file['path'] === ''
                || preg_match('/\A(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $file['path'])
                || $canonical !== $file['path']
                || ! in_array($file['role'] ?? null, ['hls-master', 'hls-playlist', 'hls-segment', 'hls-init', 'hls-key', 'dash-init', 'dash-segment'], true)) {
                throw new InvalidArgumentException('HLS playback requires named disks and canonical HLS inventory paths.');
            }

            $id = self::id($file['disk'], $file['path']);
            if (isset($this->files[$id])) {
                throw new InvalidArgumentException('HLS playback inventory contains a duplicate destination.');
            }

            $this->files[$id] = array_intersect_key($file, array_flip(['disk', 'path', 'role']));
        }

        if (! isset($this->files[$playlist]) || ! in_array($this->files[$playlist]['role'], ['hls-master', 'hls-playlist'], true)) {
            throw new InvalidArgumentException('HLS playback requires an inventory-owned entry playlist.');
        }
    }

    public static function id(string $disk, string $path): string
    {
        return hash('sha256', $disk."\0".$path);
    }

    public static function fromArray(array $inventory): self
    {
        return new self($inventory['files'], $inventory['playlist'], $inventory['keys'] ?? [], $inventory['variants'] ?? [], $inventory['selected'] ?? false, $inventory['provenance'] ?? []);
    }

    public function toArray(): array
    {
        return ['files' => array_values($this->files), 'playlist' => $this->playlist, 'keys' => $this->keys,
            'variants' => $this->variants, 'selected' => $this->selected, 'provenance' => $this->provenance];
    }

    public function playbackForVariants(array $names): self
    {
        if ($names === [] || count(array_unique($names, SORT_REGULAR)) !== count($names)) {
            throw new InvalidArgumentException('Select a nonempty set of unique HLS variants.');
        }

        $ids = [$this->playlist => true];
        $variants = [];
        foreach ($names as $name) {
            if (! is_string($name) || ! isset($this->variants[$name])) {
                throw new ItemNotFoundException('Unknown HLS variant.');
            }

            $variants[$name] = $this->variants[$name];
            $ids += array_fill_keys($variants[$name], true);
        }

        $files = array_intersect_key($this->files, $ids);
        $keys = array_intersect_key($this->keys, array_flip(array_column($files, 'path')));
        foreach ($keys as $references) {
            foreach ($references as $key) {
                $id = self::id($key['disk'], $key['path']);
                if (isset($this->files[$id])) {
                    $files[$id] = $this->files[$id];
                }
            }
        }

        return new self(array_values($files), $this->playlist, $keys, $variants, true,
            array_intersect_key($this->provenance, array_flip(array_column($files, 'path'))));
    }

    /** Render only inventory-owned references; selection removes whole master declarations. */
    public function render(array $file, callable $url): string
    {
        $playlist = new HlsPlaylist($file['disk'], $file['path']);
        $excluded = 'hls-excluded-'.bin2hex(random_bytes(16));
        foreach (['playlist', 'media', 'key'] as $kind) {
            $playlist->{$kind.'UrlsUsing'}(function ($target, $source, $uri) use ($file, $kind, $url, $excluded) {
                if ($this->selected && $file['role'] === 'hls-master' && $kind === 'playlist'
                    && ($target === null || ! isset($this->files[self::id($file['disk'], $target)]))) {
                    return $excluded;
                }

                return $url($this->reference($file, $target, $uri, $kind));
            });
        }

        $lines = explode("\n", $playlist->get());
        foreach ($lines as $index => $line) {
            if ($line === $excluded) {
                unset($lines[$index]);
                for ($previous = $index - 1; $previous >= 0; $previous--) {
                    if (str_starts_with($lines[$previous] ?? '', '#EXT-X-STREAM-INF:')) {
                        unset($lines[$previous]);
                        break;
                    }
                }
            } elseif (str_starts_with($line, '#EXT-X-MEDIA:') && str_contains($line, 'URI="'.$excluded.'"')) {
                unset($lines[$index]);
            }
        }

        return implode("\n", $lines);
    }

    /** @throws HlsResourceNotFoundException when the inventory does not own the resource */
    public function file(?string $id = null): array
    {
        return $this->files[$id ?? $this->playlist] ?? throw new HlsResourceNotFoundException('Unknown HLS resource.');
    }

    /** @throws HlsResourceNotFoundException when the reference is not an owned resource of this kind */
    public function reference(array $source, ?string $target, string $uri, string $kind): string
    {
        $key = $kind === 'key' ? ($this->keys[$source['path']][$uri] ?? null) : null;
        $id = $key === null ? ($target === null ? null : self::id($source['disk'], $target)) : self::id($key['disk'], $key['path']);
        $file = $id === null ? null : ($this->files[$id] ?? null);
        $roles = match ($kind) {
            'playlist' => ['hls-master', 'hls-playlist'],
            'key' => ['hls-key'],
            // DASH sharedHls() playlists reference the DASH initialization and media files.
            default => ['hls-segment', 'hls-init', 'dash-segment', 'dash-init'],
        };
        if ($file === null || ! in_array($file['role'], $roles, true)) {
            throw new HlsResourceNotFoundException('The HLS reference is not an owned '.$kind.' resource.');
        }

        return $id;
    }

    /** @internal The URL-encoded path from a playlist's directory to a target key on the same disk. */
    public static function relativeUrl(string $target, string $playlist): string
    {
        $from = dirname($playlist) === '.' ? [] : explode('/', dirname($playlist));
        $to = explode('/', $target);
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...array_map(rawurlencode(...), $to)]);
    }
}
