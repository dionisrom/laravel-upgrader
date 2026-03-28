<?php

namespace Modules\Reporting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ReportingAccessMiddleware
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
