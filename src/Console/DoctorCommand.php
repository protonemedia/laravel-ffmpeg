<?php

namespace ProtoneMedia\LaravelFFMpeg\Console;

use Illuminate\Console\Command;
use ProtoneMedia\LaravelFFMpeg\Capabilities;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/**
 * Check the installed FFmpeg binaries and the temporary storage.
 */
class DoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ffmpeg:doctor {--json : Print the complete structured report}
        {--encoder=* : Required compiled-in encoder} {--muxer=* : Required muxer}
        {--filter=* : Required filter} {--protocol=* : Required input protocol in both binaries}
        {--bsf=* : Required bitstream filter} {--hwaccel=* : Required compiled-in hardware accelerator}';

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
            $options = [
                'encoder' => 'encoders', 'muxer' => 'muxers', 'filter' => 'filters',
                'protocol' => 'input_protocols', 'bsf' => 'bitstream_filters', 'hwaccel' => 'hardware_accelerators',
            ];

            $requirements = collect($options)
                ->mapWithKeys(fn (string $category, string $option): array => [$category => $this->option($option)])
                ->all();

            $requirements['ffprobe_input_protocols'] = $this->option('protocol');
            $missing = $capabilities->missing($requirements);
            if ($this->option('json')) {
                $this->line(json_encode(
                    $capabilities->toArray() + ['missing' => $missing],
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                ));
            } else {
                foreach ($capabilities->versions() as $tool => $version) {
                    $this->line("{$tool}: {$version}");
                }

                foreach ([
                    'encoders', 'muxers', 'filters', 'input_protocols', 'output_protocols',
                    'bitstream_filters', 'hardware_accelerators',
                ] as $category) {
                    $this->line("{$category}: ".count($capabilities->available($category)));
                }

                $this->info('Temporary root: writable');
                $this->warn('Compiled-in support only. GPU/device usability is NOT tested.');
                if ($missing !== []) {
                    $this->error('Required compiled-in media capabilities are missing. Use --json for the list.');
                }
            }

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        } catch (MediaException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(
                    ['error' => $exception->getMessage(), 'category' => $exception->category],
                    JSON_THROW_ON_ERROR,
                ));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        } catch (\InvalidArgumentException) {
            $this->error('Invalid capability requirement.');

            return self::FAILURE;
        }
    }
}
