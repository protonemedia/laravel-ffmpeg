<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;
use Symfony\Component\Process\Process;

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

    private $nextEncryptionKey;

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
        $this->encryptionSecretsRoot = app(TemporaryDirectories::class)->create();

        $this->encryptionIV = bin2hex(static::generateEncryptionKey());

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
     * Rotates the key and returns the absolute path to the info file. This method
     * should be executed as fast as possible, or we might be too late for FFmpeg
     * opening the next segment. That's why we don't use the Disk-class magic.
     *
     * @return string
     */
    private function rotateEncryptionKey(): string
    {
        $hlsKeyInfoPath = $this->encryptionSecretsRoot . '/' . HLSExporter::HLS_KEY_INFO_FILENAME;

        // get the absolute path to the encryption key
        $keyFilename = $this->nextEncryptionKey ? $this->nextEncryptionKey[0] : static::generateEncryptionKeyFilename();
        $keyPath     = $this->encryptionSecretsRoot . '/' . $keyFilename;

        $encryptionKey = $this->setEncryptionKey($this->nextEncryptionKey ? $this->nextEncryptionKey[1] : null);

        // generate an info file with a reference to the encryption key and IV
        file_put_contents(
            $hlsKeyInfoPath,
            $keyPath . PHP_EOL . $keyPath . PHP_EOL . $this->encryptionIV,
        );

        // randomize the encryption key
        file_put_contents($keyPath, $encryptionKey);

        // call the callback
        if ($this->onNewEncryptionKey) {
            call_user_func($this->onNewEncryptionKey, $keyFilename, $encryptionKey, $this->listener);
        }

        if ($this->listener) {
            $this->listener->handle(Process::OUT, "Generated new key with filename: {$keyFilename}");
        }

        $this->nextEncryptionKey = [static::generateEncryptionKeyFilename(), static::generateEncryptionKey()];

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

        $this->addListener($this->listener = new StdListener)->onEvent('listen', function ($line) {
            $opensEncryptedSegment = Str::contains($line, ".keyinfo' for reading");

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
        if (!$this->encryptionSecretsRoot) {
            return;
        }

        $playlistMedia->each(function ($playlistMedia) {
            $disk = $playlistMedia->getDisk();
            $path = $playlistMedia->getPath();

            $prefix = '#EXT-X-KEY:METHOD=AES-128,URI="';

            $content = str_replace(
                $prefix . $this->encryptionSecretsRoot . '/',
                $prefix,
                $disk->get($path)
            );

            $content = str_replace(
                $prefix . Disk::normalizePath($this->encryptionSecretsRoot) . '/',
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
        if (!$this->encryptionSecretsRoot) {
            return;
        }

        (new Filesystem)->deleteDirectory($this->encryptionSecretsRoot);
    }
}
