<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OptimizeForBulkOperations
{
    public function handle(Request $request, Closure $next)
    {
        if ($this->isBulkOperation($request)) {
            set_time_limit(120);
            ini_set('memory_limit', '512M');
            
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
        }
        
        return $next($request);
    }
    
    private function isBulkOperation(Request $request): bool
    {
        if (strpos($request->path(), 'pembelian_detail') !== false) {
            $jumlah = $request->input('jumlah', 1);
            if (is_numeric($jumlah) && (int)$jumlah > 50) {
                return true;
            }
        }
        
        return false;
    }
}
