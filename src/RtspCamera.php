<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** Capture one bounded snapshot. Camera URLs are trusted application configuration. */
readonly class RtspCamera implements InputSource
{
    public function __construct(private string $url, private string $transport = 'tcp', private float $timeout = 15)
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'rtsp' || empty($parts['host'])
            || preg_match('/[\x00-\x20\x7f]/', $url) || isset($parts['fragment'])
            || ! in_array($transport, ['tcp', 'udp'], true) || ! is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new InvalidArgumentException('RTSP snapshots require an rtsp URL, tcp/udp transport and a timeout between zero and 300 seconds.');
        }
    }

    public function frame(): PendingFrame
    {
        return new PendingFrame(new Media(config('filesystems.default'), $this), config('filesystems.default'), 0);
    }

    public function identity(): string
    {
        return hash('sha256', serialize([$this->url, $this->transport, $this->timeout]));
    }

    public function extension(): string
    {
        return 'png';
    }

    public function label(): string
    {
        return '[RTSP snapshot]';
    }

    public function protocols(): string
    {
        return 'file';
    }

    public function options(): array
    {
        return [];
    }

    public function redactions(): array
    {
        $redactions = [$this->url => '[rtsp]'];
        $parts = parse_url($this->url);
        foreach (['user', 'pass', 'query'] as $key) {
            if (isset($parts[$key]) && $parts[$key] !== '') {
                $redactions[$parts[$key]] = '[redacted]';
                $redactions[rawurldecode($parts[$key])] = '[redacted]';
            }
        }
        foreach (explode('&', $parts['query'] ?? '') as $parameter) {
            $value = explode('=', $parameter, 2)[1] ?? '';
            if ($value !== '') {
                $redactions[$value] = $redactions[rawurldecode($value)] = '[redacted]';
            }
        }

        return $redactions;
    }

    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if ($resource) {
            throw new MediaException('Capture an RTSP snapshot before using it as a resource.', 'staging', 'resource');
        }
        $binary = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
        $runner = $workspace->runner;
        $runner->version($binary, 'ffmpeg', $workspace->path, 'staging');
        $path = $workspace->path.'/'.$name;
        $result = $runner->run([
            $binary, '-hide_banner', '-nostdin', '-y', '-xerror', '-loglevel', 'error',
            '-protocol_whitelist', $this->transport === 'tcp' ? 'tcp' : 'tcp,udp,rtp',
            '-rtsp_transport', $this->transport, '-timeout', (string) (int) ceil($this->timeout * 1000000),
            '-i', $this->url, '-map', '0:v:0', '-frames:v', '1', '-an', '-c:v', 'png', '-f', 'image2', '-update', '1', $path,
        ], $workspace->path, $this->redactions(), phase: 'staging', timeout: $this->timeout);
        if ($result->failed() || ! is_file($path) || @getimagesize($path) === false || ! chmod($path, 0600)) {
            throw new MediaException('Cannot capture the RTSP snapshot.', 'staging', 'capture', $result->errorOutput());
        }

        return $path;
    }
}
