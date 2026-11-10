<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/**
 * An RTSP camera that captures one bounded snapshot.
 *
 * Camera URLs are trusted application configuration.
 */
class RtspCamera implements InputSource
{
    /**
     * Create a new RTSP camera instance.
     */
    public function __construct(
        protected readonly string $url,
        protected readonly string $transport = 'tcp',
        protected readonly float $timeout = 15,
    ) {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'rtsp' || empty($parts['host'])) {
            throw new InvalidArgumentException('The camera URL must be an absolute rtsp URL.');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('The camera URL must not contain whitespace or control characters.');
        }

        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException('The camera URL must not contain a fragment.');
        }

        if (! in_array($transport, ['tcp', 'udp'], true)) {
            throw new InvalidArgumentException("The RTSP transport [{$transport}] must be tcp or udp.");
        }

        if (! is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new InvalidArgumentException(
                'The RTSP timeout must be greater than 0 and at most 300 seconds.'
            );
        }
    }

    /**
     * Begin exporting a snapshot frame from the camera.
     */
    public function frame(): PendingFrame
    {
        return new PendingFrame(new Media(config('filesystems.default'), $this), config('filesystems.default'), 0);
    }

    /**
     * Get a stable hash that identifies the camera and its settings.
     */
    public function identity(): string
    {
        return hash('sha256', serialize([$this->url, $this->transport, $this->timeout]));
    }

    /**
     * Get the file extension of the captured snapshot.
     */
    public function extension(): string
    {
        return 'png';
    }

    /**
     * Get the redacted label that stands in for the camera URL in metadata.
     */
    public function label(): string
    {
        return '[RTSP snapshot]';
    }

    /**
     * Get the FFmpeg protocol whitelist for the staged snapshot.
     */
    public function protocols(): string
    {
        return 'file';
    }

    /**
     * Get the FFmpeg transport options for the staged snapshot.
     */
    public function options(): array
    {
        return [];
    }

    /**
     * Get the camera URL and its credentials to replace in commands and diagnostics.
     */
    public function redactions(): array
    {
        if (! config('laravel-ffmpeg.redact_credentials')) {
            return [];
        }

        $redactions = [$this->url => '[rtsp]'];
        $parts = parse_url($this->url);

        // A password is a credential at any length, but a username on its own is not.
        if (($parts['pass'] ?? '') !== '') {
            foreach ([($parts['user'] ?? '').':'.$parts['pass'], $parts['pass']] as $secret) {
                $redactions[$secret] = $redactions[rawurldecode($secret)] = '[redacted]';
            }
        }

        return $redactions + UrlInput::queryRedactions($parts['query'] ?? null);
    }

    /**
     * Capture a single PNG snapshot from the camera into the workspace.
     */
    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if ($resource) {
            throw MediaException::staging('Capture an RTSP snapshot before using it as a resource.', 'resource');
        }

        $binary = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
        $runner = $workspace->runner;
        $runner->version($binary, 'ffmpeg', $workspace->path, 'staging');

        $path = "{$workspace->path}/{$name}";
        $result = $runner->run([
            $binary, '-hide_banner', '-nostdin', '-y', '-xerror', '-loglevel', 'error',
            '-protocol_whitelist', $this->transport === 'tcp' ? 'tcp' : 'tcp,udp,rtp',
            '-rtsp_transport', $this->transport, '-timeout', (string) (int) ceil($this->timeout * 1000000),
            '-i', $this->url, '-map', '0:v:0', '-frames:v', '1', '-an', '-c:v', 'png', '-f', 'image2',
            '-update', '1', $path,
        ], $workspace->path, $this->redactions(), phase: 'staging', timeout: $this->timeout);

        if ($result->failed() || ! is_file($path) || @getimagesize($path) === false || ! chmod($path, 0600)) {
            throw MediaException::staging('Cannot capture the RTSP snapshot.', 'capture', $result->errorOutput());
        }

        return $path;
    }
}
