<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect('/login');
        }

        if (! Auth::user()->isAdmin()) {
            return redirect()
                ->route('admin.index')
                ->with('error', 'Доступ к пользователям и ролям только у admin.');
        }

        return $next($request);
    }
}
