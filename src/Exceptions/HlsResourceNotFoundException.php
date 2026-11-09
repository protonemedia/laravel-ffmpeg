<?php

namespace ProtoneMedia\LaravelFFMpeg\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use Illuminate\Support\ItemNotFoundException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A resource outside an HLS presentation's inventory. During a request it renders as the application's 404 response. */
class HlsResourceNotFoundException extends ItemNotFoundException implements ShouldntReport
{
    public function render(Request $request): Response
    {
        return app(ExceptionHandler::class)->render($request, new NotFoundHttpException(previous: $this));
    }
}
