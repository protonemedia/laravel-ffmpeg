# Audio

## Stereo for the Web

The `aac` encoder keeps the source's channel layout, so a 5.1 source stays 5.1. To encode stereo for web playback, pass `-ac` to the `audioOptions` method, or call `channels` on an individual audio track:

```php
use ProtoneMedia\LaravelFFMpeg\AudioStreamSettings;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/surround.mkv');

$media->export()
    ->video('libx264')
    ->audio('aac', bitrate: '128k')
    ->audioOptions(['-ac', '2'])
    ->save('videos/stereo.mp4');

$media->export()
    ->video('libx264')
    ->audio('aac', bitrate: '128k')
    ->audioStream(0, fn (AudioStreamSettings $audio) => $audio->channels(2))
    ->save('videos/stereo-track.mp4');
```

## Background Music

The `backgroundMusic` method mixes a quieter track under the video's audio, looping it until the video ends. The `at` method delays the music, and `loop: false` plays it once:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$video = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4');

$music = FFMpeg::disk('music')
    ->open('ambient.mp3');

$video->backgroundMusic($music, volume: 0.2)
    ->at(2.5)
    ->video('copy')
    ->audio('aac', bitrate: '192k')
    ->toDisk('videos')
    ->save('videos/with-music.mp4');
```

## Mixing Audio

The `mixAudio` method sets both gains and plays the added track once unless you call `loop`. The `normalize` argument halves both signals to prevent clipping; it is not loudness normalization:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$video = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4');

$voiceover = FFMpeg::disk('music')
    ->open('voiceover.m4a');

$video->mixAudio($voiceover, volume: 0.9, originalVolume: 0.4, normalize: true)
    ->fadeOut(2, start: 18, video: false)
    ->video('copy')
    ->audio('aac')
    ->save('videos/mixed.mp4');
```

The first input sets the length: a shorter added track ends in silence, and a longer one is trimmed. A mix must encode audio, while video may be copied. Custom maps, clips, watermarks, and two-pass encoding are not supported.

## Loudness Normalization

The `normalizeLoudness` method measures one audio track, then normalizes it to an EBU R128 target in a single audio-only output:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::disk('uploads')
    ->open('podcasts/episode.wav')
    ->normalizeLoudness(integrated: -16, truePeak: -1.5)
    ->audio('aac', bitrate: '192k')
    ->toDisk('audio')
    ->save('podcasts/episode.m4a');

$measurement = $result->measurement;

$mode = $result->actualMode(); // FFmpeg may fall back from linear to dynamic.
```

## Waveform Peaks

The `waveformPeaks` method computes minimum and maximum sample values for a player that draws its own waveform. The `get` method returns them, and `save` also publishes them as JSON:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('podcasts/episode.mp3');

$peaks = $media->waveformPeaks()
    ->mono()
    ->buckets(1000)
    ->get();

$data = $peaks->toArray();

$result = $media->waveformPeaks()
    ->buckets(2000)
    ->toDisk('waveforms')
    ->save('podcasts/episode.json');
```

## Analysis

The `analyze` method runs an FFmpeg audio filter, such as `volumedetect`, and returns its output without throwing on failure unless you call `throw`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$analysis = FFMpeg::disk('uploads')
    ->open('podcasts/episode.mp3')
    ->analyze('volumedetect');

$report = $analysis->throw()
    ->errorOutput(); // FFmpeg prints filter statistics to standard error.
```
