<?php

namespace Otas\MicroService\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Otas\MicroService\Actions\DecodeRequestAction;

class MicroServiceMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (config('microservice.disable_package_middleware') && ! App::environment('production')) return $next($request);

        if (! DecodeRequestAction::handle()) abort(405, 'forbidden action');

        return $next($request);
    }
}
