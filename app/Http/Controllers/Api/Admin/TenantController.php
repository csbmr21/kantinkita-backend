<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Order;
use App\Models\ActivityLog;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $tenants = Tenant::with(['owner', 'subscription'])
                ->when($request->search, fn($q) =>
                    $q->where(fn($sub) => 
                        $sub->where('tenant_name', 'like', "%{$request->search}%")
                            ->orWhereHas('owner', fn($q2) =>
                                $q2->where('full_name', 'like', "%{$request->search}%")
                            )
                    )
                )
                ->when($request->status !== null, fn($q) => $q->where('status', $request->status))
                ->where('company_code', '!=', 'SYSAD')
                ->where('is_deleted', 0)
                ->orderByDesc('created_at')
                ->paginate(20);

            return $this->success($tenants);
        } catch (\Exception $e) {
            return $this->error('Gagal mengambil data tenant: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id'      => 'required|exists:users,id',
            'tenant_name'  => 'required|string|max:200|unique:tenants,tenant_name',
            'slug'         => 'nullable|string|max:200|unique:tenants,slug',
            'description'  => 'nullable|string',
            'address'      => 'nullable|string',
            'phone'        => 'nullable|string|max:20',
            'min_order'    => 'nullable|numeric|min:0',
            'company_code' => 'nullable|string|max:20',
            'status'       => 'nullable|boolean',
            'is_open'      => 'nullable|boolean',
        ]);

        $tenant = Tenant::create([
            'user_id'       => $request->user_id,
            'tenant_name'   => $request->tenant_name,
            'slug'          => $request->slug ?: \Illuminate\Support\Str::slug($request->tenant_name),
            'description'   => $request->description,
            'address'       => $request->address,
            'phone'         => $request->phone,
            'min_order'     => $request->min_order ?? 0,
            'company_code'  => $request->company_code ?? 'UNIV',
            'status'        => $request->status ?? 1,
            'is_open'       => $request->is_open ?? 1,
            'trial_ends_at' => now()->addDays(2), // 2-day free trial for new tenants
            'created_by'    => $request->user()->username,
            'updated_by'    => $request->user()->username,
        ]);

        ActivityLog::record('create', "Admin buat tenant: {$tenant->tenant_name}");
        return $this->success($tenant, 'Tenant berhasil dibuat', 201);
    }

    public function update(Request $request, int $id)
    {
        $tenant = Tenant::where('is_deleted', 0)->findOrFail($id);

        $request->validate([
            'tenant_name'  => "sometimes|string|max:200|unique:tenants,tenant_name,{$id}",
            'slug'         => "sometimes|string|max:200|unique:tenants,slug,{$id}",
            'description'  => 'nullable|string',
            'address'      => 'nullable|string',
            'phone'        => 'nullable|string|max:20',
            'min_order'    => 'nullable|numeric|min:0',
            'is_open'      => 'nullable|boolean',
            'status'       => 'nullable|boolean',
            'company_code' => 'nullable|string|max:20',
        ]);

        $data = $request->only(['tenant_name', 'description', 'address', 'phone', 'min_order', 'is_open', 'status', 'company_code', 'slug']);
        if (isset($data['tenant_name']) && !isset($data['slug'])) {
            $data['slug'] = \Illuminate\Support\Str::slug($data['tenant_name']);
        }
        $data['updated_by'] = $request->user()->username;

        $tenant->update($data);

        ActivityLog::record('update', "Admin update tenant: {$tenant->tenant_name}");
        return $this->success($tenant->fresh(), 'Tenant berhasil diperbarui');
    }

    public function destroy(Request $request, int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['is_deleted' => 1, 'updated_by' => $request->user()->username]);

        ActivityLog::record('delete', "Admin hapus tenant: {$tenant->tenant_name}");
        return $this->success(null, 'Tenant berhasil dihapus');
    }

    public function toggle(Request $request, int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => !$tenant->status, 'updated_by' => $request->user()->username]);

        $action = $tenant->status ? 'diaktifkan' : 'dinonaktifkan';
        ActivityLog::record('update', "Admin {$action} tenant: {$tenant->tenant_name}");
        return $this->success($tenant->fresh(), "Tenant berhasil {$action}");
    }

    public function stats()
    {
        return $this->success([
            'total_tenants'    => Tenant::where('is_deleted', 0)->count(),
            'active_tenants'   => Tenant::where('status', 1)->where('is_deleted', 0)->count(),
            'inactive_tenants' => Tenant::where('status', 0)->where('is_deleted', 0)->count(),
            'total_users'      => User::where('is_deleted', 0)->count(),
            'users_by_role'    => User::where('is_deleted', 0)->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
            'total_orders'     => Order::whereNotIn('status', ['cart'])->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
        ]);
    }
}
