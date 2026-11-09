<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ProcessResult;
use Throwable;

/** @internal */
class ProcessRunner
{
    private readonly int $startedAt;

    public function __construct(private readonly ?float $timeout = null, private readonly ?float $idleTimeout = null)
    {
        foreach ([$timeout, $idleTimeout] as $seconds) {
            if ($seconds !== null && (! is_finite($seconds) || $seconds <= 0)) {
                throw new InvalidArgumentException('Process deadlines must be finite, positive seconds or null.');
            }
        }

        $this->startedAt = hrtime(true);
    }

    /** The operation budget is shared by staging, probing, analysis and encoding. */
    public function check(string $phase): void
    {
        if ($this->timeout !== null && (hrtime(true) - $this->startedAt) / 1e9 >= $this->timeout) {
            throw new MediaException('The media operation timed out.', $phase, 'timeout');
        }
    }

    public function version(string $binary, string $tool, string $directory, string $phase = 'probing'): string
    {
        $result = $this->run([$binary, '-version'], $directory, outputLimit: 65536, phase: $phase);

        if ($result->exitCode !== 0 || ! preg_match('/^'.preg_quote($tool, '/').' version (?:n)?(9\.0\.\d+)(?:\s|-|$)/', $result->output, $matches)) {
            throw new MediaException(($tool === 'ffprobe' ? 'FFprobe' : 'FFmpeg').' 9.0.x is required.', $phase, 'version');
        }

        return $matches[1];
    }

    /** The shared release of a matching FFmpeg and FFprobe 9.0.x pair. */
    public function pairVersion(string $ffmpeg, string $ffprobe, string $directory): string
    {
        $version = $this->version($ffmpeg, 'ffmpeg', $directory);

        if ($this->version($ffprobe, 'ffprobe', $directory) !== $version) {
            throw new MediaException('FFmpeg and FFprobe must have matching release versions.', 'probing', 'version');
        }

        return $version;
    }

    public function run(
        array $command,
        string $directory,
        array $redactions = [],
        int $outputLimit = 16777216,
        string $phase = 'probing',
        ?ProgressParser $progress = null,
        bool $redactOutput = false,
        ?float $timeout = null,
        ?callable $tick = null,
    ): ProcessResult {
        $output = '';
        $diagnostics = '';
        $overflow = false;
        $started = hrtime(true);
        $lastOutput = $started;
        $process = null;
        $failure = null;
        $applicationFailure = null;
        $captureFailure = null;

        try {
            $this->check($phase);
            // An internal callback is required at start: quietly() disables capture,
            // but Symfony still drains both pipes into this handler. It never calls
            // application code or throws before we retain the child handle.
            $process = Process::path($directory)
                ->env($this->environment($directory))
                ->forever()
                ->quietly()
                ->start($command, function (string $type, string $buffer) use (&$output, &$diagnostics, &$overflow, &$lastOutput, &$captureFailure, $outputLimit, $progress): void {
                    $lastOutput = hrtime(true);

                    if ($type === 'err') {
                        $diagnostics = substr($diagnostics.substr($buffer, -65536), -65536);
                    } elseif ($progress instanceof ProgressParser) {
                        try {
                            $progress->push($buffer);
                        } catch (Throwable $exception) {
                            $captureFailure ??= $exception;
                        }
                    } elseif (! $overflow) {
                        $overflow = strlen($output) + strlen($buffer) > $outputLimit;

                        if (! $overflow) {
                            $output .= $buffer;
                        }
                    }
                });

            do {
                $running = $process->running();

                if ($captureFailure instanceof Throwable) {
                    throw $captureFailure;
                }

                if ($overflow) {
                    throw new MediaException('Media process output exceeds the capture limit.', $phase, 'output_limit');
                }

                if ($tick !== null) {
                    try {
                        $tick();
                    } catch (Throwable $exception) {
                        $applicationFailure = $exception;

                        throw $exception;
                    }
                }

                $this->check($phase);
                $now = hrtime(true);
                $totalDeadline = config('laravel-ffmpeg.timeout', 3600);
                if ($timeout !== null) {
                    $totalDeadline = $totalDeadline === null ? $timeout : min($timeout, $totalDeadline);
                }

                $idleDeadline = config('laravel-ffmpeg.idle_timeout');
                if ($this->idleTimeout !== null) {
                    $idleDeadline = $idleDeadline === null ? $this->idleTimeout : min($idleDeadline, $this->idleTimeout);
                }

                if (($totalDeadline !== null && ($now - $started) / 1e9 >= $totalDeadline)
                    || ($idleDeadline !== null && ($now - $lastOutput) / 1e9 >= $idleDeadline)) {
                    throw new MediaException('The media process timed out.', $phase, 'timeout');
                }

                try {
                    $progress?->notify(! $running);
                } catch (Throwable $exception) {
                    $applicationFailure = $exception;

                    throw $exception;
                }

                if ($running) {
                    usleep(10000);
                }
            } while ($running);

            $result = $process->wait();

            // strtr() replaces the longest key first in one pass, so a short value inside a longer secret cannot expose the rest.
            return new ProcessResult(
                $result->exitCode(),
                $redactOutput ? strtr($output, $redactions) : $output,
                strtr($diagnostics, $redactions),
                (hrtime(true) - $started) / 1e9,
                array_map(fn (string $token): string => strtr($token, $redactions), $command),
                $phase,
            );
        } catch (Throwable $throwable) {
            $failure = $throwable instanceof MediaException || $throwable === $applicationFailure
                ? $throwable
                : new MediaException('Cannot execute the media process.', $phase, 'process');

            throw $failure;
        } finally {
            if ($process !== null) {
                try {
                    if ($process->running()) {
                        // Laravel's concrete process supports graceful stop. Its
                        // public contract exposes only signals and wait instead.
                        if (method_exists($process, 'stop')) {
                            $process->stop(1);
                        } else {
                            $process->signal(15);
                        }

                        $process->wait();
                    }
                } catch (Throwable) {
                    if (! $failure instanceof Throwable) {
                        throw new MediaException('Cannot stop the media process.', 'cleanup', 'process');
                    }
                }
            }
        }
    }

    private function environment(string $directory): array
    {
        $inherited = array_merge($_SERVER, $_ENV, getenv());
        $environment = array_fill_keys(array_keys($inherited), false);

        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'LANG', 'LC_ALL'] as $key) {
            if (isset($inherited[$key]) && is_string($inherited[$key])) {
                $environment[$key] = $inherited[$key];
            }
        }

        // Temporary files, including any created by the binary, stay private.
        return array_replace($environment, ['TMPDIR' => $directory, 'TMP' => $directory, 'TEMP' => $directory]);
    }
}
