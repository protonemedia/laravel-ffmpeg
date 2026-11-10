<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

class PlaybackTest extends TestCase
{
    protected bool $allowed = true;

    protected array $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $disk = Storage::disk('memory');
        $disk->put('lesson/master.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=800000
            low/index.m3u8

            M3U8);
        $disk->put('lesson/low/index.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-KEY:METHOD=AES-128,URI="https://keys.example/private",IV=0x01
            #EXT-X-MAP:URI="init.mp4"
            #EXTINF:1,
            part.m4s
            #EXT-X-ENDLIST

            M3U8);
        $disk->put('lesson/low/part.m4s', '0123456789abcdef');
        $disk->put('lesson/low/init.mp4', 'initialization');
        $disk->put('private/key', str_repeat('k', 16));
        $disk->put('other/private.txt', 'DO NOT DISCLOSE');
        $files = [];
        foreach (['lesson/master.m3u8' => 'hls-master', 'lesson/low/index.m3u8' => 'hls-playlist',
            'lesson/low/part.m4s' => 'hls-segment', 'lesson/low/init.mp4' => 'hls-init', 'private/key' => 'hls-key'] as $path => $role) {
            $files[] = ['disk' => 'memory', 'path' => $path, 'role' => $role];
        }
        $this->inventory = (new HlsPresentation($files, HlsPresentation::id('memory', 'lesson/master.m3u8'), [
            'lesson/low/index.m3u8' => ['https://keys.example/private' => ['disk' => 'memory', 'path' => 'private/key']],
        ]))->toArray();
        Gate::define('watch', fn ($user, $lesson) => $this->allowed && (int) $user->id === (int) $lesson);
        Hls::define('lessons', resolve: fn () => HlsPresentation::fromArray($this->inventory),
            authorize: fn (Request $request, $lesson) => Gate::authorize('watch', $lesson));
        Hls::routes('/lessons/{lesson}/hls', name: 'lessons.hls', playback: 'lessons');
    }

    protected function login(): void
    {
        $this->actingAs(new GenericUser(['id' => 42]));
    }

    protected function url(?string $path = null, int $lesson = 42): string
    {
        return Hls::url('lessons.hls', array_filter([
            'lesson' => $lesson, 'hlsResource' => $path === null ? null : HlsPresentation::id('memory', $path),
        ]), expires: now()->addMinutes(5));
    }

    protected function links(string $playlist): array
    {
        preg_match_all('~https?://[^\s"]+~', $playlist, $matches);

        return $matches[0];
    }

    /**
     * Get the signed URL of the lesson's variant playlist and the signed URLs it links to.
     */
    protected function variantLinks(): array
    {
        $variant = $this->links($this->get($this->url())->assertOk()->getContent())[0];

        return [$variant, $this->links($this->get($variant)->assertOk()->getContent())];
    }

    public function test_every_resource_is_authorized_on_every_request(): void
    {
        $master = $this->url();
        $this->getJson($master)->assertUnauthorized();
        $this->login();
        $this->getJson('/lessons/42/hls')->assertForbidden();
        $this->getJson($master.'&extra=1')->assertForbidden();
        $this->getJson(str_replace('/lessons/42/', '/lessons/43/', $master))->assertForbidden();
        $this->getJson($this->url(lesson: 43))->assertForbidden();
        $variant = $this->links($this->get($master)->assertOk()->getContent())[0];
        $links = $this->links($this->get($variant)->assertOk()->getContent());
        $this->assertCount(3, $links);
        $this->allowed = false;
        foreach ([$master, $variant, ...$links] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_signed_children_keep_the_original_deadline(): void
    {
        $this->login();
        $master = $this->url();
        parse_str(parse_url($master, PHP_URL_QUERY), $original);
        $variant = $this->links($this->get($master)->assertOk()->getContent())[0];
        $this->travel(2)->minutes();
        $links = $this->links($this->get($variant)->assertOk()->getContent());
        foreach ([$variant, ...$links] as $url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($original['expires'], $query['expires']);
            $this->assertStringNotContainsString('private/key', $url);
        }
        $this->travel(4)->minutes();
        foreach ([$master, $variant, ...$links] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_variant_playlists_are_never_cached(): void
    {
        $this->login();
        [$variant] = $this->variantLinks();
        $response = $this->get($variant)->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_keys_are_returned_raw(): void
    {
        $this->login();
        [, $links] = $this->variantLinks();
        $this->get($links[0])->assertOk()->assertContent(str_repeat('k', 16))
            ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Length', '16');
    }

    public function test_initialization_segments_are_streamed(): void
    {
        $this->login();
        [, $links] = $this->variantLinks();
        $this->assertSame('initialization', $this->get($links[1])->assertOk()->streamedContent());
    }

    public function test_media_segments_support_byte_ranges(): void
    {
        $this->login();
        [, $links] = $this->variantLinks();
        $media = $this->get($links[2], ['Range' => 'bytes=3-7'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 3-7/16')->assertHeader('Content-Length', '5')
            ->assertHeader('Content-Type', 'video/mp4')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('34567', $media->streamedContent());
        $this->assertSame('cdef', $this->get($links[2], ['Range' => 'bytes=-4'])->assertStatus(206)->streamedContent());
        $this->assertSame('ef', $this->get($links[2], ['Range' => 'bytes=14-'])->assertStatus(206)->streamedContent());
        $this->head($links[2])->assertOk()->assertHeader('Content-Length', '16')->assertContent('');
    }

    #[DataProvider('unsatisfiableRangeProvider')]
    public function test_unsatisfiable_ranges_are_rejected(string $range): void
    {
        $this->login();
        [, $links] = $this->variantLinks();
        $this->get($links[2], ['Range' => $range])->assertStatus(416)->assertHeader('Content-Range', 'bytes */16');
    }

    public static function unsatisfiableRangeProvider(): array
    {
        return [
            'beyond the end' => ['bytes=99-'],
            'reversed' => ['bytes=8-2'],
            'empty suffix' => ['bytes=-0'],
            'multiple ranges' => ['bytes=1-2,4-5'],
            'not a range' => ['invalid'],
            'bare number' => ['0'],
        ];
    }

    public function test_signed_urls_cannot_escape_the_inventory_or_change_resource_roles(): void
    {
        $this->login();
        $this->get($this->url('other/private.txt'))->assertNotFound();
        $this->get($this->url('private/missing'))->assertNotFound();
        $master = <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            %s

            M3U8;
        Storage::disk('memory')->put('lesson/master.m3u8', sprintf($master, '../../other/private.txt'));
        $this->get($this->url())->assertNotFound();
        Storage::disk('memory')->put('lesson/master.m3u8', sprintf($master, 'low/part.m4s'));
        $this->get($this->url())->assertNotFound();
        Storage::disk('memory')->put('lesson/master.m3u8', sprintf($master, 'https://external.example/unowned'));
        $this->get($this->url())->assertNotFound();
        Storage::disk('memory')->put('private/key', 'invalid-key-length');
        $this->get($this->url('private/key'))->assertNotFound();
        Storage::disk('memory')->delete('lesson/low/part.m4s');
        $this->get($this->url('lesson/low/part.m4s'))->assertNotFound();
    }

    public function test_cached_routes_with_model_bindings_keep_policy_checks(): void
    {
        Route::bind('lesson', fn ($id) => new PlaybackLesson(['id' => $id]));
        Hls::define('lessons', resolve: fn (PlaybackLesson $lesson) => HlsPresentation::fromArray($this->inventory),
            authorize: fn (Request $request, PlaybackLesson $lesson) => $this->allowed && $request->user()->id === (int) $lesson->id);
        $compiled = Route::getRoutes()->compile();
        $this->app['router']->setCompiledRoutes($compiled);
        $this->login();
        $this->get($this->url())->assertOk();
        $this->allowed = false;
        $this->get($this->url())->assertForbidden();
    }

    public function test_bearer_playback_checks_authorization_on_every_request(): void
    {
        Hls::routes('/bearer/{lesson}', name: 'bearer.hls', playback: 'bearer', middleware: ['web']);
        Hls::define('bearer', resolve: fn () => HlsPresentation::fromArray($this->inventory), authorize: fn () => $this->allowed);
        $url = Hls::url('bearer.hls', ['lesson' => 42]);
        $this->get($url)->assertOk();
        $this->allowed = false;
        $this->get($url)->assertForbidden();
    }

    public function test_signed_query_context_is_preserved_without_overriding_route_identifiers(): void
    {
        $this->login();
        Hls::define('lessons', resolve: fn () => HlsPresentation::fromArray($this->inventory),
            authorize: fn (Request $request, $lesson) => (int) $lesson === 42 && $request->query('grant') === 'edition-a');
        $master = Hls::url('lessons.hls', ['lesson' => 42, 'grant' => 'edition-a']);
        $variant = $this->links($this->get($master)->assertOk()->getContent())[0];
        foreach ($this->links($this->get($variant)->assertOk()->getContent()) as $url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame('edition-a', $query['grant']);
            $this->get($url)->assertOk();
        }
    }

    public function test_token_guards_apply_to_child_requests(): void
    {
        Auth::viaRequest('playback-token', fn (Request $request) => $request->bearerToken() === 'test-token' ? new GenericUser(['id' => 42]) : null);
        config(['auth.guards.playback' => ['driver' => 'playback-token']]);
        Hls::routes('/token/{lesson}', name: 'token.hls', playback: 'lessons', middleware: ['api', 'auth:playback']);
        $url = Hls::url('token.hls', ['lesson' => 42]);
        $this->getJson($url)->assertUnauthorized();
        $variant = $this->links($this->get($url, ['Authorization' => 'Bearer test-token'])->assertOk()->getContent())[0];
        Auth::forgetGuards();
        $this->getJson($variant)->assertUnauthorized();
        Auth::forgetGuards();
        $this->get($variant, ['Authorization' => 'Bearer test-token'])->assertOk();
    }

    public function test_encrypted_export_segments_decrypt_with_the_served_keys(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        config(['filesystems.disks.playback-keys' => ['driver' => 'memory']]);
        $result = FFMpeg::open(new File(__DIR__.'/src/video.mp4'))
            ->hls(segmentDuration: 0.7)->video('libx264', bitrate: 80_000)->audio('aac', bitrate: 48_000)
            ->variant('small', fn ($variant) => $variant->resize(width: 64))
            ->encrypt('playback-keys', fn ($path) => 'https://keys.example/'.rawurlencode($path), rotateEvery: 2)
            ->toDisk('memory')->save('native/master.m3u8');
        $this->inventory = json_decode(json_encode($result->playback()->toArray()), true);
        $this->login();
        $variant = $this->links($this->get($this->url())->assertOk()->getContent())[0];
        $response = $this->get($variant)->assertOk()->getContent();
        $this->assertStringNotContainsString('keys.example', $response);
        $key = $iv = null;
        $decoded = '';
        foreach (explode("\n", $response) as $line) {
            if (preg_match('/URI="([^"]+)",IV=0x([a-f0-9]+)/', $line, $match)) {
                $key = $this->get($match[1])->assertOk()->getContent();
                $this->assertSame(16, strlen($key));
                $iv = hex2bin($match[2]);
            } elseif (str_starts_with($line, 'http')) {
                $segment = $this->get($line)->assertOk()->assertHeader('Content-Type', 'video/mp2t')->streamedContent();
                $plain = openssl_decrypt($segment, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
                $this->assertNotFalse($plain);
                $decoded .= $plain;
            }
        }
        $this->assertNotSame('', $decoded);
        $variantResult = $result->variant('small');
        $this->assertCount(0, $variantResult->files()->where('role', 'hls-key'));
        $this->inventory = $variantResult->playback()->toArray();
        $variantPlaylist = $this->get($this->url())->assertOk()->getContent();
        foreach ($this->links($variantPlaylist) as $url) {
            $this->get($url)->assertOk();
        }
        $path = tempnam(sys_get_temp_dir(), 'playback-decode-');
        try {
            file_put_contents($path, $decoded);
            $process = Process::run([$ffmpeg, '-v', 'error', '-i', $path, '-f', 'null', '-']);
            $this->assertProcessSucceeded($process);
        } finally {
            unlink($path);
        }
    }
}

class PlaybackLesson extends Model
{
    protected $guarded = [];
}
