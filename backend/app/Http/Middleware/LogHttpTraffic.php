<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogHttpTraffic
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        // Log request basics
        try {
            $payload = $request->all();
        } catch (\Throwable $e) {
            $payload = '[unavailable]';
        }

        Log::info('HTTP Request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'body' => $payload,
            'ip' => $request->ip(),
        ]);

        $response = $next($request);

        $duration = microtime(true) - $start;

        // Capture response content-type and snippet
        $contentType = $response->headers->get('Content-Type');
        $content = '';
        try {
            $content = (string) $response->getContent();
        } catch (\Throwable $e) {
            $content = '[unavailable]';
        }

        $snippet = mb_substr(trim(strip_tags($content)), 0, 1500);

        Log::info('HTTP Response', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'content_type' => $contentType,
            'duration_ms' => intval($duration * 1000),
            'body_snippet' => $snippet,
        ]);

        return $response;
    }
}
