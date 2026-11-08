<?php

namespace ProtoneMedia\LaravelFFMpeg\Http;

use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;

/** A trusted, persistable inventory. Never construct this from request input. */
class HlsPresentation
{
    private array $files = [];

    public function __construct(array $files, private readonly string $playlist, private readonly array $keys = [])
    {
        foreach ($files as $file) {
            if (! is_string($file['disk'] ?? null) || $file['disk'] === ''
                || ! is_string($file['path'] ?? null) || $file['path'] === ''
                || preg_match('/\A(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $file['path'])
                || (new WhitespacePathNormalizer)->normalizePath($file['path']) !== $file['path']
                || ! in_array($file['role'] ?? null, ['hls-master', 'hls-playlist', 'hls-segment', 'hls-init', 'hls-key'], true)) {
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
        return new self($inventory['files'], $inventory['playlist'], $inventory['keys'] ?? []);
    }

    public function toArray(): array
    {
        return ['files' => array_values($this->files), 'playlist' => $this->playlist, 'keys' => $this->keys];
    }

    public function file(?string $id = null): array
    {
        return $this->files[$id ?? $this->playlist] ?? abort(404);
    }

    public function reference(array $source, ?string $target, string $uri, string $kind): string
    {
        $key = $kind === 'key' ? ($this->keys[$source['path']][$uri] ?? null) : null;
        $id = $key === null ? ($target === null ? null : self::id($source['disk'], $target)) : self::id($key['disk'], $key['path']);
        $file = $id === null ? null : ($this->files[$id] ?? null);
        $roles = match ($kind) {
            'playlist' => ['hls-master', 'hls-playlist'],
            'key' => ['hls-key'],
            default => ['hls-segment', 'hls-init'],
        };
        abort_unless($file !== null && in_array($file['role'], $roles, true), 404);

        return $id;
    }
}
