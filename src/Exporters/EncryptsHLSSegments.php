<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;

trait EncryptsHLSSegments
{
    /**
     * The encryption key.
     *
     * @var string
     */
    private $encryptionKey;

    private $onNewEncryptionKey = null;

    private $encryptionSecretsDisk  = null;
    private $encryptionInfoFilename = null;
    private $encryptionIV           = null;
    private $rotatingEncryptiongKey = false;
    private $segmentsOpenend        = 0;

    /**
     * Creates a new encryption key.
     *
     * @return string
     */
    public static function generateEncryptionKey(): string
    {
        return random_bytes(16);
    }

    /**
     * Sets the encryption key with the given value or generates a new one.
     *
     * @param string $key
     * @return self
     */
    private function setEncryptionKey($key = null): self
    {
        return $this->encryptionKey = $key ?: static::generateEncryptionKey();
    }

    /**
     * Initialises the disk, info and IV for encryption and sets the key.
     *
     * @param string $key
     * @return self
     */
    public function withEncryptionKey($key = null): self
    {
        $this->encryptionSecretsDisk  = Disk::makeTemporaryDisk();
        $this->encryptionInfoFilename = Str::random(8) . ".keyinfo";
        $this->encryptionIV           = bin2hex(static::generateEncryptionKey());

        return tap($this)->setEncryptionKey($key);
    }

    /**
     * Enables encryption with rotating keys.
     *
     * @return self
     */
    public function withRotatingEncryptionKey(): self
    {
        $this->rotatingEncryptiongKey = true;

        return $this->withEncryptionKey();
    }

    /**
     * A callable for each key that is generated.
     *
     * @param Closure $callback
     * @return self
     */
    public function onNewEncryptionKey(Closure $callback): self
    {
        $this->onNewEncryptionKey = $callback;

        return $this;
    }

    private function rotateEncryptionKey()
    {
        // randomize the encryption key
        $this->encryptionSecretsDisk->put(
            $keyFilename = Str::random(8) . '.key',
            $encryptionKey = $this->setEncryptionKey()
        );

        $keyPath = $this->encryptionSecretsDisk->makeMedia($keyFilename)->getLocalPath();

        $this->encryptionSecretsDisk->put($this->encryptionInfoFilename, implode(PHP_EOL, [
            $keyPath, $keyPath, $this->encryptionIV,
        ]));

        if ($this->onNewEncryptionKey) {
            call_user_func($this->onNewEncryptionKey, $keyFilename, $encryptionKey);
        }

        return $this->encryptionSecretsDisk
            ->makeMedia($this->encryptionInfoFilename)
            ->getLocalPath();
    }

    private function getEncrypedHLSParameters(): array
    {
        if (!$this->encryptionKey) {
            return [];
        }

        $keyInfoPath = $this->rotateEncryptionKey();
        $parameters  = ['-hls_key_info_file', $keyInfoPath];

        if ($this->rotatingEncryptiongKey) {
            $parameters[] = '-hls_flags';
            $parameters[] = 'periodic_rekey';
        }

        return $parameters;
    }

    private function addHandlerToRotateEncryption()
    {
        if (!$this->rotatingEncryptiongKey) {
            return;
        }

        $this->addListener(new StdListener)->onEvent('listen', function ($line) {
            $opensEncryptedSegment = Str::contains($line, "Opening 'crypto:/")
                && Str::contains($line, ".ts' for writing");

            if (!$opensEncryptedSegment) {
                return;
            }

            $this->segmentsOpenend++;
            $this->rotateEncryptionKey();
        });
    }

    private function cleanupHLSEncryption()
    {
        if (!$this->encryptionSecretsDisk) {
            return;
        }

        $paths = $this->encryptionSecretsDisk->allFiles();

        foreach ($paths as $path) {
            $this->encryptionSecretsDisk->delete($path);
        }
    }
}
