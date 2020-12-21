<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;

trait EncryptsHLSSegments
{
    const HLS_KEY_INFO_FILENAME = 'hls_encryption.keyinfo';

    /**
     * The encryption key.
     *
     * @var string
     */
    private $encryptionKey;

    /**
     * Gets called whenever a new encryption key is set.
     *
     * @var callable
     */
    private $onNewEncryptionKey ;

    /**
     * Disk to store the secrets.
     *
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Disk
     */
    private $encryptionSecretsDisk;

    /**
     * Encryption IV
     *
     * @var string
     */
    private $encryptionIV;

    /**
     * Wether to rotate the key on every segment.
     *
     * @var boolean
     */
    private $rotatingEncryptiongKey = false;

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
     * @return string
     */
    private function setEncryptionKey($key = null): string
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
        $this->encryptionSecretsDisk = Disk::makeTemporaryDisk();
        $this->encryptionIV          = bin2hex(static::generateEncryptionKey());

        $this->setEncryptionKey($key);

        return $this;
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

    /**
     * Rotates the key and returns the absolute path to the info file.
     *
     * @return string
     */
    private function rotateEncryptionKey(): string
    {
        // randomize the encryption key
        $this->encryptionSecretsDisk->put(
            $keyFilename = Str::random(8) . '.key',
            $encryptionKey = $this->setEncryptionKey()
        );

        // get the absolute path to the encryption key
        $keyPath = $this->encryptionSecretsDisk->makeMedia($keyFilename)->getLocalPath();

        // generate an info file with a reference to the encryption key and IV
        $this->encryptionSecretsDisk->put(static::HLS_KEY_INFO_FILENAME, implode(PHP_EOL, [
            $keyPath, $keyPath, $this->encryptionIV,
        ]));

        // call the callback
        if ($this->onNewEncryptionKey) {
            call_user_func($this->onNewEncryptionKey, $keyFilename, $encryptionKey);
        }

        // return the absolute path to the info file
        return $this->encryptionSecretsDisk
            ->makeMedia(static::HLS_KEY_INFO_FILENAME)
            ->getLocalPath();
    }

    /**
     * Returns an array with the encryption parameters.
     *
     * @return array
     */
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

    /**
     * Adds a listener and handler to rotate the key on
     * every new HLS segment.
     *
     * @return void
     */
    private function addHandlerToRotateEncryption()
    {
        if (!$this->rotatingEncryptiongKey) {
            return;
        }

        $this->addListener(new StdListener)->onEvent('listen', function ($line) {
            $opensEncryptedSegment = Str::contains($line, "Opening 'crypto:/")
                && Str::contains($line, ".ts' for writing");

            if ($opensEncryptedSegment) {
                $this->rotateEncryptionKey();
            }
        });
    }

    /**
     * Removes the encryption keys from the temporary disk.
     *
     * @return void
     */
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
