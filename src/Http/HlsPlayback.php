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

class HlsPlayback
{
    protected array $presentations = [];

    /** Register in a service provider so definitions also exist with cached routes. */
    public function define(string $name, callable $resolve, callable $authorize): self
    {
        $this->presentations[$name] = [Closure::fromCallable($resolve), Closure::fromCallable($authorize)];

        return $this;
    }

    public function routes(string $uri, string $name, string $playback, array $middleware = ['web', 'auth']): Route
    {
        return Router::get(rtrim($uri, '/').'/{hlsResource?}', HlsPlaybackController::class)
            ->where('hlsResource', '[a-f0-9]{64}')->defaults('_hlsPlayback', $playback)
            ->middleware($middleware)->name($name);
    }

    public function url(string $route, array $parameters = [], ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute($route, $expires ?? now()->addMinutes(30), $parameters);
    }

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
