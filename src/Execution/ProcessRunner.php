<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ProcessResult;
use Throwable;

/**
 * Runs FFmpeg and FFprobe processes within the operation's deadlines and capture limits.
 *
 * @internal
 */
class ProcessRunner
{
    /**
     * The default maximum size in bytes of captured process output.
     */
    public const int MAX_OUTPUT_BYTES = 16777216;

    /**
     * The number of trailing diagnostic bytes we keep from standard error.
     */
    protected const int MAX_DIAGNOSTIC_BYTES = 65536;

    /**
     * The high-resolution time the operation started, in nanoseconds.
     */
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

    /**
     * The high-resolution time the running process started, in nanoseconds.
     */
    protected int|float|null $processStartedAt = null;

    /**
     * The deadline in seconds of the running process.
     */
    protected ?float $processTimeout = null;

    /**
     * The parser that receives the progress of the running process.
     */
    protected ?ProgressParser $progress = null;

    /**
     * The callback invoked each time the running process is polled.
     */
    protected ?Closure $tick = null;

    /**
     * Create a new process runner instance.
     */
    public function __construct(
        protected readonly ?float $timeout = null,
        protected readonly ?float $idleTimeout = null,
    ) {
        foreach ([$timeout, $idleTimeout] as $seconds) {
            if ($seconds !== null && (! is_finite($seconds) || $seconds <= 0)) {
                throw new InvalidArgumentException('Process deadlines must be finite, positive seconds or null.');
            }
        }

        $this->startedAt = hrtime(true);
    }

    /**
     * Ensure the operation has not exceeded its time budget.
     *
     * The budget is shared by staging, probing, analysis and encoding.
     */
    public function check(string $phase): void
    {
        if ($this->timeout !== null && (hrtime(true) - $this->startedAt) / 1e9 >= $this->timeout) {
            throw new MediaException('The media operation timed out.', $phase, 'timeout');
        }
    }

    /**
     * Get the 9.0.x release of the given binary.
     */
    public function version(string $binary, string $tool, string $directory, string $phase = 'probing'): string
    {
        $result = $this->run([$binary, '-version'], $directory, outputLimit: 65536, phase: $phase);

        if ($result->exitCode !== 0
            || ! preg_match(
                '/^'.preg_quote($tool, '/').' version (?:n)?(9\.0\.\d+)(?:\s|-|$)/',
                $result->output,
                $matches,
            )) {
            throw new MediaException(
                ($tool === 'ffprobe' ? 'FFprobe' : 'FFmpeg').' 9.0.x is required.',
                $phase,
                'version',
            );
        }

        return $matches[1];
    }

    /**
     * Get the shared release of a matching FFmpeg and FFprobe 9.0.x pair.
     */
    public function pairVersion(string $ffmpeg, string $ffprobe, string $directory): string
    {
        $version = $this->version($ffmpeg, 'ffmpeg', $directory);

        if ($this->version($ffprobe, 'ffprobe', $directory) !== $version) {
            throw MediaException::probing('FFmpeg and FFprobe must have matching release versions.', 'version');
        }

        return $version;
    }

    /**
     * Run the given command and get its redacted result.
     */
    public function run(
        array $command,
        string $directory,
        array $redactions = [],
        int $outputLimit = self::MAX_OUTPUT_BYTES,
        string $phase = 'probing',
        ?ProgressParser $progress = null,
        bool $redactOutput = false,
        ?float $timeout = null,
        ?callable $tick = null,
    ): ProcessResult {
        $started = hrtime(true);
        $process = null;
        $failure = null;

        $this->beginTracking($started, $timeout, $progress, $tick);

        try {
            $this->check($phase);

            $process = $this->startProcess($command, $directory, $outputLimit, $progress);

            $this->supervise($process, $phase);

            $result = $process->wait();

            // The strtr() function replaces the longest key first in a single pass, so a
            // short value that occurs inside a longer secret cannot expose the rest of it.
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
                $this->stopTracking();
            }
        }
    }

    /**
     * Reset the capture and supervision state for a process started at the given time.
     */
    protected function beginTracking(int|float $started, ?float $timeout, ?ProgressParser $progress, ?callable $tick): void
    {
        $this->capture = [
            'output' => '',
            'diagnostics' => '',
            'overflow' => false,
            'lastOutput' => $started,
            'failure' => null,
        ];
        $this->applicationFailure = null;
        $this->processStartedAt = $started;
        $this->processTimeout = $timeout;
        $this->progress = $progress;
        $this->tick = $tick === null ? null : $tick(...);
    }

    /**
     * Forget the capture and supervision state of the finished process.
     */
    protected function stopTracking(): void
    {
        $this->capture = null;
        $this->applicationFailure = null;
        $this->processStartedAt = null;
        $this->processTimeout = null;
        $this->progress = null;
        $this->tick = null;
    }

    /**
     * Start the process with a handler that captures its output.
     */
    protected function startProcess(
        array $command,
        string $directory,
        int $outputLimit,
        ?ProgressParser $progress,
    ): InvokedProcess {
        // The internal callback must be given at start. While quietly() disables capture,
        // Symfony still drains both pipes into this handler. The handler never calls
        // application code or throws before we retain the child process handle.
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
            $this->capture['diagnostics'] = substr(
                $this->capture['diagnostics'].substr($buffer, -self::MAX_DIAGNOSTIC_BYTES),
                -self::MAX_DIAGNOSTIC_BYTES,
            );
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
    protected function supervise(InvokedProcess $process, string $phase): void
    {
        do {
            $running = $process->running();

            $this->ensureCaptureSucceeded($phase);

            if ($this->tick instanceof Closure) {
                $this->callApplication($this->tick);
            }

            $this->check($phase);
            $this->ensureWithinDeadlines($phase);
            $this->callApplication(fn () => $this->progress?->notify(! $running));

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
    protected function ensureWithinDeadlines(string $phase): void
    {
        $now = hrtime(true);
        $totalDeadline = config('laravel-ffmpeg.timeout', 3600);

        if ($this->processTimeout !== null) {
            $totalDeadline = $totalDeadline === null
                ? $this->processTimeout
                : min($this->processTimeout, $totalDeadline);
        }

        $idleDeadline = config('laravel-ffmpeg.idle_timeout');

        if ($this->idleTimeout !== null) {
            $idleDeadline = $idleDeadline === null ? $this->idleTimeout : min($idleDeadline, $this->idleTimeout);
        }

        if (($totalDeadline !== null && ($now - $this->processStartedAt) / 1e9 >= $totalDeadline)
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
                // Laravel's concrete process supports a graceful stop, but its public
                // contract only exposes signals and waiting, so we fall back to SIGTERM.
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

    /**
     * Get the minimal environment for a process running in the given directory.
     */
    protected function environment(string $directory): array
    {
        $inherited = array_merge($_SERVER, $_ENV, getenv());
        $environment = array_fill_keys(array_keys($inherited), false);

        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'LANG', 'LC_ALL'] as $key) {
            if (isset($inherited[$key]) && is_string($inherited[$key])) {
                $environment[$key] = $inherited[$key];
            }
        }

        // We point the temporary directory variables at the workspace so that temporary
        // files, including any the binary creates, stay private to this operation.
        return array_replace($environment, ['TMPDIR' => $directory, 'TMP' => $directory, 'TEMP' => $directory]);
    }
}
