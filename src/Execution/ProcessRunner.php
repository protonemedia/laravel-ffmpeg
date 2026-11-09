<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ProcessResult;
use Throwable;

/** @internal */
class ProcessRunner
{
    protected readonly int $startedAt;

    /**
     * The output captured from the running process.
     *
     * @var array{output: string, diagnostics: string, overflow: bool, lastOutput: int|float, failure: ?Throwable}|null
     */
    protected ?array $capture = null;

    /**
     * The exception thrown by an application callback while the process runs.
     */
    protected ?Throwable $applicationFailure = null;

    public function __construct(protected readonly ?float $timeout = null, protected readonly ?float $idleTimeout = null)
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
            throw MediaException::probing('FFmpeg and FFprobe must have matching release versions.', 'version');
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
        $started = hrtime(true);
        $process = null;
        $failure = null;
        $this->capture = ['output' => '', 'diagnostics' => '', 'overflow' => false, 'lastOutput' => $started, 'failure' => null];
        $this->applicationFailure = null;

        try {
            $this->check($phase);
            $process = $this->startProcess($command, $directory, $outputLimit, $progress);
            $this->supervise($process, $phase, $started, $progress, $timeout, $tick);
            $result = $process->wait();

            // strtr() replaces the longest key first in one pass, so a short value inside a longer secret cannot expose the rest.
            return new ProcessResult(
                $result->exitCode(),
                $redactOutput ? strtr($this->capture['output'], $redactions) : $this->capture['output'],
                strtr($this->capture['diagnostics'], $redactions),
                (hrtime(true) - $started) / 1e9,
                array_map(fn (string $token): string => strtr($token, $redactions), $command),
                $phase,
            );
        } catch (Throwable $throwable) {
            $failure = $throwable instanceof MediaException || $throwable === $this->applicationFailure
                ? $throwable
                : new MediaException('Cannot execute the media process.', $phase, 'process');

            throw $failure;
        } finally {
            try {
                if ($process instanceof InvokedProcess) {
                    $this->stopProcess($process, $failure);
                }
            } finally {
                $this->capture = null;
                $this->applicationFailure = null;
            }
        }
    }

    /**
     * Start the process with a handler that captures its output.
     */
    protected function startProcess(array $command, string $directory, int $outputLimit, ?ProgressParser $progress): InvokedProcess
    {
        // An internal callback is required at start: quietly() disables capture,
        // but Symfony still drains both pipes into this handler. It never calls
        // application code or throws before we retain the child handle.
        return Process::path($directory)
            ->env($this->environment($directory))
            ->forever()
            ->quietly()
            ->start($command, function (string $type, string $buffer) use ($outputLimit, $progress): void {
                $this->captureOutput($type, $buffer, $outputLimit, $progress);
            });
    }

    /**
     * Capture a chunk of process output, diagnostics or progress.
     */
    protected function captureOutput(string $type, string $buffer, int $outputLimit, ?ProgressParser $progress): void
    {
        $this->capture['lastOutput'] = hrtime(true);

        if ($type === 'err') {
            $this->capture['diagnostics'] = substr($this->capture['diagnostics'].substr($buffer, -65536), -65536);
        } elseif ($progress instanceof ProgressParser) {
            try {
                $progress->push($buffer);
            } catch (Throwable $exception) {
                $this->capture['failure'] ??= $exception;
            }
        } elseif (! $this->capture['overflow']) {
            $this->capture['overflow'] = strlen($this->capture['output']) + strlen($buffer) > $outputLimit;

            if (! $this->capture['overflow']) {
                $this->capture['output'] .= $buffer;
            }
        }
    }

    /**
     * Poll the process until it exits, enforcing the capture limit and deadlines.
     */
    protected function supervise(InvokedProcess $process, string $phase, int|float $started, ?ProgressParser $progress, ?float $timeout, ?callable $tick): void
    {
        do {
            $running = $process->running();

            $this->ensureCaptureSucceeded($phase);

            if ($tick !== null) {
                $this->callApplication($tick);
            }

            $this->check($phase);
            $this->ensureWithinDeadlines($phase, $started, $timeout);
            $this->callApplication(fn () => $progress?->notify(! $running));

            if ($running) {
                usleep(10000);
            }
        } while ($running);
    }

    /**
     * Ensure the output handler has not failed or exceeded the capture limit.
     */
    protected function ensureCaptureSucceeded(string $phase): void
    {
        if ($this->capture['failure'] instanceof Throwable) {
            throw $this->capture['failure'];
        }

        if ($this->capture['overflow']) {
            throw new MediaException('Media process output exceeds the capture limit.', $phase, 'output_limit');
        }
    }

    /**
     * Call application code, remembering any exception so it reaches the caller unchanged.
     */
    protected function callApplication(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            $this->applicationFailure = $throwable;

            throw $throwable;
        }
    }

    /**
     * Ensure the process has not exceeded its total or idle deadline.
     */
    protected function ensureWithinDeadlines(string $phase, int|float $started, ?float $timeout): void
    {
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
            || ($idleDeadline !== null && ($now - $this->capture['lastOutput']) / 1e9 >= $idleDeadline)) {
            throw new MediaException('The media process timed out.', $phase, 'timeout');
        }
    }

    /**
     * Stop the process if it is still running.
     */
    protected function stopProcess(InvokedProcess $process, ?Throwable $failure): void
    {
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
                throw MediaException::cleanup('Cannot stop the media process.', 'process');
            }
        }
    }

    protected function environment(string $directory): array
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
