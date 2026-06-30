<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckTenantActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        // Admin bypass
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Owner/Merchant logic
        if ($user->isOwner()) {
            // Reload tenant relationship to avoid stale cache
            $user->loadMissing('tenant');
            $tenant = $user->tenant ?: \App\Models\Tenant::where('user_id', $user->id)->where('is_deleted', 0)->first();
            
            if (!$tenant) {
                \Illuminate\Support\Facades\Log::error('SysErr01: Tenant not found for owner', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'user_role_id' => $user->role_id,
                ]);
                return response()->json(['status' => false, 'message' => 'SysErr01: Data Tenant Owner tidak ditemukan di database.'], 403);
            }
            // Gunakan loose comparison (==) karena cast boolean
            if ($tenant->status == 0 || $tenant->status == false) {
                return response()->json(['status' => false, 'message' => 'SysErr02: Status Tenant Anda dinonaktifkan.'], 403);
            }
        }

        // Staff/Kasir logic
        if ($user->isStaff()) {
            $tenant = $user->staffTenants()->first();
            if (!$tenant || $tenant->status === 0) {
                return response()->json(['status' => false, 'message' => 'Tenant tempat Anda bekerja tidak aktif.'], 403);
            }
        }

        return $next($request);
    }
}
