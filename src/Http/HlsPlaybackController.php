<?php

namespace ProtoneMedia\LaravelFFMpeg\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serve the playlists, keys and media files of a protected HLS presentation.
 */
class HlsPlaybackController
{
    /**
     * The number of bytes read from storage at a time.
     */
    protected const int CHUNK_SIZE = 1048576;

    /**
     * Handle the incoming playback request.
     */
    public function __invoke(Request $request, HlsPlayback $playback): Response
    {
        $presentation = $playback->resolve($request);
        $file = $presentation->file($request->route('hlsResource'));
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (in_array($file['role'], ['hls-master', 'hls-playlist'], true)) {
            return $this->playlistResponse($request, $presentation, $file, $headers);
        }

        [$stream, $size] = $this->openFile($file);

        if ($file['role'] === 'hls-key') {
            return $this->keyResponse($request, $stream, $headers);
        }

        return $this->fileResponse($request, $file, $stream, $size, $headers);
    }

    /**
     * Render the playlist with signed URLs for each of its resources.
     */
    protected function playlistResponse(
        Request $request,
        HlsPresentation $presentation,
        array $file,
        array $headers,
    ): Response {
        try {
            $content = $presentation->render($file, fn ($id): string => $this->resourceUrl($request, $id));

            return response($content, 200, [...$headers, 'Content-Type' => 'application/vnd.apple.mpegurl']);
        } catch (MediaException) {
            abort(404);
        }
    }

    /**
     * Sign a URL for the given resource that expires with the current request's URL.
     */
    protected function resourceUrl(Request $request, string $id): string
    {
        $parameters = Arr::except(
            array_replace($request->query(), $request->route()->parameters()),
            ['_hlsPlayback', 'signature', 'expires'],
        );
        $parameters['hlsResource'] = $id;

        return URL::temporarySignedRoute($request->route()->getName(),
            CarbonImmutable::createFromTimestamp((int) $request->query('expires')), $parameters);
    }

    /**
     * Open a read stream for the given file and determine its size.
     *
     * @return array{resource, int}
     */
    protected function openFile(array $file): array
    {
        try {
            $disk = Storage::disk($file['disk']);
            $size = $disk->size($file['path']);
            $stream = $disk->readStream($file['path']);
            if (! is_resource($stream)) {
                abort(404);
            }
        } catch (Throwable) {
            abort(404);
        }

        return [$stream, $size];
    }

    /**
     * Respond with the 16-byte encryption key from the given stream.
     *
     * @param  resource  $stream
     */
    protected function keyResponse(Request $request, $stream, array $headers): Response
    {
        try {
            $key = stream_get_contents($stream, 17);
            abort_unless(is_string($key) && strlen($key) === 16, 404);

            return response($request->isMethod('HEAD') ? '' : $key, 200, [...$headers,
                'Content-Type' => 'application/octet-stream', 'Content-Length' => '16']);
        } catch (Throwable) {
            abort(404);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Respond with the requested byte range of a media segment or other file.
     *
     * @param  resource  $stream
     */
    protected function fileResponse(Request $request, array $file, $stream, int $size, array $headers): Response
    {
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers['Accept-Ranges'] = 'bytes';
        $headers['Content-Type'] = match (strtolower(pathinfo($file['path'], PATHINFO_EXTENSION))) {
            'ts' => 'video/mp2t', 'mp4', 'm4s' => 'video/mp4', 'vtt' => 'text/vtt', 'aac' => 'audio/aac',
            default => 'application/octet-stream',
        };
        if (($range = $request->header('Range')) !== null) {
            $bounds = $this->byteRange($range, $size);
            if ($bounds === null) {
                fclose($stream);

                return response('', 416, [...$headers, 'Content-Range' => "bytes */{$size}"]);
            }

            [$start, $end] = $bounds;
            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $headers['Content-Length'] = (string) max(0, $end - $start + 1);
        if ($request->isMethod('HEAD')) {
            fclose($stream);

            return response('', $status, $headers);
        }

        return response()->stream(fn () => $this->sendRange($stream, $start, $end), $status, $headers);
    }

    /**
     * Parse a single byte range, or return null when it cannot be satisfied.
     *
     * @return array{int, int}|null
     */
    protected function byteRange(string $range, int $size): ?array
    {
        if (! preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $match) || ($match[1] === '' && $match[2] === '')) {
            return null;
        }

        $start = $match[1] === '' ? max(0, $size - (int) $match[2]) : (int) $match[1];
        $end = $match[1] === '' || $match[2] === '' ? $size - 1 : min($size - 1, (int) $match[2]);
        if ($start > $end || $start >= $size) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * Send the given byte range of the stream and close it.
     *
     * @param  resource  $stream
     */
    protected function sendRange($stream, int $start, int $end): void
    {
        try {
            // Some remote storage streams cannot seek. In that case we will read and discard
            // the prefix in bounded chunks instead of loading the prefix or the whole segment.
            if ($start > 0 && @fseek($stream, $start) !== 0) {
                $remaining = $start;
                while ($remaining > 0 && ! feof($stream)) {
                    $bytes = fread($stream, min(self::CHUNK_SIZE, $remaining));
                    if ($bytes === false || $bytes === '') {
                        break;
                    }

                    $remaining -= strlen($bytes);
                }
            }

            $remaining = $end - $start + 1;
            while ($remaining > 0 && ! feof($stream)) {
                $bytes = fread($stream, min(self::CHUNK_SIZE, $remaining));
                if ($bytes === false || $bytes === '') {
                    break;
                }

                echo $bytes;
                $remaining -= strlen($bytes);
            }
        } finally {
            fclose($stream);
        }
    }
}
