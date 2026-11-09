<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * An explicitly trusted continuous source, not an RTSP snapshot or a staged download.
 *
 * The URL must be controlled by the application because this class is not an SSRF boundary. HTTP redirects
 * remain subject to the protocol allowlist, and manifest and dependency demuxers are excluded.
 */
readonly class LiveHlsSource
{
    /**
     * Create a new live HLS source instance.
     */
    public function __construct(#[SensitiveParameter] protected string $url)
    {
        $parts = parse_url($url);
        if ($parts === false
            || ! in_array($parts['scheme'] ?? '', ['http', 'https', 'rtsp'], true)
            || empty($parts['host'])) {
            throw new InvalidArgumentException('Live sources require a trusted HTTP(S) or RTSP URL with a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Live source URLs must not contain user information.');
        }

        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException('Live source URLs must not contain fragments.');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('Live source URLs must not contain whitespace or control characters.');
        }
    }

    /**
     * Get the properties to show when the source is dumped, with the URL redacted.
     */
    public function __debugInfo(): array
    {
        return ['url' => '[live-source]'];
    }

    /**
     * Get the source URL.
     *
     * @internal
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Get the FFmpeg protocol whitelist for the source's scheme.
     *
     * @internal
     */
    public function protocols(): string
    {
        return match (parse_url($this->url, PHP_URL_SCHEME)) {
            'rtsp' => 'tcp,udp,rtp',
            'https' => 'https,tls,tcp',
            default => 'http,tcp',
        };
    }

    /**
     * Get the FFmpeg input options for the source's scheme.
     *
     * @internal
     */
    public function options(): array
    {
        return parse_url($this->url, PHP_URL_SCHEME) === 'rtsp'
            ? ['-rtsp_transport', 'tcp', '-timeout', (string) UrlInput::NETWORK_TIMEOUT, '-format_whitelist', 'rtsp']
            : ['-rw_timeout', (string) UrlInput::NETWORK_TIMEOUT, '-format_whitelist', 'mpegts,matroska,webm,mov,flv',
                ...(str_starts_with($this->url, 'https:') ? ['-tls_verify', '1'] : [])];
    }
}
