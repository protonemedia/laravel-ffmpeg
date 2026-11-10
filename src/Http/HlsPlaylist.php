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
use ProtoneMedia\LaravelFFMpeg\Media;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A stored HLS playlist whose references can be rewritten to application URLs.
 */
class HlsPlaylist implements Responsable
{
    use Conditionable;

    /**
     * The normalized disk-relative path of the playlist.
     */
    protected string $path;

    /**
     * The URL resolvers, keyed by reference kind.
     */
    protected array $resolvers = [];

    /**
     * Indicates if the running render includes every local playlist it references.
     */
    protected bool $renderingAll = false;

    /**
     * The URL resolvers applied by the running render, keyed by reference kind.
     *
     * @var array<string, Closure>
     */
    protected array $renderResolvers = [];

    /**
     * The callback that receives every reference of the running render.
     */
    protected ?Closure $discover = null;

    /**
     * The playlists queued by the running render, in reading order.
     *
     * @var list<string>
     */
    protected array $pending = [];

    /**
     * The playlists queued by the running render, keyed by path.
     *
     * @var array<string, true>
     */
    protected array $queued = [];

    /**
     * The number of bytes the running render may still produce.
     */
    protected int $outputBudget = 0;

    /**
     * Create a new HLS playlist instance.
     */
    public function __construct(protected readonly string|Filesystem $disk, string $path)
    {
        if ($path === '' || preg_match('/\A(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $path)) {
            throw new InvalidArgumentException('A playlist requires a disk-relative m3u8 key.');
        }

        $this->path = (new WhitespacePathNormalizer)->normalizePath($path);

        if (strtolower(pathinfo($this->path, PATHINFO_EXTENSION)) !== 'm3u8') {
            throw new InvalidArgumentException('A playlist requires an m3u8 extension.');
        }
    }

    /**
     * Set the callback that resolves encryption key URLs.
     */
    public function keyUrlsUsing(callable $resolver): static
    {
        $this->resolvers['key'] = Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Set the callback that resolves media segment URLs.
     */
    public function mediaUrlsUsing(callable $resolver): static
    {
        $this->resolvers['media'] = Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Set the callback that resolves nested playlist URLs.
     */
    public function playlistUrlsUsing(callable $resolver): static
    {
        $this->resolvers['playlist'] = Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Get the rendered contents of the playlist.
     */
    public function get(): string
    {
        return $this->render(false)[$this->path];
    }

    /**
     * Get the rendered contents of the playlist and every local playlist it references.
     *
     * Keys and media are never fetched.
     *
     * @return Collection<string, string>
     */
    public function all(): Collection
    {
        return collect($this->render(true));
    }

    /**
     * Delete the playlist and the original disk files it references, never the resolver URLs.
     *
     * The approval callback must return true for every unique disk-relative path, including the opened playlist.
     * Discovery and approval finish before the first delete, but adapter failures may leave a partially deleted
     * set. The application must protect shared files and prevent concurrent changes to the playlists or their
     * storage during this operation.
     *
     * @param  callable(string): bool  $approve
     */
    public function deleteAllFiles(callable $approve): static
    {
        $paths = [$this->path => true];

        $this->render(true, function (?string $target, string $uri) use (&$paths): void {
            $decoded = rawurldecode(parse_url($uri, PHP_URL_PATH) ?? '');

            if ($target === null || preg_match('/\A(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $decoded)) {
                throw MediaException::response('HLS deletion requires safe disk-relative references.', 'artifact');
            }

            $paths[$target] = true;
        });

        $paths = array_map(strval(...), array_keys($paths));

        foreach ($paths as $path) {
            if ($approve($path) !== true) {
                throw MediaException::response('HLS deletion was not approved for every file.', 'authorization');
            }
        }

        try {
            $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;

            foreach ($paths as $path) {
                if (! $disk->delete($path)) {
                    throw new InvalidArgumentException;
                }
            }
        } catch (Throwable) {
            throw MediaException::response('Cannot delete the HLS files.', 'filesystem');
        }

        return $this;
    }

    /**
     * Create an HTTP response that represents the playlist.
     */
    public function toResponse($request): Response
    {
        return response($this->get(), 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Render the playlist, and every local playlist it references when requested.
     *
     * @return array<string, string>
     */
    protected function render(bool $all, ?Closure $discover = null): array
    {
        $this->beginRender($all, $discover);

        try {
            $result = [];
            $inputBudget = Media::MAX_MANIFEST_BYTES;

            for ($index = 0; $index < count($this->pending); $index++) {
                $path = $this->pending[$index];
                $content = $this->read($path, $inputBudget);
                $inputBudget -= strlen($content);

                $result[$path] = $this->renderPlaylist($path, $content);
            }

            return $result;
        } finally {
            $this->resetRender();
        }
    }

    /**
     * Start a render that begins with the opened playlist and the full output budget.
     */
    protected function beginRender(bool $all, ?Closure $discover): void
    {
        $this->resetRender();

        $this->renderingAll = $all;
        $this->renderResolvers = $discover instanceof Closure ? [] : $this->resolvers;
        $this->discover = $discover;
        $this->pending = [$this->path];
        $this->queued = [$this->path => true];
        $this->outputBudget = Media::MAX_MANIFEST_BYTES;
    }

    /**
     * Reset the render state to its defaults.
     */
    protected function resetRender(): void
    {
        $this->renderingAll = false;
        $this->renderResolvers = [];
        $this->discover = null;
        $this->pending = [];
        $this->queued = [];
        $this->outputBudget = 0;
    }

    /**
     * Render one playlist and queue the local playlists it references.
     */
    protected function renderPlaylist(string $path, string $content): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $cache = [];
        $type = null;

        foreach ($lines as $lineIndex => &$line) {
            [$kind, $offset, $length, $type] = $this->classifyLine($line, $type);

            if ($kind !== null) {
                $uri = substr($line, $offset, $length);
                $target = $this->referenceTarget($path, $uri);

                if ($this->discover instanceof Closure) {
                    ($this->discover)($target, $uri);
                }

                if ($this->renderingAll && $kind === 'playlist' && $target !== null
                    && ! isset($this->queued[$target])) {
                    if (count($this->queued) >= 1024) {
                        throw MediaException::response(
                            'An HLS response cannot read more than 1024 local playlists.',
                            'artifact',
                        );
                    }

                    $this->queued[$target] = true;
                    $this->pending[] = $target;
                }

                if (isset($this->renderResolvers[$kind])) {
                    $cache[$kind][$uri] ??= $this->resolveUrl($this->renderResolvers[$kind], $target, $path, $uri);
                    $line = substr_replace($line, $cache[$kind][$uri], $offset, $length);
                }
            }

            $this->outputBudget -= strlen($line) + ($lineIndex > 0 ? 1 : 0);

            if ($this->outputBudget < 0) {
                throw MediaException::response('An HLS response exceeds its 16 MiB output limit.', 'artifact');
            }
        }

        unset($line);

        if ($type !== null) {
            throw MediaException::response('An HLS resource declaration has no URI.', 'artifact');
        }

        return implode("\n", $lines);
    }

    /**
     * Classify a playlist line and locate the URI it references, if any.
     *
     * The result holds the reference kind, URI offset, URI length and pending declaration type.
     *
     * @return array{string|null, int, int, string|null}
     */
    protected function classifyLine(string $line, ?string $type): array
    {
        $kind = null;
        $offset = 0;
        $length = strlen($line);

        if (str_starts_with($line, '#EXT-X-STREAM-INF:') || str_starts_with($line, '#EXTINF:')) {
            if ($type !== null) {
                throw MediaException::response('An HLS resource declaration has no URI.', 'artifact');
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
                throw MediaException::response('An HLS URI has no resource declaration.', 'artifact');
            }

            $kind = $type;
            $type = null;
        } elseif (
            preg_match('/\A#EXT-X-(?:I-FRAME-STREAM-INF|SESSION-KEY|PART|PRELOAD-HINT|RENDITION-REPORT):/', $line)
            || (str_starts_with($line, '#EXT') && preg_match('/[:,]URI\s*=/', $line))
        ) {
            throw MediaException::response('This response does not support this HLS resource tag.', 'artifact');
        }

        return [$kind, $offset, $length, $type];
    }

    /**
     * Validate the given reference and resolve its disk path when it is relative.
     */
    protected function referenceTarget(string $path, string $uri): ?string
    {
        $this->validateUri($uri);

        $target = null;

        if (! str_starts_with($uri, '/') && parse_url($uri, PHP_URL_SCHEME) === null) {
            try {
                $decoded = rawurldecode(parse_url($uri, PHP_URL_PATH) ?? '');

                if ($decoded === '' || ! preg_match('//u', $decoded) || str_contains($decoded, '\\')) {
                    throw new InvalidArgumentException;
                }

                $target = (new WhitespacePathNormalizer)->normalizePath(
                    (dirname($path) === '.' ? '' : dirname($path).'/').$decoded
                );

                if ($target === '') {
                    throw new InvalidArgumentException;
                }
            } catch (Throwable) {
                throw MediaException::response('An HLS reference escapes the disk or has an invalid path.', 'artifact');
            }
        }

        return $target;
    }

    /**
     * Resolve and validate the URL that replaces the given reference.
     */
    protected function resolveUrl(Closure $resolver, ?string $target, string $path, string $uri): string
    {
        $url = $resolver($target, $path, $uri);

        $this->validateUri($url);

        return $url;
    }

    /**
     * Read a playlist from the disk within the given byte limit and validate its encoding.
     */
    protected function read(string $path, int $limit): string
    {
        $stream = null;

        try {
            $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;
            $stream = $disk->readStream($path);

            if (! is_resource($stream) || ($content = stream_get_contents($stream, $limit + 1)) === false) {
                throw new InvalidArgumentException;
            }
        } catch (Throwable) {
            throw MediaException::response('Cannot read the HLS playlist.', 'filesystem');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $normalized = str_replace("\r\n", "\n", $content);

        if (strlen($content) > $limit) {
            throw MediaException::response('An HLS response exceeds its 16 MiB input limit.', 'artifact');
        }

        if (! str_starts_with($normalized, "#EXTM3U\n")
            || ! preg_match('//u', $normalized) || preg_match('/[\x00-\x09\x0B-\x1F\x7F-\x9F]/u', $normalized)) {
            throw MediaException::response(
                'HLS responses require UTF-8 playlists that start with #EXTM3U and contain no control characters.',
                'artifact',
            );
        }

        return $content;
    }

    /**
     * Ensure the given value is an HTTP(S) URL or a valid relative URI.
     */
    protected function validateUri(mixed $uri): void
    {
        if (! is_string($uri) || $uri === '' || ! preg_match('//u', $uri)
            || preg_match('/[\s\x00-\x1F\x7F-\x9F"\\\\#]/u', $uri)
            || preg_match('/%(?![0-9a-f]{2})/i', $uri) || str_starts_with($uri, '//') || parse_url($uri) === false
            || (($scheme = parse_url($uri, PHP_URL_SCHEME)) !== null
                && (! in_array(strtolower($scheme), ['http', 'https'], true)
                    || ! filter_var($uri, FILTER_VALIDATE_URL)))) {
            throw MediaException::response(
                'HLS resolvers and references require an HTTP(S) URL or a valid relative URI.',
                'artifact',
            );
        }
    }

    /**
     * Split an attribute list into name => [value, offset] pairs without splitting on commas inside quotes.
     *
     * @internal
     *
     * @return array<string, array{string, int}>|null null when the list has gaps or duplicate names
     */
    public static function attributes(string $list): ?array
    {
        preg_match_all(
            '/(?:\A|,)([A-Z0-9-]+)=("[^"\r\n]*"|[^,"\r\n]+)/',
            $list,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $end = 0;
        $values = [];

        foreach ($matches as $match) {
            if ($match[0][1] !== $end || isset($values[$match[1][0]])) {
                return null;
            }

            $end += strlen($match[0][0]);
            $values[$match[1][0]] = [$match[2][0], $match[2][1]];
        }

        return $end === strlen($list) ? $values : null;
    }

    /**
     * Get the byte offset and length of the quoted URI within a MAP or MEDIA line.
     *
     * @return array{int, int}|null
     */
    protected function resourceUri(string $line, bool $required): ?array
    {
        $start = strpos($line, ':') + 1;
        $values = self::attributes(substr($line, $start));

        if ($values === null) {
            throw MediaException::response('Invalid HLS resource attributes.', 'artifact');
        }

        $uri = null;

        if (isset($values['URI'])) {
            [$value, $offset] = $values['URI'];

            if (! str_starts_with($value, '"') || ! str_ends_with($value, '"')) {
                throw MediaException::response('HLS resource URIs must be quoted.', 'artifact');
            }

            $uri = [$start + $offset + 1, strlen($value) - 2];
        }

        if (($required && $uri === null)
            || (! $required && (! isset($values['TYPE'], $values['GROUP-ID'], $values['NAME'])
                || ($values['TYPE'][0] === 'SUBTITLES' && $uri === null)))) {
            throw MediaException::response('Invalid HLS resource attributes.', 'artifact');
        }

        return $uri;
    }

    /**
     * Get the byte offset and length of the URI within a KEY line without changing its other attributes.
     *
     * @return array{int, int}|null
     */
    protected function keyUri(string $line): ?array
    {
        $values = self::attributes(substr($line, 11));
        $uri = null;

        if (isset($values['URI'])
            && str_starts_with($values['URI'][0], '"')
            && str_ends_with($values['URI'][0], '"')) {
            $uri = [11 + $values['URI'][1] + 1, strlen($values['URI'][0]) - 2];
        }

        if (! isset($values['METHOD'])
            || ($values['METHOD'][0] === 'NONE' ? isset($values['URI']) : $uri === null)) {
            throw MediaException::response('Invalid HLS key attributes.', 'artifact');
        }

        return $uri;
    }
}
