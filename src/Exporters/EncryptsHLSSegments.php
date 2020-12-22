<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Illuminate\Support\Collection;
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

    /**
     * Gets called whenever a new encryption key is set.
     *
     * @var callable
     */
    private $onNewEncryptionKey;

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
    private $rotateEncryptiongKey = false;

    /**
     * Number of opened segments.
     *
     * @var integer
     */
    private $segmentsOpened = 0;

    /**
     * Number of segments that can use the same key.
     *
     * @var integer
     */
    private $segmentsPerKey = 1;

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
    public function withEncryptionKey($key): self
    {
        $this->encryptionSecretsDisk = Disk::makeTemporaryDisk();
        $this->encryptionIV          = bin2hex(static::generateEncryptionKey());

        $this->setEncryptionKey($key);

        return $this;
    }

    /**
     * Enables encryption with rotating keys. The callable will receive every new
     * key and the integer sets the number of segments that can
     * use the same key.
     *
     * @param Closure $callback
     * @param int $segmentsPerKey
     * @return self
     */
    public function withRotatingEncryptionKey(Closure $callback, int $segmentsPerKey = 1): self
    {
        $this->rotateEncryptiongKey = true;
        $this->onNewEncryptionKey   = $callback;
        $this->segmentsPerKey       = $segmentsPerKey;

        return $this->withEncryptionKey(static::generateEncryptionKey());
    }

    /**
     * Rotates the key and returns the absolute path to the info file.
     *
     * @return string
     */
    private function rotateEncryptionKey(): string
    {

        // get the absolute path to the encryption key
        $keyPath = $this->encryptionSecretsDisk
            ->makeMedia($keyFilename = uniqid() . '.key')
            ->getLocalPath();

        // randomize the encryption key
        $this->encryptionSecretsDisk->put(
            $keyFilename,
            $encryptionKey = $this->setEncryptionKey()
        );

        // generate an info file with a reference to the encryption key and IV
        $this->encryptionSecretsDisk->put(
            HLSExporter::HLS_KEY_INFO_FILENAME,
            implode(PHP_EOL, [
                $keyPath, $keyPath, $this->encryptionIV,
            ])
        );

        // call the callback
        if ($this->onNewEncryptionKey) {
            call_user_func($this->onNewEncryptionKey, $keyFilename, $encryptionKey);
        }

        // return the absolute path to the info file
        return $this->encryptionSecretsDisk
            ->makeMedia(HLSExporter::HLS_KEY_INFO_FILENAME)
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

        if ($this->rotateEncryptiongKey) {
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
    private function addHandlerToRotateEncryptionKey()
    {
        if (!$this->rotateEncryptiongKey) {
            return;
        }

        $this->addListener(new StdListener)->onEvent('listen', function ($line) {
            $opensEncryptedSegment = Str::contains($line, "Opening 'crypto:/")
                && Str::contains($line, ".ts' for writing");

            if (!$opensEncryptedSegment) {
                return;
            }

            $this->segmentsOpened++;

            if ($this->segmentsOpened % $this->segmentsPerKey === 0) {
                $this->rotateEncryptionKey();
            }
        });
    }

    /**
     * While encoding, the encryption keys are saved to a temporary directory.
     * With this method, we loop through all segment playlists and replace
     * the absolute path to the keys to a relative ones.
     *
     * @param \Illuminate\Support\Collection $playlistMedia
     * @return void
     */
    private function replaceAbsolutePathsHLSEncryption(Collection $playlistMedia)
    {
        if (!$this->encryptionSecretsDisk) {
            return;
        }

        $playlistMedia->each(function ($playlistMedia) {
            $disk = $playlistMedia->getDisk();
            $path = $playlistMedia->getPath();

            $prefix = '#EXT-X-KEY:METHOD=AES-128,URI="';

            $content = str_replace(
                $prefix . $this->encryptionSecretsDisk->path(''),
                $prefix,
                $disk->get($path)
            );

            $disk->put($path, $content);
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
