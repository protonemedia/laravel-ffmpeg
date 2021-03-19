<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;

trait EncryptsHLSSegments
{
    /**
     * The encryption key.
     *
     * @var string
     */
    private $encryptionKey;

    /**
     * The encryption key filename.
     *
     * @var string
     */
    private $encryptionKeyFilename;

    /**
     * Gets called whenever a new encryption key is set.
     *
     * @var callable
     */
    private $onNewEncryptionKey;

    /**
     * Disk to store the secrets.
     */
    private $encryptionSecretsRoot;

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
     * Listener that will rotate the key.
     *
     * @var \ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener
     */
    private $listener;

    /**
     * A fresh filename and encryption key for the next round.
     *
     * @var array
     */
    private $nextEncryptionFilenameAndKey;

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
     * Creates a new encryption key filename.
     *
     * @return string
     */
    public static function generateEncryptionKeyFilename(): string
    {
        return bin2hex(random_bytes(8)) . '.key';
    }

    /**
     * Initialises the disk, info and IV for encryption and sets the key.
     *
     * @param string $key
     * @param string $filename
     * @return self
     */
    public function withEncryptionKey($key, $filename = 'secret.key'): self
    {
        $this->encryptionKey = $key;
        $this->encryptionIV  = bin2hex(static::generateEncryptionKey());

        $this->encryptionKeyFilename = $filename;
        $this->encryptionSecretsRoot = app(TemporaryDirectories::class)->create();

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

        return $this->withEncryptionKey(null, null);
    }

    /**
     * Rotates the key and returns the absolute path to the info file. This method
     * should be executed as fast as possible, or we might be too late for FFmpeg
     * opening the next segment. That's why we don't use the Disk-class magic.
     *
     * @return string
     */
    private function rotateEncryptionKey(): string
    {
        if ($this->nextEncryptionFilenameAndKey) {
            [$keyFilename, $encryptionKey] = $this->nextEncryptionFilenameAndKey;
        } else {
            $keyFilename   = $this->encryptionKeyFilename ?: static::generateEncryptionKeyFilename();
            $encryptionKey = $this->encryptionKey ?: static::generateEncryptionKey();
        }

        // get the absolute path to the info file and encryption key
        $hlsKeyInfoPath = $this->encryptionSecretsRoot . '/' . HLSExporter::HLS_KEY_INFO_FILENAME;
        $keyPath        = $this->encryptionSecretsRoot . '/' . $keyFilename;

        $normalizedKeyPath = Disk::normalizePath($keyPath);

        // store the encryption key
        file_put_contents($keyPath, $encryptionKey);

        // store an info file with a reference to the encryption key and IV
        file_put_contents(
            $hlsKeyInfoPath,
            $normalizedKeyPath . PHP_EOL . $normalizedKeyPath . PHP_EOL . $this->encryptionIV
        );

        // prepare for the next round
        if ($this->rotateEncryptiongKey) {
            $this->nextEncryptionFilenameAndKey = [
                static::generateEncryptionKeyFilename(),
                static::generateEncryptionKey(),
            ];
        }

        // call the callback
        if ($this->onNewEncryptionKey) {
            call_user_func($this->onNewEncryptionKey, $keyFilename, $encryptionKey, $this->listener);
        }

        // return the absolute path to the info file
        return Disk::normalizePath($hlsKeyInfoPath);
    }

    /**
     * Returns an array with the encryption parameters.
     *
     * @return array
     */
    private function getEncrypedHLSParameters(): array
    {
        if (!$this->encryptionIV) {
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

        $this->listener = new StdListener(HLSExporter::ENCRYPTION_LISTENER);

        $this->addListener($this->listener)
            ->onEvent(HLSExporter::ENCRYPTION_LISTENER, function ($line) {
                if (!strpos($line, ".keyinfo' for reading")) {
                    return;
                }

                $this->segmentsOpened++;

                if ($this->segmentsOpened % $this->segmentsPerKey === 0) {
                    $this->rotateEncryptionKey();
                }
            });
    }

    /**
     * Remove the listener at the end of the export to
     * prevent duplicate event handlers.
     *
     * @return self
     */
    private function removeHandlerThatRotatesEncryptionKey(): self
    {
        if ($this->listener) {
            $this->listener->removeAllListeners();
            $this->removeListener($this->listener);
            $this->listener = null;

            $this->getFFMpegDriver()->removeAllListeners(HLSExporter::ENCRYPTION_LISTENER);
        }

        return $this;
    }

    /**
     * While encoding, the encryption keys are saved to a temporary directory.
     * With this method, we loop through all segment playlists and replace
     * the absolute path to the keys to a relative ones.
     *
     * @param \Illuminate\Support\Collection $playlistMedia
     * @return self
     */
    private function replaceAbsolutePathsHLSEncryption(Collection $playlistMedia): self
    {
        if (!$this->encryptionSecretsRoot) {
            return $this;
        }

        $playlistMedia->each(function ($playlistMedia) {
            $disk = $playlistMedia->getDisk();
            $path = $playlistMedia->getPath();

            $prefix = '#EXT-X-KEY:METHOD=AES-128,URI="';

            $content = str_replace(
                $prefix . Disk::normalizePath($this->encryptionSecretsRoot) . '/',
                $prefix,
                $disk->get($path)
            );

            $disk->put($path, $content);
        });

        return $this;
    }

    /**
     * Removes the encryption keys from the temporary disk.
     *
     * @return self
     */
    private function cleanupHLSEncryption(): self
    {
        if ($this->encryptionSecretsRoot) {
            (new Filesystem)->deleteDirectory($this->encryptionSecretsRoot);
        }

        return $this;
    }
}
