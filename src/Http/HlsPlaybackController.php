<?php

namespace ProtoneMedia\LaravelFFMpeg\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HlsPlaybackController
{
    public function __invoke(Request $request, HlsPlayback $playback): Response
    {
        $presentation = $playback->resolve($request);
        $file = $presentation->file($request->route('hlsResource'));
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (in_array($file['role'], ['hls-master', 'hls-playlist'], true)) {
            $playlist = new HlsPlaylist($file['disk'], $file['path']);
            foreach (['playlist', 'media', 'key'] as $kind) {
                $playlist->{$kind.'UrlsUsing'}(function ($target, $source, $uri) use ($request, $presentation, $file, $kind) {
                    $parameters = array_replace($request->query(), $request->route()->parameters());
                    unset($parameters['_hlsPlayback'], $parameters['signature'], $parameters['expires']);
                    $parameters['hlsResource'] = $presentation->reference($file, $target, $uri, $kind);

                    return URL::temporarySignedRoute($request->route()->getName(),
                        CarbonImmutable::createFromTimestamp((int) $request->query('expires')), $parameters);
                });
            }

            try {
                return response($playlist->get(), 200, [...$headers, 'Content-Type' => 'application/vnd.apple.mpegurl']);
            } catch (MediaException) {
                abort(404);
            }
        }

        $stream = null;
        try {
            $disk = Storage::disk($file['disk']);
            $size = $disk->size($file['path']);
            $stream = $disk->readStream($file['path']);
            if (! is_resource($stream)) {
                abort(404);
            }
            if ($file['role'] === 'hls-key') {
                $key = stream_get_contents($stream, 17);
                abort_unless(is_string($key) && strlen($key) === 16, 404);

                return response($request->isMethod('HEAD') ? '' : $key, 200, [...$headers,
                    'Content-Type' => 'application/octet-stream', 'Content-Length' => '16']);
            }
        } catch (Throwable) {
            abort(404);
        } finally {
            if (is_resource($stream) && ($file['role'] === 'hls-key')) {
                fclose($stream);
            }
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers['Accept-Ranges'] = 'bytes';
        $headers['Content-Type'] = match (strtolower(pathinfo($file['path'], PATHINFO_EXTENSION))) {
            'ts' => 'video/mp2t', 'mp4', 'm4s' => 'video/mp4', 'vtt' => 'text/vtt', 'aac' => 'audio/aac',
            default => 'application/octet-stream',
        };
        if (($range = $request->header('Range')) !== null) {
            if (! preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $match) || ($match[1] === '' && $match[2] === '')) {
                fclose($stream);

                return response('', 416, [...$headers, 'Content-Range' => 'bytes */'.$size]);
            }
            $start = $match[1] === '' ? max(0, $size - (int) $match[2]) : (int) $match[1];
            $end = $match[1] === '' || $match[2] === '' ? $size - 1 : min($size - 1, (int) $match[2]);
            if ($start > $end || $start >= $size) {
                fclose($stream);

                return response('', 416, [...$headers, 'Content-Range' => 'bytes */'.$size]);
            }
            $status = 206;
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }
        $headers['Content-Length'] = (string) max(0, $end - $start + 1);
        if ($request->isMethod('HEAD')) {
            fclose($stream);

            return response('', $status, $headers);
        }

        return response()->stream(function () use ($stream, $start, $end) {
            try {
                // Some remote Storage streams are not seekable. Discard bounded
                // chunks rather than loading the prefix or whole segment.
                if ($start > 0 && @fseek($stream, $start) !== 0) {
                    $remaining = $start;
                    while ($remaining > 0 && ! feof($stream)) {
                        $bytes = fread($stream, min(1048576, $remaining));
                        if ($bytes === false || $bytes === '') {
                            break;
                        }
                        $remaining -= strlen($bytes);
                    }
                }
                $remaining = $end - $start + 1;
                while ($remaining > 0 && ! feof($stream)) {
                    $bytes = fread($stream, min(1048576, $remaining));
                    if ($bytes === false || $bytes === '') {
                        break;
                    }
                    echo $bytes;
                    $remaining -= strlen($bytes);
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }
}
