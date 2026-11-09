<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

/**
 * A media input at an HTTP(S) URL, read directly by FFmpeg or downloaded first.
 *
 * @internal
 */
class UrlInput implements InputSource
{
    /**
     * The network read timeout in microseconds.
     *
     * @internal
     */
    public const int NETWORK_TIMEOUT = 15000000;

    /**
     * Create a new URL input instance.
     */
    public function __construct(protected readonly string $url, protected readonly array $headers, public readonly bool $download)
    {
        $parts = parse_url($url);

        if ($parts === false
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host'])) {
            throw new InvalidArgumentException('The media URL must be an absolute HTTP(S) URL.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The media URL must not contain user information.');
        }

        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException('The media URL must not contain a fragment.');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('The media URL must not contain whitespace or control characters.');
        }

        if ($headers !== [] && ! $download) {
            throw new InvalidArgumentException(
                'Per-input HTTP headers require download: true to keep credentials out of process arguments.'
            );
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! preg_match('/\A[!#$%&\x27*+.^_`|~0-9a-z-]+\z/i', $name)) {
                throw new InvalidArgumentException('The HTTP header names must be valid tokens.');
            }

            if (! is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('The HTTP header values must be single-line strings.');
            }
        }
    }

    /**
     * Get the redacted properties to show when the input is dumped.
     */
    public function __debugInfo(): array
    {
        return ['url' => '[url]', 'headers' => '[redacted]', 'download' => $this->download];
    }

    /**
     * Get the URL of the input.
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Get a stable hash that identifies the URL, headers and download mode.
     */
    public function identity(): string
    {
        return hash('sha256', serialize([$this->url, $this->headers, $this->download]));
    }

    /**
     * Get the file extension of the URL path.
     */
    public function extension(): string
    {
        return pathinfo(parse_url($this->url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
    }

    /**
     * Get the redacted label that stands in for the URL in metadata.
     */
    public function label(): string
    {
        return '[url]';
    }

    /**
     * Get the FFmpeg protocol whitelist for the staged input.
     */
    public function protocols(): string
    {
        return $this->download ? 'file' : (str_starts_with($this->url, 'https:') ? 'https,tls,tcp' : 'http,tcp');
    }

    /**
     * Get the FFmpeg transport options for direct HTTP(S) input.
     */
    public function options(): array
    {
        if ($this->download) {
            return [];
        }

        $options = ['-rw_timeout', (string) self::NETWORK_TIMEOUT];

        if (str_starts_with($this->url, 'https:')) {
            $options[] = '-tls_verify';
            $options[] = '1';
        }

        return $options;
    }

    /**
     * Get the URL and its secret query values to replace in commands and diagnostics.
     */
    public function redactions(): array
    {
        if (! config('laravel-ffmpeg.redact_credentials')) {
            return [];
        }

        return [$this->url => '[url]'] + self::queryRedactions(parse_url($this->url, PHP_URL_QUERY));
    }

    /**
     * Get the redactions for the given query string.
     *
     * These cover the whole query, plus each value whose name looks secret or that is long enough
     * to be a token. Short values under ordinary names, such as dl=1, are kept because they also
     * occur inside numbers and metadata.
     *
     * @internal
     */
    public static function queryRedactions(?string $query): array
    {
        if ($query === null || $query === '') {
            return [];
        }

        $redactions = [$query => '[query]'];

        foreach (explode('&', $query) as $parameter) {
            [$name, $value] = explode('=', $parameter, 2) + [1 => ''];

            if ($value !== '' && (
                strlen($value) >= 16
                || preg_match('/token|sig|key|auth|pass|secret|credential|policy/i', rawurldecode($name))
            )) {
                $redactions[$value] = $redactions[rawurldecode($value)] = '[redacted]';
            }
        }

        return $redactions;
    }

    /**
     * Stage the input, downloading it into the workspace when required.
     */
    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if (! $resource && in_array(strtolower($this->extension()), Media::MANIFEST_EXTENSIONS, true)) {
            throw MediaException::staging('URL manifest inputs require an explicit dependency policy.', 'manifest');
        }

        if (! $this->download && ! $resource) {
            return $this->url;
        }

        $path = $workspace->path.'/'.$name;
        $output = @fopen($path, 'xb');

        if ($output === false) {
            throw MediaException::staging('Cannot create the downloaded media file.', 'filesystem');
        }

        try {
            if (! chmod($path, 0600)) {
                throw MediaException::staging('Cannot protect the downloaded media file.', 'filesystem');
            }

            // We will not follow redirects with reusable credentials, decompress the bytes,
            // or accumulate the response body in PHP memory.
            $response = Http::withHeaders($this->headers)->connectTimeout(10)->timeout(60)
                ->withOptions(['allow_redirects' => false, 'decode_content' => false, 'verify' => true])
                ->sink($output)->get($this->url);
            $length = $response->header('Content-Length');

            if (! fflush($output)) {
                throw MediaException::staging('Cannot flush the downloaded media file.', 'filesystem');
            }

            $size = fstat($output)['size'];

            if ($response->status() !== 200 || $size === 0
                || ($length !== '' && (! ctype_digit($length) || $size !== (int) $length))) {
                throw MediaException::staging('The HTTP media download did not complete.', 'transfer');
            }

            if (! $resource) {
                Media::rejectManifestContent($path);
            }

            return $path;
        } catch (Throwable $throwable) {
            if ($throwable instanceof MediaException) {
                throw $throwable;
            }

            // We drop the original exception, since HTTP exceptions can contain signed URLs,
            // headers and response bodies.
            throw MediaException::staging('Cannot download the HTTP media input.', 'transfer');
        } finally {
            fclose($output);
        }
    }
}
