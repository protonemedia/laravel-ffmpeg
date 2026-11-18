# Testing

## Inspecting Commands

The `inspect` method returns the FFmpeg arguments without reading inputs or running a process, so a unit test may assert an encoding profile. Inputs and outputs appear as placeholders such as `[input:0]`:

```php
namespace Tests\Unit;

use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Tests\TestCase;

class WebVideoProfileTest extends TestCase
{
    public function test_web_videos_use_h264_at_1280_pixels_wide(): void
    {
        $arguments = FFMpeg::disk('uploads')
            ->open('videos/intro.mov')
            ->export()
            ->video('libx264', crf: 23)
            ->audio('aac', bitrate: '128k')
            ->resize(width: 1280)
            ->inspect();

        $this->assertContains('libx264', $arguments);
        $this->assertContains('scale=w=1280:h=-2:reset_sar=1', $arguments);
    }
}
```

## Faking FFmpeg

To test code that saves an export without FFmpeg installed, fake Laravel's processes. The fake must answer the `-version` check with a 9.0.x version, answer FFprobe with JSON, and create the output file, because the package publishes the file the encoder wrote:

```php
namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Tests\TestCase;

class EncodeVideoTest extends TestCase
{
    public function test_videos_are_published_to_the_videos_disk(): void
    {
        Storage::fake('uploads');
        Storage::fake('videos');

        Storage::disk('uploads')->put('videos/intro.mov', 'source');

        Process::preventStrayProcesses();

        Process::fake(function (PendingProcess $process) {
            $binary = basename($process->command[0]);

            if (in_array('-version', $process->command, true)) {
                return Process::result(output: "{$binary} version 9.0.2");
            }

            if ($binary === 'ffprobe') {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '10'],
                    'streams' => [
                        ['index' => 0, 'codec_type' => 'video'],
                        ['index' => 1, 'codec_type' => 'audio'],
                    ],
                    'chapters' => [],
                ]));
            }

            // The last argument of a single-output export is the staged output file.
            file_put_contents(end($process->command), 'encoded video');

            return Process::result(output: "progress=end\n");
        });

        FFMpeg::disk('uploads')
            ->open('videos/intro.mov')
            ->export()
            ->video('libx264')
            ->audio('aac')
            ->toDisk('videos')
            ->save('videos/intro.mp4');

        Storage::disk('videos')->assertExists('videos/intro.mp4');
    }
}
```

A fake proves the application's flow, not the media. Add a test against a real FFmpeg 9.0.x build for codec and filter behavior.

## Faking Queued Exports

The `Bus::fake()` method captures the export job, which exposes its `exportId` and destination `path`:

```php
namespace Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportJob;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Tests\TestCase;

class QueueVideoTest extends TestCase
{
    public function test_videos_are_encoded_on_the_media_queue(): void
    {
        Bus::fake();
        Storage::fake('uploads');

        FFMpeg::disk('uploads')
            ->open('videos/intro.mov')
            ->export()
            ->video('libx264')
            ->audio('aac')
            ->queued(queue: 'media')
            ->save('videos/intro.mp4');

        Bus::assertDispatched(ExportJob::class, fn (ExportJob $job) => $job->path === 'videos/intro.mp4'
            && $job->queue === 'media');
    }
}
```

## Checking the FFmpeg Build

The `ffmpeg:doctor` Artisan command checks that FFmpeg and FFprobe report the same 9.0.x version and that the temporary directory is writable. It exits with a nonzero code when a required encoder, muxer, filter, or protocol is missing, so you may run it in a deployment script:

```shell
php artisan ffmpeg:doctor --encoder=libx264 --encoder=aac --muxer=hls
```

Within the application, `FFMpeg::capabilities()->require(['encoders' => ['libx264', 'aac']])` performs the same check.
