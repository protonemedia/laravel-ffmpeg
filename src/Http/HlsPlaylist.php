<?php

namespace ProtoneMedia\LaravelFFMpeg\Http;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HlsPlaylist implements Responsable
{
    use Conditionable;

    private string $path;

    private array $resolvers = [];

    public function __construct(private readonly string|Filesystem $disk, string $path)
    {
        if ($path === '' || preg_match('/\A(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $path)) {
            throw new InvalidArgumentException('A playlist requires a disk-relative m3u8 key.');
        }
        $this->path = (new WhitespacePathNormalizer)->normalizePath($path);
        if (strtolower(pathinfo($this->path, PATHINFO_EXTENSION)) !== 'm3u8') {
            throw new InvalidArgumentException('A playlist requires an m3u8 extension.');
        }
    }

    public function keyUrlsUsing(callable $resolver): self
    {
        $this->resolvers['key'] = Closure::fromCallable($resolver);

        return $this;
    }

    public function mediaUrlsUsing(callable $resolver): self
    {
        $this->resolvers['media'] = Closure::fromCallable($resolver);

        return $this;
    }

    public function playlistUrlsUsing(callable $resolver): self
    {
        $this->resolvers['playlist'] = Closure::fromCallable($resolver);

        return $this;
    }

    public function get(): string
    {
        return $this->render(false)[$this->path];
    }

    /** @return Collection<string, string> Local playlists only, without fetching keys or media. */
    public function all(): Collection
    {
        return collect($this->render(true));
    }

    public function toResponse($request): Response
    {
        return response($this->get(), 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function render(bool $all): array
    {
        $pending = [$this->path];
        $queued = [$this->path => true];
        $result = $cache = [];
        $resolvers = $this->resolvers;
        $inputBudget = $outputBudget = 16777216;
        for ($index = 0; $index < count($pending); $index++) {
            $path = $pending[$index];
            $content = $this->read($path, $inputBudget);
            $inputBudget -= strlen($content);
            $lines = explode("\n", str_replace("\r\n", "\n", $content));
            $type = null;
            foreach ($lines as $lineIndex => &$line) {
                $kind = null;
                $offset = 0;
                $length = strlen($line);
                if (str_starts_with($line, '#EXT-X-STREAM-INF:') || str_starts_with($line, '#EXTINF:')) {
                    if ($type !== null) {
                        throw new MediaException('An HLS resource declaration has no URI.', 'response', 'artifact');
                    }
                    $type = str_starts_with($line, '#EXTINF:') ? 'media' : 'playlist';
                } elseif (str_starts_with($line, '#EXT-X-KEY:')) {
                    $key = $this->keyUri($line);
                    if ($key !== null) {
                        [$offset, $length] = $key;
                        $kind = 'key';
                    }
                } elseif (str_starts_with($line, '#EXT-X-MAP:') || str_starts_with($line, '#EXT-X-MEDIA:')) {
                    $map = str_starts_with($line, '#EXT-X-MAP:');
                    $resource = $this->resourceUri($line, $map);
                    if ($resource !== null) {
                        [$offset, $length] = $resource;
                        $kind = $map ? 'media' : 'playlist';
                    }
                } elseif ($line !== '' && $line[0] !== '#') {
                    if ($type === null) {
                        throw new MediaException('An HLS URI has no resource declaration.', 'response', 'artifact');
                    }
                    $kind = $type;
                    $type = null;
                } elseif (preg_match('/\A#EXT-X-(?:I-FRAME-STREAM-INF|SESSION-KEY|PART|PRELOAD-HINT|RENDITION-REPORT):/', $line)
                    || (str_starts_with($line, '#EXT') && preg_match('/[:,]URI\s*=/', $line))) {
                    throw new MediaException('This response does not support this HLS resource tag.', 'response', 'artifact');
                }
                if ($kind !== null) {
                    $uri = substr($line, $offset, $length);
                    $this->validateUri($uri);
                    $target = null;
                    if (! str_starts_with($uri, '/') && parse_url($uri, PHP_URL_SCHEME) === null) {
                        try {
                            $decoded = rawurldecode(parse_url($uri, PHP_URL_PATH) ?? '');
                            if ($decoded === '' || ! preg_match('//u', $decoded) || str_contains($decoded, '\\')) {
                                throw new InvalidArgumentException;
                            }
                            $target = (new WhitespacePathNormalizer)->normalizePath((dirname($path) === '.' ? '' : dirname($path).'/').$decoded);
                            if ($target === '') {
                                throw new InvalidArgumentException;
                            }
                        } catch (Throwable) {
                            throw new MediaException('An HLS reference escapes the disk or has an invalid path.', 'response', 'artifact');
                        }
                    }
                    if ($all && $kind === 'playlist' && $target !== null && ! isset($queued[$target])) {
                        if (count($pending) >= 1024) {
                            throw new MediaException('An HLS response cannot read more than 1024 local playlists.', 'response', 'artifact');
                        }
                        $queued[$target] = true;
                        $pending[] = $target;
                    }
                    if (isset($resolvers[$kind])) {
                        if (! isset($cache[$kind][$path][$uri])) {
                            $url = ($resolvers[$kind])($target, $path, $uri);
                            $this->validateUri($url);
                            $cache[$kind][$path][$uri] = $url;
                        }
                        $line = substr_replace($line, $cache[$kind][$path][$uri], $offset, $length);
                    }
                }
                $outputBudget -= strlen($line) + ($lineIndex > 0 ? 1 : 0);
                if ($outputBudget < 0) {
                    throw new MediaException('An HLS response exceeds its 16 MiB output limit.', 'response', 'artifact');
                }
            }
            unset($line);
            if ($type !== null) {
                throw new MediaException('An HLS resource declaration has no URI.', 'response', 'artifact');
            }
            $result[$path] = implode("\n", $lines);
        }

        return $result;
    }

    private function read(string $path, int $limit): string
    {
        $stream = null;
        try {
            $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;
            $stream = $disk->readStream($path);
            if (! is_resource($stream) || ($content = stream_get_contents($stream, $limit + 1)) === false) {
                throw new InvalidArgumentException;
            }
        } catch (Throwable) {
            throw new MediaException('Cannot read the HLS playlist.', 'response', 'filesystem');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $normalized = str_replace("\r\n", "\n", $content);
        if (strlen($content) > $limit || ! str_starts_with($normalized, "#EXTM3U\n")
            || ! preg_match('//u', $normalized) || preg_match('/[\x00-\x09\x0B-\x1F\x7F-\x9F]/u', $normalized)) {
            throw new MediaException('HLS responses require UTF-8 playlists within the 16 MiB input limit.', 'response', 'artifact');
        }

        return $content;
    }

    private function validateUri(mixed $uri): void
    {
        if (! is_string($uri) || $uri === '' || ! preg_match('//u', $uri) || preg_match('/[\s\x00-\x1F\x7F-\x9F"\\\\#]/u', $uri)
            || preg_match('/%(?![0-9a-f]{2})/i', $uri) || str_starts_with($uri, '//') || parse_url($uri) === false
            || (($scheme = parse_url($uri, PHP_URL_SCHEME)) !== null
                && (! in_array(strtolower($scheme), ['http', 'https'], true) || ! filter_var($uri, FILTER_VALIDATE_URL)))) {
            throw new MediaException('HLS resolvers and references require an HTTP(S) URL or a valid relative URI.', 'response', 'artifact');
        }
    }

    /** Parse quoted URI attributes without treating commas inside NAME as separators. */
    private function resourceUri(string $line, bool $required): ?array
    {
        $start = strpos($line, ':') + 1;
        $attributes = substr($line, $start);
        preg_match_all('/(?:\A|,)([A-Z0-9-]+)=("[^"\r\n]*"|[^,"\r\n]+)/', $attributes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $end = 0;
        $values = [];
        $uri = null;
        foreach ($matches as $match) {
            [$name, $value] = [$match[1][0], $match[2][0]];
            if ($match[0][1] !== $end || isset($values[$name])) {
                throw new MediaException('Invalid HLS resource attributes.', 'response', 'artifact');
            }
            $end += strlen($match[0][0]);
            $values[$name] = $value;
            if ($name === 'URI') {
                if (! str_starts_with($value, '"') || ! str_ends_with($value, '"')) {
                    throw new MediaException('HLS resource URIs must be quoted.', 'response', 'artifact');
                }
                $uri = [$start + $match[2][1] + 1, strlen($value) - 2];
            }
        }
        if ($end !== strlen($attributes) || ($required && $uri === null)
            || (! $required && (! isset($values['TYPE'], $values['GROUP-ID'], $values['NAME'])
                || ($values['TYPE'] === 'SUBTITLES' && $uri === null)))) {
            throw new MediaException('Invalid HLS resource attributes.', 'response', 'artifact');
        }

        return $uri;
    }

    /** Return the URI's byte offset and length without changing other key attributes. */
    private function keyUri(string $line): ?array
    {
        $attributes = substr($line, 11);
        preg_match_all('/(?:\A|,)([A-Z0-9-]+)=("[^"\r\n]*"|[^,"\r\n]+)/', $attributes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $end = 0;
        $values = [];
        $uri = null;
        foreach ($matches as $match) {
            [$name, $value] = [$match[1][0], $match[2][0]];
            if ($match[0][1] !== $end || isset($values[$name])) {
                throw new MediaException('Invalid HLS key attributes.', 'response', 'artifact');
            }
            $end += strlen($match[0][0]);
            $values[$name] = $value;
            if ($name === 'URI' && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $uri = [11 + $match[2][1] + 1, strlen($value) - 2];
            }
        }
        if ($end !== strlen($attributes) || ! isset($values['METHOD'])
            || ($values['METHOD'] === 'NONE' ? isset($values['URI']) : $uri === null)) {
            throw new MediaException('Invalid HLS key attributes.', 'response', 'artifact');
        }

        return $uri;
    }
}
