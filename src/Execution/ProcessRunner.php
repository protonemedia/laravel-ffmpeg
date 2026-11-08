<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Illuminate\Support\Facades\Process;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ProcessResult;
use Throwable;

/** @internal */
class ProcessRunner
{
    public function version(string $binary, string $tool, string $directory): string
    {
        $result = $this->run([$binary, '-version'], $directory, outputLimit: 65536);

        if ($result->exitCode !== 0 || ! preg_match('/^'.preg_quote($tool, '/').' version (?:n)?(9\.0\.\d+)(?:\s|-|$)/', $result->output, $matches)) {
            throw new MediaException(($tool === 'ffprobe' ? 'FFprobe' : 'FFmpeg').' 9.0.x is required.', 'probing', 'version');
        }

        return $matches[1];
    }

    public function run(
        array $command,
        string $directory,
        array $redactions = [],
        int $outputLimit = 16777216,
        string $phase = 'probing',
        ?ProgressParser $progress = null,
        ?callable $onProgress = null,
        bool $redactOutput = false,
        ?float $timeout = null,
    ): ProcessResult {
        $output = $diagnostics = '';
        $overflow = false;
        $started = $lastOutput = hrtime(true);
        $lastNotification = 0;
        $process = null;
        $failure = null;
        $applicationFailure = null;
        $captureFailure = null;

        try {
            // An internal callback is required at start: quietly() disables capture,
            // but Symfony still drains both pipes into this handler. It never calls
            // application code or throws before we retain the child handle.
            $process = Process::path($directory)
                ->env($this->environment($directory))
                ->forever()
                ->quietly()
                ->start($command, function (string $type, string $buffer) use (&$output, &$diagnostics, &$overflow, &$lastOutput, &$captureFailure, $outputLimit, $progress) {
                    $lastOutput = hrtime(true);

                    if ($type === 'err') {
                        $diagnostics = substr($diagnostics.substr($buffer, -65536), -65536);
                    } elseif ($progress !== null) {
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

                if ($captureFailure !== null) {
                    throw $captureFailure;
                }

                if ($overflow) {
                    throw new MediaException('Media process output exceeds the capture limit.', $phase, 'output_limit');
                }

                $now = hrtime(true);
                $totalDeadline = config('laravel-ffmpeg.timeout', 3600);
                if ($timeout !== null) {
                    $totalDeadline = $totalDeadline === null ? $timeout : min($timeout, $totalDeadline);
                }
                $idleDeadline = config('laravel-ffmpeg.idle_timeout');

                if (($totalDeadline !== null && ($now - $started) / 1e9 >= $totalDeadline)
                    || ($idleDeadline !== null && ($now - $lastOutput) / 1e9 >= $idleDeadline)) {
                    throw new MediaException('The media process timed out.', $phase, 'timeout');
                }

                if ($progress !== null && $onProgress !== null && (! $running || $now - $lastNotification >= 100000000)) {
                    if ($snapshot = $progress->take()) {
                        try {
                            $onProgress($snapshot);
                        } catch (Throwable $exception) {
                            $applicationFailure = $exception;

                            throw $exception;
                        }

                        $lastNotification = $now;
                    }
                }

                if ($running) {
                    usleep(10000);
                }
            } while ($running);

            $result = $process->wait();

            return new ProcessResult(
                $result->exitCode(),
                $redactOutput ? str_replace(array_keys($redactions), array_values($redactions), $output) : $output,
                str_replace(array_keys($redactions), array_values($redactions), $diagnostics),
                (hrtime(true) - $started) / 1e9,
                array_map(fn (string $token) => str_replace(array_keys($redactions), array_values($redactions), $token), $command),
                $phase,
            );
        } catch (Throwable $exception) {
            $failure = $exception instanceof MediaException || $exception === $applicationFailure
                ? $exception
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
                    if ($failure === null) {
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
