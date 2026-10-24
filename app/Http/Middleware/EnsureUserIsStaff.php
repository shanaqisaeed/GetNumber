<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStaff
{
    /**
     * Handle an incoming request for staff (Admin or Agent).
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isStaff()) {
            abort(Response::HTTP_FORBIDDEN, 'دسترسی غیرمجاز. این بخش در اختیار کارشناسان پشتیبانی و راهبران سامانه می‌باشد.');
        }

        return $next($request);
    }
}
