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

    private $newEncryptionKeyCallback = null;

    private $encryptionKeyDisk      = null;
    private $encryptionKeyName      = null;
    private $encryptionIV           = null;
    private $rotatingEncryptiongKey = false;
    private $segmentsOpenend        = 0;

    public function onEncryptionKey(Closure $callback): self
    {
        $this->newEncryptionKeyCallback = $callback;

        return $this;
    }

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
    public function withEncryptionKey($key = null): self
    {
        $this->encryptionKey = $key ?: static::generateEncryptionKey();

        return $this;
    }

    public function withRotatingEncryptionKey(): self
    {
        $this->withEncryptionKey();

        $this->rotatingEncryptiongKey = true;

        return $this;
    }

    private function rotateEncryptionKey()
    {
        $this->withEncryptionKey();

        if (!$this->encryptionKeyDisk) {
            $this->encryptionKeyDisk = Disk::makeTemporaryDisk();
        }

        if (!$this->encryptionKeyName) {
            $this->encryptionKeyName = Str::random(8);
        }

        if (!$this->encryptionIV) {
            $this->encryptionIV = bin2hex(static::generateEncryptionKey());
        }

        $keyInfoPath = $this->encryptionKeyDisk
            ->makeMedia("{$this->encryptionKeyName}.keyinfo")
            ->getLocalPath();

        $name = $this->encryptionKeyName . "_" . Str::random(8);

        $keyPath = $this->encryptionKeyDisk->makeMedia("{$name}.key")->getLocalPath();

        file_put_contents($keyPath, $this->encryptionKey);

        file_put_contents($keyInfoPath, implode(PHP_EOL, [
            $keyPath, $keyPath, $this->encryptionIV,
        ]));

        if ($this->newEncryptionKeyCallback) {
            call_user_func($this->newEncryptionKeyCallback, "{$name}.key", $this->encryptionKey);
        }

        return $keyInfoPath;
    }

    private function getEncrypedHLSParameters(): array
    {
        if (!$this->encryptionKey) {
            return [];
        }

        $hlsParameters = [
            '-hls_key_info_file',
            $this->rotateEncryptionKey(),
        ];

        if ($this->rotatingEncryptiongKey) {
            $hlsParameters[] = '-hls_flags';
            $hlsParameters[] = 'periodic_rekey';
        }

        return $hlsParameters;
    }

    private function addRotatingKeyListener()
    {
        if (!$this->rotatingEncryptiongKey) {
            return;
        }

        $this->addListener(new StdListener)->onEvent('listen', function ($line) {
            if (!(Str::contains($line, "Opening 'crypto:/") && Str::contains($line, ".ts' for writing"))) {
                return;
            }

            $this->segmentsOpenend++;
            $this->rotateEncryptionKey();
        });
    }
}
