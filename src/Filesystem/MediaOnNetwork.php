<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\InteractsWithHttpHeaders;

class MediaOnNetwork
{
    use HasInputOptions;
    use InteractsWithHttpHeaders;

    /**
     * @var string
     */
    private $path;

    public function __construct(string $path, array $headers = [])
    {
        $this->path    = $path;
        $this->headers = $headers;
    }

    public static function make(string $path, array $headers = []): self
    {
        return new static($path, $headers);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDisk(): Disk
    {
        return Disk::make(config('filesystems.default'));
    }

    public function getLocalPath(): string
    {
        return $this->path;
    }

    public function getFilenameWithoutExtension(): string
    {
        return pathinfo($this->getPath())['filename'];
    }

    public function getFilename(): string
    {
        return pathinfo($this->getPath())['basename'];
    }

    public function getCompiledInputOptions(): array
    {
        return array_merge($this->getInputOptions(), $this->getCompiledHeaders());
    }

    public function getCompiledHeaders(): array
    {
        return static::compileHeaders($this->getHeaders());
    }

    /**
     * Downloads the Media from the internet and stores it in
     * a temporary directory.
     *
     * @param callable $withCurl
     * @return \ProtoneMedia\LaravelFFMpeg\Filesystem\Media
     */
    public function toMedia(callable $withCurl = null): Media
    {
        $disk = Disk::makeTemporaryDisk();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->path);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        if (!empty($this->headers)) {
            $headers = Collection::make($this->headers)->map(function($value, $header) {
                return "{$header}: {$value}";
            })->all();

            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        if ($withCurl) {
            $curl = $withCurl($curl) ?: $curl;
        }

        $contents = curl_exec($curl);
        curl_close($curl);

        $disk->getFilesystemAdapter()->put(
            $filename = $this->getFilename(),
            $contents
        );

        return new Media($disk, $filename);
    }
}
