<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use SensitiveParameter;

/**
 * The private keys of one execution and the bounded-memory encryption of its segments.
 *
 * @internal
 */
class HlsEncryption
{
    /**
     * The callback that resolves the URL of each key within a playlist.
     */
    protected readonly Closure $keyUrl;

    /**
     * The key directory on the keys disk, with a trailing slash unless it is the root.
     */
    protected readonly string $directory;

    /**
     * The generated keys and their artifacts, keyed by rotation index.
     */
    protected array $keys = [];

    /**
     * The resolved key URLs, keyed by playlist and rotation index.
     */
    protected array $urls = [];

    /**
     * Create a new HLS encryption instance.
     */
    public function __construct(
        protected readonly string|Filesystem $keysDisk,
        callable $keyUrl,
        #[SensitiveParameter] protected readonly ?string $key,
        protected readonly ?int $rotateEvery,
        string $directory,
    ) {
        if ($key !== null && strlen($key) !== 16) {
            throw new InvalidArgumentException('The AES-128 key must be exactly 16 bytes.');
        }

        if ($rotateEvery !== null && $rotateEvery < 1) {
            throw new InvalidArgumentException("The HLS key rotation interval [{$rotateEvery}] must be positive.");
        }

        if ($key !== null && $rotateEvery !== null) {
            throw new InvalidArgumentException(
                'A fixed AES-128 key cannot be rotated; omit the key to generate rotated keys.'
            );
        }

        if (! function_exists('openssl_encrypt')) {
            throw new InvalidArgumentException('HLS encryption requires the OpenSSL PHP extension.');
        }

        $path = new ExportOutput(($directory === '' ? '' : $directory.'/').'key.key', $keysDisk)->destination()['path'];
        $this->directory = dirname($path) === '.' ? '' : dirname($path).'/';
        $this->keyUrl = Closure::fromCallable($keyUrl);
    }

    /**
     * Encrypt the given segment with the key of its rotation index and get its key tag.
     *
     * @return array{path: string, tag: string}
     */
    public function segment(string $path, int $sequence, string $playlist, string $variant): array
    {
        $index = $this->rotateEvery === null ? 0 : intdiv($sequence, $this->rotateEvery);
        if (! isset($this->keys[$index])) {
            $bytes = $this->key ?? random_bytes(16);
            $filename = bin2hex(random_bytes(16)).'.key';
            $local = dirname($path).'/'.$filename;
            if (@file_put_contents($local, $bytes) !== 16 || ! chmod($local, 0600)) {
                throw MediaException::validation('Cannot prepare the private HLS key.', 'filesystem');
            }

            $target = new ExportOutput($this->directory.$filename, $this->keysDisk)->visibility('private');
            $this->keys[$index] = ['bytes' => $bytes, 'artifact' => [
                'path' => $local,
                'output' => 0,
                'target' => $target,
                'destination' => $target->destination(),
                'role' => 'hls-key',
            ]];
        }

        $key = $this->keys[$index];
        if (! isset($this->urls[$playlist][$index])) {
            $url = ($this->keyUrl)($key['artifact']['destination']['path'], $playlist, $variant, $index);
            if (! is_string($url) || $url === '' || ! preg_match('//u', $url)
                || preg_match('/[\s\x00-\x1F\x7F-\x9F"\\\\#]/u', $url)
                || str_starts_with($url, '//') || parse_url($url) === false
                || (($scheme = parse_url($url, PHP_URL_SCHEME)) !== null
                    && (! in_array(strtolower($scheme), ['http', 'https'], true)
                        || ! filter_var($url, FILTER_VALIDATE_URL)))) {
                throw MediaException::validation(
                    'HLS key URLs require an HTTP(S) URL or a relative URI without quotes, backslashes, whitespace, or fragments.',
                    'artifact',
                );
            }

            if (in_array($url, $this->urls[$playlist] ?? [], true)) {
                throw MediaException::validation(
                    'Rotated HLS keys must have distinct URLs within each playlist.',
                    'artifact',
                );
            }

            $this->urls[$playlist][$index] = $url;
        }

        $iv = random_bytes(16);
        $encrypted = $path.'.aes';
        $source = null;
        $destination = null;
        try {
            $source = @fopen($path, 'rb');
            $destination = @fopen($encrypted, 'xb');
            if ($source === false || $destination === false || ! chmod($encrypted, 0600)) {
                throw MediaException::validation('Cannot prepare the encrypted HLS segment.', 'filesystem');
            }

            $chain = $iv;
            $buffer = '';
            do {
                $chunk = fread($source, 1048576);
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw MediaException::validation('Cannot read the HLS segment.', 'filesystem');
                }

                $buffer .= $chunk;
                $final = feof($source);
                if ($final) {
                    $padding = 16 - strlen($buffer) % 16;
                    $buffer .= str_repeat(chr($padding), $padding);
                }

                $length = intdiv(strlen($buffer), 16) * 16;
                if ($length > 0) {
                    $ciphertext = openssl_encrypt(
                        substr($buffer, 0, $length),
                        'aes-128-cbc',
                        $key['bytes'],
                        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                        $chain,
                    );
                    if ($ciphertext === false || fwrite($destination, $ciphertext) !== strlen($ciphertext)) {
                        throw MediaException::validation('Cannot encrypt the HLS segment.', 'filesystem');
                    }

                    $chain = substr($ciphertext, -16);
                    $buffer = substr($buffer, $length);
                }
            } while (! $final);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($destination)) {
                fclose($destination);
            }
        }

        return [
            'path' => $encrypted,
            'tag' => '#EXT-X-KEY:METHOD=AES-128,URI="'.$this->urls[$playlist][$index].'",IV=0x'.bin2hex($iv),
        ];
    }

    /**
     * Get the key artifacts that must be published with the export.
     */
    public function artifacts(): array
    {
        return array_column($this->keys, 'artifact');
    }

    /**
     * Get the key destinations, keyed by playlist and key URL.
     */
    public function references(): array
    {
        $references = [];
        foreach ($this->urls as $playlist => $urls) {
            foreach ($urls as $index => $uri) {
                $references[$playlist][$uri] = [
                    'disk' => $this->keys[$index]['artifact']['destination']['disk'],
                    'path' => $this->keys[$index]['artifact']['destination']['path'],
                ];
            }
        }

        return $references;
    }
}
