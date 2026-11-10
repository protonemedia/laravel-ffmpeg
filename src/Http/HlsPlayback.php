<?php

namespace ProtoneMedia\LaravelFFMpeg\Http;

use Closure;
use DateTimeInterface;
use Illuminate\Auth\Access\Response as AuthorizationResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * The registry of protected HLS playback definitions.
 */
class HlsPlayback
{
    /**
     * The registered resolve and authorize callbacks, keyed by definition name.
     */
    protected array $presentations = [];

    /**
     * Register a playback definition.
     *
     * Register definitions in a service provider so they also exist when routes are cached.
     */
    public function define(string $name, callable $resolve, callable $authorize): static
    {
        $this->presentations[$name] = [Closure::fromCallable($resolve), Closure::fromCallable($authorize)];

        return $this;
    }

    /**
     * Register the route that serves the given playback definition.
     */
    public function routes(string $uri, string $name, string $playback, array $middleware = ['web', 'auth']): Route
    {
        return Router::get(rtrim($uri, '/').'/{hlsResource?}', HlsPlaybackController::class)
            ->where('hlsResource', '[a-f0-9]{64}')->defaults('_hlsPlayback', $playback)
            ->middleware($middleware)->name($name);
    }

    /**
     * Create a temporary signed URL for the given playback route.
     *
     * The URL expires after 30 minutes by default.
     */
    public function url(string $route, array $parameters = [], ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute($route, $expires ?? now()->addMinutes(30), $parameters);
    }

    /**
     * Resolve the authorized presentation for the given signed request.
     */
    public function resolve(Request $request): HlsPresentation
    {
        abort_unless(is_string($request->query('expires')) && ctype_digit($request->query('expires'))
            && $request->hasValidSignature(), 403);
        $definition = $this->presentations[$request->route()->defaults['_hlsPlayback'] ?? ''] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException('Register the HLS playback definition in a service provider.');
        }

        $parameters = $request->route()->parameters();
        unset($parameters['hlsResource'], $parameters['_hlsPlayback']);
        $parameters['request'] = $request;
        [$resolve, $authorize] = $definition;
        $decision = app()->call($authorize, $parameters);
        abort_unless($decision === true || ($decision instanceof AuthorizationResponse && $decision->allowed()), 403);

        return app()->call($resolve, $parameters);
    }
}
