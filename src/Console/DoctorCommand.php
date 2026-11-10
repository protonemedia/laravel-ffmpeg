<?php

namespace ProtoneMedia\LaravelFFMpeg\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Capabilities;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Check the installed FFmpeg binaries and the temporary storage.
 */
#[AsCommand(name: 'ffmpeg:doctor')]
class DoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ffmpeg:doctor
                            {--json : Print the complete structured report}
                            {--encoder=* : Required compiled-in encoder}
                            {--muxer=* : Required muxer}
                            {--filter=* : Required filter}
                            {--protocol=* : Required input protocol in both binaries}
                            {--bsf=* : Required bitstream filter}
                            {--hwaccel=* : Required compiled-in hardware accelerator}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check matching FFmpeg/FFprobe 9.0.x, compiled-in features, and temporary storage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $capabilities = new Capabilities;

            $missing = $capabilities->missing($this->requirements());

            $this->option('json')
                ? $this->displayJson($capabilities, $missing)
                : $this->displayReport($capabilities, $missing);

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        } catch (MediaException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(
                    ['error' => $exception->getMessage(), 'category' => $exception->category],
                    JSON_THROW_ON_ERROR,
                ));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        } catch (InvalidArgumentException) {
            $this->option('json')
                ? $this->error('Invalid capability requirement.')
                : $this->components->error('Invalid capability requirement.');

            return self::FAILURE;
        }
    }

    /**
     * Get the required capabilities from the command options, keyed by category.
     */
    protected function requirements(): array
    {
        return [
            'encoders' => $this->option('encoder'),
            'muxers' => $this->option('muxer'),
            'filters' => $this->option('filter'),
            'input_protocols' => $this->option('protocol'),
            'bitstream_filters' => $this->option('bsf'),
            'hardware_accelerators' => $this->option('hwaccel'),
            'ffprobe_input_protocols' => $this->option('protocol'),
        ];
    }

    /**
     * Display the complete structured report for the given capabilities.
     */
    protected function displayJson(Capabilities $capabilities, array $missing): void
    {
        $this->line(json_encode(
            $capabilities->toArray() + ['missing' => $missing],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Display the human-readable report for the given capabilities.
     */
    protected function displayReport(Capabilities $capabilities, array $missing): void
    {
        foreach ($capabilities->versions() as $tool => $version) {
            $this->components->twoColumnDetail($tool, $version);
        }

        foreach ([
            'encoders', 'muxers', 'filters', 'input_protocols', 'output_protocols',
            'bitstream_filters', 'hardware_accelerators',
        ] as $category) {
            $this->components->twoColumnDetail($category, (string) count($capabilities->available($category)));
        }

        $this->components->info('The temporary root is writable.');

        // The listings only prove that FFmpeg was compiled with a feature. A hardware
        // accelerator may still fail at runtime when the GPU or device is missing, so
        // we make that limit explicit instead of letting the report imply otherwise.
        $this->components->warn('Compiled-in support only. GPU/device usability is NOT tested.');

        if ($missing !== []) {
            $this->components->error('Required compiled-in media capabilities are missing. Use --json for the list.');
        }
    }
}
