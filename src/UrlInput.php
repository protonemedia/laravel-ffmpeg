<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

/** @internal */
readonly class UrlInput implements InputSource
{
    public function __construct(protected string $url, protected array $headers, public bool $download)
    {
        $parts = parse_url($url);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('openUrl() requires an HTTP(S) URL without user information, fragments, or whitespace.');
        }

        if ($headers !== [] && ! $download) {
            throw new InvalidArgumentException('Per-input HTTP headers require download: true to keep credentials out of process arguments.');
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! preg_match('/\A[!#$%&\x27*+.^_`|~0-9a-z-]+\z/i', $name)
                || ! is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('HTTP headers require valid names and single-line string values.');
            }
        }
    }

    public function __debugInfo(): array
    {
        return ['url' => '[url]', 'headers' => '[redacted]', 'download' => $this->download];
    }

    public function url(): string
    {
        return $this->url;
    }

    public function identity(): string
    {
        return hash('sha256', serialize([$this->url, $this->headers, $this->download]));
    }

    public function extension(): string
    {
        return pathinfo(parse_url($this->url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
    }

    public function label(): string
    {
        return '[url]';
    }

    public function protocols(): string
    {
        return $this->download ? 'file' : (str_starts_with($this->url, 'https:') ? 'https,tls,tcp' : 'http,tcp');
    }

    public function options(): array
    {
        if ($this->download) {
            return [];
        }

        $options = ['-rw_timeout', '15000000'];

        if (str_starts_with($this->url, 'https:')) {
            $options[] = '-tls_verify';
            $options[] = '1';
        }

        return $options;
    }

    public function redactions(): array
    {
        if (! config('laravel-ffmpeg.redact_credentials')) {
            return [];
        }

        return [$this->url => '[url]'] + self::queryRedactions(parse_url($this->url, PHP_URL_QUERY));
    }

    /**
     * @internal The whole query, plus each value whose name looks secret or that is long enough to be a token.
     * Short values under ordinary names, such as dl=1, also occur inside numbers and metadata.
     */
    public static function queryRedactions(?string $query): array
    {
        if ($query === null || $query === '') {
            return [];
        }

        $redactions = [$query => '[query]'];

        foreach (explode('&', $query) as $parameter) {
            [$name, $value] = explode('=', $parameter, 2) + [1 => ''];

            if ($value !== '' && (strlen($value) >= 16 || preg_match('/token|sig|key|auth|pass|secret|credential|policy/i', rawurldecode($name)))) {
                $redactions[$value] = $redactions[rawurldecode($value)] = '[redacted]';
            }
        }

        return $redactions;
    }

    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if (! $resource && in_array(strtolower($this->extension()), Media::MANIFEST_EXTENSIONS, true)) {
            throw new MediaException('URL manifest inputs require an explicit dependency policy.', 'staging', 'manifest');
        }

        if (! $this->download && ! $resource) {
            return $this->url;
        }

        $path = $workspace->path.'/'.$name;
        $output = @fopen($path, 'xb');

        if ($output === false) {
            throw new MediaException('Cannot create the downloaded media file.', 'staging', 'filesystem');
        }

        try {
            if (! chmod($path, 0600)) {
                throw new MediaException('Cannot protect the downloaded media file.', 'staging', 'filesystem');
            }

            // Do not follow redirects with reusable credentials, decompress bytes,
            // or accumulate a response body in PHP memory.
            $response = Http::withHeaders($this->headers)->connectTimeout(10)->timeout(60)
                ->withOptions(['allow_redirects' => false, 'decode_content' => false, 'verify' => true])
                ->sink($output)->get($this->url);
            $length = $response->header('Content-Length');

            if (! fflush($output)) {
                throw new MediaException('Cannot flush the downloaded media file.', 'staging', 'filesystem');
            }

            $size = fstat($output)['size'];

            if ($response->status() !== 200 || $size === 0
                || ($length !== '' && (! ctype_digit($length) || $size !== (int) $length))) {
                throw new MediaException('The HTTP media download did not complete.', 'staging', 'transfer');
            }

            if (! $resource) {
                Media::rejectManifestContent($path);
            }

            return $path;
        } catch (Throwable $throwable) {
            if ($throwable instanceof MediaException) {
                throw $throwable;
            }

            // HTTP exceptions can contain signed URLs, headers and response bodies.
            throw new MediaException('Cannot download the HTTP media input.', 'staging', 'transfer');
        } finally {
            fclose($output);
        }
    }
}
