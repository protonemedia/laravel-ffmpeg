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

    protected function fakeCapabilities(string $probeVersion = '9.0.2', ?string $brokenOption = null): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($probeVersion, $brokenOption) {
            $command = $process->command;
            $option = end($command);

            if ($option === $brokenOption) {
                return Process::result(exitCode: 1, errorOutput: 'secret-token /private/binary');
            }

            if ($option === '-version') {
                return Process::result(
                    output: $command[0].' version '.($command[0] === 'ffprobe' ? $probeVersion : '9.0.2'),
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

    #[TestWith(['9.0.1'])]
    #[TestWith(['8.0.2'])]
    public function test_mismatched_and_wrong_major_versions_fail_sanitized(string $version): void
    {
        $this->fakeCapabilities(probeVersion: $version);

        $this->assertThrows(fn () => new Capabilities, function (MediaException $exception) {
            $this->assertSame('version', $exception->category);
            $this->assertSame('', $exception->diagnostics);

            return true;
        });
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
        $probe = $this->root.'/ffprobe';

        file_put_contents($probe, sprintf(<<<'PHP'
            #!%s
            <?php echo 'ffprobe version 9.0.2';
            PHP, PHP_BINARY));

        chmod($probe, 0700);

        return $probe;
    }

    public function test_real_capture_overflow_fails_without_leaking_paths(): void
    {
        $binary = $this->root.'/ffmpeg';
        file_put_contents($binary, sprintf(<<<'PHP'
            #!%s
            <?php
            if (in_array("-version", $argv, true)) { echo "ffmpeg version 9.0.2"; exit; } echo str_repeat("X", 3000000);
            PHP, PHP_BINARY));
        chmod($binary, 0700);
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
