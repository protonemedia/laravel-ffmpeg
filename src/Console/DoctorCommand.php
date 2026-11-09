<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Console;

use Illuminate\Console\Command;
use ProtoneMedia\LaravelFFMpeg\Capabilities;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

class DoctorCommand extends Command
{
    protected $signature = 'ffmpeg:doctor {--json : Print the complete structured report}
        {--encoder=* : Required compiled-in encoder} {--muxer=* : Required muxer}
        {--filter=* : Required filter} {--protocol=* : Required input protocol in both binaries}
        {--bsf=* : Required bitstream filter} {--hwaccel=* : Required compiled-in hardware accelerator}';

    protected $description = 'Check matching FFmpeg/FFprobe 9.0.x, compiled-in features, and temporary storage';

    public function handle(): int
    {
        try {
            $capabilities = new Capabilities;
            $requirements = [];
            foreach (['encoder' => 'encoders', 'muxer' => 'muxers', 'filter' => 'filters',
                'protocol' => 'input_protocols', 'bsf' => 'bitstream_filters', 'hwaccel' => 'hardware_accelerators'] as $option => $category) {
                $requirements[$category] = $this->option($option);
            }

            $requirements['ffprobe_input_protocols'] = $this->option('protocol');
            $missing = $capabilities->missing($requirements);
            if ($this->option('json')) {
                $this->line(json_encode($capabilities->toArray() + ['missing' => $missing], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                foreach ($capabilities->versions() as $tool => $version) {
                    $this->line($tool.': '.$version);
                }

                foreach (['encoders', 'muxers', 'filters', 'input_protocols', 'output_protocols', 'bitstream_filters', 'hardware_accelerators'] as $category) {
                    $this->line($category.': '.count($capabilities->available($category)));
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
                $this->line(json_encode(['error' => $exception->getMessage(), 'category' => $exception->category], JSON_THROW_ON_ERROR));
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
