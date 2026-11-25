<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ProcessResult;
use Symfony\Component\Process\Exception\ProcessSignaledException;
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
     * The oldest FFmpeg and FFprobe release the package runs.
     */
    protected const string MINIMUM_RELEASE = '9.0';

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
     * The process that is running.
     */
    protected ?InvokedProcess $process = null;

    /**
     * The workspace of the operation, deleted when the operation is terminated.
     */
    protected ?Workspace $workspace = null;

    /**
     * The signal that interrupted the operation.
     */
    protected ?int $interruptedBy = null;

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
     * Ensure the operation has not been interrupted or exceeded its time budget.
     *
     * The budget is shared by staging, probing, analysis and encoding.
     */
    public function check(string $phase): void
    {
        if ($this->interruptedBy !== null) {
            throw new MediaException(
                "The media operation was interrupted by signal [{$this->interruptedBy}].",
                $phase,
                'interrupted',
            );
        }

        if ($this->timeout !== null && (hrtime(true) - $this->startedAt) / 1e9 >= $this->timeout) {
            throw new MediaException('The media operation timed out.', $phase, 'timeout');
        }
    }

    /**
     * Set the workspace that is deleted when the operation is terminated.
     */
    public function useWorkspace(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    /**
     * Interrupt the operation with the given signal, so it fails at its next check.
     *
     * Terminating also stops the running process and deletes the workspace before returning.
     */
    public function interrupt(int $signal, bool $terminate = false): void
    {
        $this->interruptedBy ??= $signal;

        if (! $terminate) {
            return;
        }

        // A queue worker that times out kills itself as soon as the job returns from
        // its signal handler, so the operation never unwinds. We will stop the child
        // and delete the workspace here, which its own cleanup may safely repeat.
        try {
            if ($this->process instanceof InvokedProcess) {
                $this->stopProcess($this->process, null);
            }
        } finally {
            $this->workspace?->delete();
        }
    }

    /**
     * Get the release of the given binary, which must be the minimum release or later unless the check is disabled.
     */
    public function version(string $binary, string $tool, string $directory, string $phase = 'probing'): string
    {
        $result = $this->run([$binary, '-version'], $directory, outputLimit: 65536, phase: $phase);

        $this->ensureBinaryStarted($result, $tool);

        $release = $this->reportedRelease($tool, $result);

        // An application may disable the check to run an older build or a development
        // snapshot. We still report whatever version the binary prints, since doctor
        // displays it, but no longer refuse a binary because of the version alone.
        if (! config('laravel-ffmpeg.verify_version', true)) {
            return $release ?? $this->reportedVersion($tool, $result) ?? 'unknown';
        }

        if ($release === null || version_compare($release, self::MINIMUM_RELEASE, '<')) {
            throw new MediaException($this->unsupportedVersionMessage($tool, $result), $phase, 'version');
        }

        return $release;
    }

    /**
     * Get the numbered release that the version output of the given tool reports, if any.
     */
    protected function reportedRelease(string $tool, ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return null;
        }

        // Builds from a release tag prefix the release with an "n" and distributions
        // append their own revision, such as "9.0.2-1ubuntu1", so we will only keep
        // the numbers FFmpeg assigned. Development snapshots report none at all.
        $pattern = '/^'.preg_quote($tool, '/').' version n?(\d+\.\d+(?:\.\d+)?)(?:\s|-|$)/';

        if (! preg_match($pattern, $result->output, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Ensure the shell started the binary, since it exits with 126 or 127 when it cannot.
     */
    protected function ensureBinaryStarted(ProcessResult $result, string $tool): void
    {
        if (! in_array($result->exitCode, [126, 127], true)) {
            return;
        }

        throw new MediaException($this->launchFailureMessage($tool, $result), $result->phase, 'launch');
    }

    /**
     * Get the message for a binary that the shell could not start.
     */
    protected function launchFailureMessage(string $tool, ProcessResult $result): string
    {
        $name = $this->toolName($tool);

        if ($result->exitCode === 126) {
            return "The configured {$name} binary is not executable.";
        }

        // The dynamic linker also exits with 127 when the binary exists but cannot load one
        // of its shared libraries. Its error names the library, which holds no path, so we
        // will quote that name while the binary's own path stays out of the message.
        if (preg_match('/error while loading shared libraries: ([\w.+-]{1,128}):/', $result->diagnostics, $matches)) {
            return "The configured {$name} binary cannot load the shared library [{$matches[1]}].";
        }

        return "The configured {$name} binary cannot be found.";
    }

    /**
     * Get the message for a binary that does not report the minimum release or later.
     */
    protected function unsupportedVersionMessage(string $tool, ProcessResult $result): string
    {
        $requirement = $this->toolName($tool).' '.self::MINIMUM_RELEASE.' or later is required';

        $version = $this->reportedVersion($tool, $result);

        if ($version === null) {
            return "{$requirement}.";
        }

        return "{$requirement}, but the configured binary reports version [{$version}].";
    }

    /**
     * Get the version that the version output of the given tool reports, if it looks like one.
     */
    protected function reportedVersion(string $tool, ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return null;
        }

        // The version output also lists the build configuration, which may contain
        // paths, so we only keep a version that looks like a version number and
        // leave the remaining output out of the messages and reports entirely.
        $pattern = '/^'.preg_quote($tool, '/').' version ([\w.+~-]{1,64})(?:\s|$)/';

        if (! preg_match($pattern, $result->output, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Get the display name of the given tool.
     */
    protected function toolName(string $tool): string
    {
        return $tool === 'ffprobe' ? 'FFprobe' : 'FFmpeg';
    }

    /**
     * Get the releases of the FFmpeg and FFprobe pair, which must match unless the check is disabled.
     *
     * @return array{ffmpeg: string, ffprobe: string}
     */
    public function pairVersions(string $ffmpeg, string $ffprobe, string $directory): array
    {
        $version = $this->version($ffmpeg, 'ffmpeg', $directory);
        $probeVersion = $this->version($ffprobe, 'ffprobe', $directory);

        if (config('laravel-ffmpeg.verify_version', true) && $probeVersion !== $version) {
            throw MediaException::probing(
                "FFmpeg [{$version}] and FFprobe [{$probeVersion}] must have matching release versions.",
                'version',
            );
        }

        return ['ffmpeg' => $version, 'ffprobe' => $probeVersion];
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
            $this->process = $process;

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
            $failure = $this->failure($throwable, $phase, $redactions);

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
     * Get the exception that reports the given failure of the running process.
     */
    protected function failure(Throwable $throwable, string $phase, array $redactions): Throwable
    {
        if ($throwable instanceof MediaException || $throwable === $this->applicationFailure) {
            return $throwable;
        }

        // A signal we did not send ourselves, such as SIGKILL from the out-of-memory killer,
        // ends the process without an exit code. We will name the signal and keep the
        // diagnostics the binary wrote before it was stopped.
        if ($throwable instanceof ProcessSignaledException) {
            return new MediaException(
                "The media process was terminated by signal [{$throwable->getSignal()}].",
                $phase,
                'signal',
                strtr($this->capture['diagnostics'], $redactions),
            );
        }

        return new MediaException('Cannot execute the media process.', $phase, 'process');
    }

    /**
     * Reset the capture and supervision state for a process started at the given time.
     */
    protected function beginTracking(
        int|float $started,
        ?float $timeout,
        ?ProgressParser $progress,
        ?callable $tick,
    ): void {
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
        $this->process = null;
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

        if ($this->deadlineHasPassed($totalDeadline, $this->processStartedAt, $now)
            || $this->deadlineHasPassed($idleDeadline, $this->capture['lastOutput'], $now)) {
            throw new MediaException('The media process timed out.', $phase, 'timeout');
        }
    }

    /**
     * Determine if the given deadline in seconds, if any, has passed since the given high-resolution time.
     */
    protected function deadlineHasPassed(mixed $deadline, int|float $since, int|float $now): bool
    {
        return $deadline !== null && ($now - $since) / 1e9 >= $deadline;
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
