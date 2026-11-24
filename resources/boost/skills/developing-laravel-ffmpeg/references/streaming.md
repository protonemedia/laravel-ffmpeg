# Streaming

## Adaptive HLS

The `hls` method starts an HLS export with `libx264` and `aac` defaults. Each `variant` closure configures one rendition a player may choose:

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->hls(segmentDuration: 6)
    ->variant('360p', fn (HlsVariant $variant) => $variant
        ->resize(height: 360)
        ->video('libx264', bitrate: '800k'))
    ->variant('720p', fn (HlsVariant $variant) => $variant
        ->resize(height: 720)
        ->video('libx264', bitrate: '2500k'))
    ->toDisk('streams')
    ->save('lessons/42/master.m3u8');

$masterPath = $result->playlist()->path;

$files = $result->variant('720p')
    ->files();
```

Settings on the HLS builder, such as `video`, `audio`, and `watermark`, apply to every variant. The `fmp4` method packages fragmented MP4 segments instead of MPEG-TS. Keep the segment duration above half a second, or the master playlist loses its `RESOLUTION` and `CODECS` attributes.

## Encryption

The `encrypt` method writes AES-128 keys to a private disk and takes a closure that returns each key's URL. Encryption requires MPEG-TS segments, so it cannot be combined with `fmp4`:

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->hls()
    ->variant('720p', fn (HlsVariant $variant) => $variant
        ->resize(height: 720)
        ->video('libx264', bitrate: '2500k'))
    ->encrypt('secrets', fn (string $keyPath) => route('lessons.key', ['key' => basename($keyPath)]),
        rotateEvery: 3)
    ->toDisk('streams')
    ->save('lessons/42/master.m3u8');
```

AES-128 is not DRM, so the application must authorize every key request. The protected playback routes below do that for you.

## Protected Playback

The `Hls` facade registers signed routes that serve only the files in a stored inventory, after your authorization callback approves the viewer. First, store the root result's inventory in a JSON column cast to an array:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected function casts(): array
    {
        return ['hls_playback' => 'array'];
    }
}
```

```php
use App\Models\Lesson;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$lesson = Lesson::findOrFail(42);

$result = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->hls()
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720))
    ->toDisk('streams')
    ->save("lessons/{$lesson->id}/master.m3u8");

$lesson->hls_playback = $result->playback()
    ->toArray();

$lesson->save();
```

Next, define the playback in the `AppServiceProvider`. The `authorize` callback is required, and the `Route::model` call is too, because the package cannot infer the binding from the callbacks:

```php
namespace App\Providers;

use App\Models\Lesson;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::model('lesson', Lesson::class);

        Hls::define('lesson',
            resolve: fn (Lesson $lesson) => HlsPresentation::fromArray($lesson->hls_playback),
            authorize: fn (Lesson $lesson) => Gate::authorize('view', $lesson),
        );
    }
}
```

Then register the route in `routes/web.php`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

Hls::routes('/lessons/{lesson}/hls', name: 'lessons.hls', playback: 'lesson');
```

Finally, issue the entry URL from an authorized controller action and give it to the player:

```php
use App\Models\Lesson;
use Illuminate\Support\Facades\Gate;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

$lesson = Lesson::findOrFail(42);

Gate::authorize('view', $lesson);

$url = Hls::url('lessons.hls', ['lesson' => $lesson], expires: now()->addMinutes(10));
```

Media and keys must live on named private disks. The routes use the `web` and `auth` middleware by default, and pass `middleware: ['api', 'auth:sanctum']` to `routes` for Sanctum. To serve only some variants, store `$result->playbackForVariants(['720p'])->toArray()` instead. The entry URL ends in `index.m3u8` and every other playback URL ends in its file's extension, so players and FFmpeg detect HLS from the URL.

## DASH

The `dash` method builds adaptation sets and their representations, and `sharedHls` adds HLS playlists over the same segments:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->dash(segmentDuration: 2)
    ->adaptationSet('picture', 'video')
    ->adaptationSet('sound', 'audio')
    ->representation('low', 'picture', fn ($video) => $video
        ->map('0:v:0')
        ->resize(width: 640)
        ->video(bitrate: '800k'))
    ->representation('high', 'picture', fn ($video) => $video
        ->map('0:v:0')
        ->video(bitrate: '2500k'))
    ->representation('audio', 'sound', fn ($audio) => $audio
        ->map('0:a:0')
        ->audio(bitrate: '128k'))
    ->sharedHls()
    ->toDisk('streams')
    ->save('courses/intro/manifest.mpd');

$manifestPath = $result->manifest()->path;
```

DASH supports finite, unencrypted H.264 and AAC. With `sharedHls`, the `playback` method returns an inventory for the protected playback routes.

## Continuous HLS

The `liveHls` method publishes a sliding playlist while FFmpeg runs, and its `run` method blocks until the source ends, the timeout passes, or `cancelWhen` returns `true`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::liveHls(config('services.camera.url'), segmentDuration: 2, window: 6)
    ->toDisk('streams')
    ->retry(maxRetries: 3, delay: 1, reconnectOnEof: true)
    ->timeout(3600)
    ->run('live/front-door-'.now()->timestamp);
```

Run it in a supervised worker, and give every session a fresh, empty directory. Continuous HLS publishes one H.264 and AAC rendition, without adaptive variants, fMP4, or encryption. Source URLs belong in trusted configuration, never in user input.

## Other Streaming Methods

- `FFMpeg::mergeHls(['main' => $first, 'alternate' => $second])` combines completed HLS results of the same timeline into one master playlist without transcoding.
- `FFMpeg::disk('streams')->playlist($path)` returns a `Responsable` playlist whose `mediaUrlsUsing`, `playlistUrlsUsing`, and `keyUrlsUsing` closures rewrite its URLs for your own routes.
- The `audioRendition` and `subtitleRendition` methods add alternate audio and subtitle groups to an HLS export.

The README's "Streaming" sections show each of these in full.
