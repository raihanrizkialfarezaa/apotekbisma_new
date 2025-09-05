<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HandleServerErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
            
            if ($response->getStatusCode() >= 500) {
                Log::error('Server error detected', [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'status_code' => $response->getStatusCode(),
                    'user_agent' => $request->userAgent(),
                    'ip' => $request->ip()
                ]);
            }
            
            return $response;
            
        } catch (\Throwable $e) {
            Log::error('Uncaught exception in request', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.',
                    'error_code' => 'SERVER_ERROR_' . time()
                ], 500);
            }
            
            return redirect()->back()->with('error', 'Terjadi kesalahan pada server. Silakan coba lagi.');
        }
    }
}
