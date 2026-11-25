<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Capabilities;
use ProtoneMedia\LaravelFFMpeg\Console\DoctorCommand;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class CapabilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['laravel-ffmpeg.ffmpeg.binaries' => 'ffmpeg', 'laravel-ffmpeg.ffprobe.binaries' => 'ffprobe']);
        $this->app->make(Kernel::class)
            ->registerCommand(new DoctorCommand);
    }

    protected function fakeCapabilities(
        string $version = '9.0.2',
        ?string $probeVersion = null,
        ?string $brokenOption = null,
    ): void {
        $probeVersion ??= $version;

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($version, $probeVersion, $brokenOption) {
            $command = $process->command;
            $option = end($command);

            if ($option === $brokenOption) {
                return Process::result(exitCode: 1, errorOutput: 'secret-token /private/binary');
            }

            if ($option === '-version') {
                return Process::result(
                    output: $command[0].' version '.($command[0] === 'ffprobe' ? $probeVersion : $version),
                );
            }

            return Process::result(output: match ($option) {
                '-encoders' => <<<'TEXT'
                    Encoders:
                     V..... = Video
                     V....D mpeg4 MPEG-4
                     A..... aac AAC

                    TEXT,
                '-muxers' => <<<'TEXT'
                    Formats:
                     .E. = Muxing supported
                      E  mov,mp4 QuickTime

                    TEXT,
                '-filters' => <<<'TEXT'
                    Filters:
                     TS scale V->V Scale
                     .. null V->V Pass through

                    TEXT,
                '-protocols' => <<<'TEXT'
                    Supported file protocols:
                    Input:
                     file
                     https
                    Output:
                     file

                    TEXT,
                '-bsfs' => <<<'TEXT'
                    Bitstream filters:
                    h264_mp4toannexb
                    aac_adtstoasc

                    TEXT,
                '-hwaccels' => <<<'TEXT'
                    Hardware acceleration methods:
                    cuda
                    vaapi

                    TEXT,
            });
        });
    }

    public function test_public_api_reports_lists_and_requirement_checks_without_claiming_gpu_usability(): void
    {
        $this->fakeCapabilities();
        $capabilities = new Capabilities;

        $this->assertInstanceOf(Capabilities::class, $capabilities);
        $this->assertSame(['ffmpeg' => '9.0.2', 'ffprobe' => '9.0.2'], $capabilities->versions());
        $this->assertSame(['aac', 'mpeg4'], $capabilities->available('encoders'));
        $this->assertFalse($capabilities->toArray()['hardware']['device_usability_tested']);
        $this->assertTrue($capabilities->toArray()['temporary_root']['writable']);
        $this->assertSame(['encoders' => ['missing']], $capabilities->missing(['encoders' => ['aac', 'missing']]));
        $this->assertSame($capabilities, $capabilities->require(['filters' => ['scale']]));
        $this->assertSame($capabilities->toArray(), json_decode(json_encode($capabilities), true));
        $this->assertWorkspacesAreCleanedUp();
        $this->assertInstanceOf(Capabilities::class, FFMpeg::capabilities());
        $this->expectException(MediaException::class);

        $capabilities->require(['encoders' => ['absent']]);
    }

    public static function capabilityProvider(): array
    {
        return [
            'encoders' => ['encoders', 'mpeg4'],
            'muxers' => ['muxers', 'mp4'],
            'filters' => ['filters', 'scale'],
            'input protocols' => ['input_protocols', 'https'],
            'output protocols' => ['output_protocols', 'file'],
            'bitstream filters' => ['bitstream_filters', 'aac_adtstoasc'],
            'hardware accelerators' => ['hardware_accelerators', 'cuda'],
            'ffprobe input protocols' => ['ffprobe_input_protocols', 'file'],
        ];
    }

    #[DataProvider('capabilityProvider')]
    public function test_capabilities_are_parsed_from_the_binary_listings(string $category, string $name): void
    {
        $this->fakeCapabilities();

        $this->assertTrue((new Capabilities)->has($category, $name), $category);
    }

    public function test_doctor_has_human_and_json_reports_and_missing_requirements_fail(): void
    {
        $this->fakeCapabilities();

        $this->assertSame(0, Artisan::call('ffmpeg:doctor', ['--encoder' => ['mpeg4']]));
        $this->assertStringContainsString('GPU/device usability is NOT tested', Artisan::output());
        $this->assertSame(1, Artisan::call('ffmpeg:doctor', ['--json' => true, '--encoder' => ['not_present']]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['encoders' => ['not_present']], $report['missing']);
        $this->assertFalse($report['hardware']['device_usability_tested']);
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['database', 90, true])]
    #[TestWith(['database', 3631, false])]
    #[TestWith(['sync', 90, false])]
    public function test_doctor_warns_when_retry_after_does_not_exceed_the_job_timeout(
        string $driver,
        int $retryAfter,
        bool $warns,
    ): void {
        $this->fakeCapabilities();
        config(['queue.default' => 'media', 'queue.connections.media' => [
            'driver' => $driver, 'retry_after' => $retryAfter,
        ]]);

        $this->assertSame(0, Artisan::call('ffmpeg:doctor'));
        $this->assertSame($warns, str_contains(
            preg_replace('/\s+/', ' ', Artisan::output()),
            "The retry_after [{$retryAfter}] of the [media] connection must exceed the job timeout [3630].",
        ));
    }

    #[TestWith(['9.0', '9.0'])]
    #[TestWith(['n9.0', '9.0'])]
    #[TestWith(['9.0.3', '9.0.3'])]
    #[TestWith(['9.1.0', '9.1.0'])]
    #[TestWith(['10.0', '10.0'])]
    public function test_releases_from_9_0_onward_are_accepted(string $reported, string $release): void
    {
        $this->fakeCapabilities(version: $reported);

        $this->assertSame(['ffmpeg' => $release, 'ffprobe' => $release], (new Capabilities)->toArray()['versions']);
    }

    #[TestWith(['9.0.1', 'FFmpeg [9.0.2] and FFprobe [9.0.1] must have matching release versions.'])]
    #[TestWith(['8.0.2', 'FFprobe 9.0 or later is required, but the configured binary reports version [8.0.2].'])]
    #[TestWith([
        "5.1.9-0+deb12u1 Copyright (c) 2007-2026\nconfiguration: --prefix=/private/build",
        'FFprobe 9.0 or later is required, but the configured binary reports version [5.1.9-0+deb12u1].',
    ])]
    #[TestWith(['/private/build/ffprobe', 'FFprobe 9.0 or later is required.'])]
    #[TestWith([
        'N-120345-g1a2b3c4d5e',
        'FFprobe 9.0 or later is required, but the configured binary reports version [N-120345-g1a2b3c4d5e].',
    ])]
    public function test_mismatched_and_wrong_major_versions_fail_sanitized(string $version, string $message): void
    {
        $this->fakeCapabilities(probeVersion: $version);

        $this->assertThrows(fn () => new Capabilities, function (MediaException $exception) use ($message) {
            $this->assertSame('version', $exception->category);
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame('', $exception->diagnostics);

            return true;
        });
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['missing', 'The configured FFmpeg binary cannot be found.'])]
    #[TestWith(['not-executable', 'The configured FFmpeg binary is not executable.'])]
    #[TestWith(['without-library', 'The configured FFmpeg binary cannot load the shared library [libavdevice.so.62].'])]
    public function test_binaries_that_cannot_start_report_the_launch_category(string $binary, string $message): void
    {
        file_put_contents("{$this->root}/not-executable", "#!/bin/sh\n");
        chmod("{$this->root}/not-executable", 0600);

        $this->fakeBinary("{$this->root}/without-library", <<<'PHP'
            fwrite(STDERR, "/private/build/ffmpeg: error while loading shared libraries: libavdevice.so.62: "
                ."cannot open shared object file: No such file or directory\n");
            exit(127);
            PHP);

        config(['laravel-ffmpeg.ffmpeg.binaries' => "{$this->root}/{$binary}"]);

        $this->assertSame(1, Artisan::call('ffmpeg:doctor', ['--json' => true]));
        $this->assertSame(
            ['error' => $message, 'category' => 'launch'],
            json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_failed_capture_is_reported_without_binary_details(): void
    {
        $this->fakeCapabilities(brokenOption: '-encoders');

        $this->assertSame(1, Artisan::call('ffmpeg:doctor', ['--json' => true]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('capability', $report['category']);
        $this->assertStringNotContainsString('secret-token', Artisan::output());
        $this->assertStringNotContainsString('/private/binary', Artisan::output());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_missing_temporary_root_is_reported_without_its_path(): void
    {
        $this->fakeCapabilities();
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/absent/root']);

        $this->assertSame(1, Artisan::call('ffmpeg:doctor', ['--json' => true]));
        $this->assertSame('filesystem', json_decode(Artisan::output(), true)['category']);
        $this->assertStringNotContainsString($this->root, Artisan::output());
    }

    /**
     * Write an FFprobe stand-in that only answers the version command and get its path.
     */
    protected function versionOnlyProbe(): string
    {
        return $this->fakeBinary($this->root.'/ffprobe', "echo 'ffprobe version 9.0.2';");
    }

    public function test_real_capture_overflow_fails_without_leaking_paths(): void
    {
        $binary = $this->fakeBinary($this->root.'/ffmpeg', <<<'PHP'
            if (in_array("-version", $argv, true)) { echo "ffmpeg version 9.0.2"; exit; } echo str_repeat("X", 3000000);
            PHP);

        // Both version commands can use this controlled executable; FFprobe's
        // separate version response is supplied by the second script.
        $probe = $this->versionOnlyProbe();
        config(['laravel-ffmpeg.ffmpeg.binaries' => $binary, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);

        $this->assertSame(1, Artisan::call('ffmpeg:doctor', ['--json' => true]));
        $this->assertSame('output_limit', json_decode(Artisan::output(), true)['category']);
        $this->assertStringNotContainsString($this->root, Artisan::output());
        $this->assertSame([], glob($this->root.'/workspaces/laravel-ffmpeg-*'));
    }

    public function test_missing_binary_fails_without_leaking_paths(): void
    {
        $probe = $this->versionOnlyProbe();
        config([
            'laravel-ffmpeg.ffmpeg.binaries' => $this->root.'/missing-private-binary',
            'laravel-ffmpeg.ffprobe.binaries' => $probe,
        ]);

        $this->assertSame(1, Artisan::call('ffmpeg:doctor', ['--json' => true]));
        $this->assertStringNotContainsString($this->root, Artisan::output());
        $this->assertStringNotContainsString('missing-private-binary', Artisan::output());
        $this->assertSame([], glob($this->root.'/workspaces/laravel-ffmpeg-*'));
    }

    public function test_native_binaries_expose_real_compiled_in_capabilities_and_the_command_runs(): void
    {
        $this->useRealFFMpeg();
        $capabilities = new Capabilities;

        $this->assertMatchesRegularExpression('/\A9\.0\.\d+\z/', $capabilities->versions()['ffmpeg']);
        $this->assertSame(
            [],
            $capabilities->missing(
                [
                    'encoders' => ['mpeg4', 'aac'],
                    'muxers' => ['mp4', 'hls'],
                    'filters' => ['scale', 'loudnorm'],
                    'bitstream_filters' => ['aac_adtstoasc'],
                    'input_protocols' => ['file'],
                ],
            ),
        );
        $this->assertFalse($capabilities->toArray()['hardware']['device_usability_tested']);
        $this->assertSame(0, Artisan::call('ffmpeg:doctor', ['--json' => true, '--protocol' => ['file']]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $report['missing']);
        $this->assertTrue($report['temporary_root']['writable']);
        $this->assertWorkspacesAreCleanedUp();
    }
}
