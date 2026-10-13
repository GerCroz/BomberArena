<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! Auth::user()->is_admin) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'No autorizado. Se requieren permisos de administrador.'], 403);
            }

            return redirect()->route('admin.login')->with('error', 'Inicia sesión con credenciales de administrador.');
        }

        return $next($request);
    }
}
