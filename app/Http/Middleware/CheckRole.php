<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  mixed ...$roles  One or more roles, e.g. "Admin", "Data Entry"
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $level)
    {
        $user = Auth::user();
        
        // Check if the user has at least one of the required roles.
        if ($user && $level > 0 && $user->role_bitpack < $level) {
            abort(403, 'Unauthorized.');
        }
        
        return $next($request);
    }
}
