<?php

namespace Otas\MicroService\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Otas\MicroService\Actions\DecodeRequestAction;

class MicroServiceEstablishConnectionMiddleware
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
        if (! DecodeRequestAction::handle(establish: true)) abort(405, 'forbidden action');

        return $next($request);
    }
}
