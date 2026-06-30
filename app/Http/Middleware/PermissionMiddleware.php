<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Get canonical role slug
        $userRole = $user->getRoleSlug();

        // 1. Administrator ALWAYS has all permissions
        if ($userRole === 'admin' || $userRole === 'administrator') {
            return $next($request);
        }

        // 2. Owners/Merchants ALWAYS have full access to their own tenant resources
        // (Menus, Categories, Orders, etc.)
        if ($userRole === 'owner' || $userRole === 'merchant') {
            return $next($request);
        }

        // 3. Staff & Customers check explicit permissions
        if ($user->hasPermission($permission)) {
            return $next($request);
        }

        return response()->json([
            'status' => false, 
            'message' => "Akses ditolak. Anda memerlukan izin '{$permission}' untuk melakukan aksi ini."
        ], 403);
    }
}
