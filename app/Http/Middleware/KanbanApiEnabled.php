<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KanbanApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.kanban_api_enabled')) {
            return response()->json([
                'error' => 'Kanban API is disabled. Set KANBAN_API_ENABLED=true to enable.',
            ], 503);
        }

        return $next($request);
    }
}
