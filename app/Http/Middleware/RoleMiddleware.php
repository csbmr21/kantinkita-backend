<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Get the canonical role slug from User model
        $userRole = $user->getRoleSlug();

        // 1. Administrator ALWAYS has access to everything
        if ($userRole === 'admin' || $userRole === 'administrator') {
            return $next($request);
        }

        // 2. Define Alias Groups for flexibility
        $aliases = [
            'staff'    => ['staff', 'kasir', 'employee', 'owner', 'merchant'], // Owners can access staff routes
            'owner'    => ['owner', 'merchant', 'tenant-owner'],
            'customer' => ['customer', 'user', 'client'],
        ];

        // 3. Check if any of the required roles match the user's role or its aliases
        foreach ($roles as $requiredRole) {
            $requiredRole = strtolower($requiredRole);
            
            // Direct match
            if ($userRole === $requiredRole) {
                return $next($request);
            }

            // Alias match: if the required role is 'staff', and user is 'kasir', it's a match
            if (isset($aliases[$requiredRole]) && in_array($userRole, $aliases[$requiredRole])) {
                return $next($request);
            }
        }

        return response()->json([
            'status' => false, 
            'message' => 'Akses ditolak. Role Anda (' . $userRole . ') tidak memiliki izin untuk resource ini.'
        ], 403);
    }
}
