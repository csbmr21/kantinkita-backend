<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuFavorite;
use App\Models\OrderItem;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    use ApiResponse;

    /**
     * Get customer's favorite menus (liked + frequently ordered)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Menus explicitly liked by the customer
        $likedMenuIds = MenuFavorite::where('user_id', $user->id)
            ->pluck('menu_id')
            ->toArray();

        // 2. Menus most frequently ordered by the customer (top 10)
        $frequentMenuIds = OrderItem::whereHas('order', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->whereIn('status', ['completed', 'paid', 'processing']);
            })
            ->select('menu_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('menu_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->pluck('menu_id')
            ->toArray();

        // Merge both lists (unique)
        $allMenuIds = array_unique(array_merge($likedMenuIds, $frequentMenuIds));

        if (empty($allMenuIds)) {
            return $this->success([], 'Belum ada menu favorit');
        }

        // Fetch menus with tenant info
        $menus = Menu::with('tenant:id,tenant_name,photo,company_code')
            ->whereIn('id', $allMenuIds)
            ->where('is_deleted', false)
            ->where('status', true)
            ->get()
            ->map(function ($menu) use ($likedMenuIds, $frequentMenuIds) {
                $menu->is_liked = in_array($menu->id, $likedMenuIds);
                $menu->is_frequent = in_array($menu->id, $frequentMenuIds);
                return $menu;
            });

        return $this->success($menus, 'Menu favorit berhasil dimuat');
    }

    /**
     * Toggle favorite (like/unlike) a menu
     */
    public function toggle(Request $request, $menuId)
    {
        $user = $request->user();

        // Verify menu exists
        $menu = Menu::where('id', $menuId)
            ->where('is_deleted', false)
            ->first();

        if (!$menu) {
            return $this->error('Menu tidak ditemukan', 404);
        }

        $existing = MenuFavorite::where('user_id', $user->id)
            ->where('menu_id', $menuId)
            ->first();

        if ($existing) {
            $existing->delete();
            return $this->success(['is_liked' => false], 'Dihapus dari favorit');
        }

        MenuFavorite::create([
            'user_id'      => $user->id,
            'menu_id'      => $menuId,
            'company_code' => $user->company_code ?? 'UNIV',
        ]);

        return $this->success(['is_liked' => true], 'Ditambahkan ke favorit');
    }

    /**
     * Check which menus are liked by the customer (for batch checking)
     */
    public function check(Request $request)
    {
        $menuIds = $request->input('menu_ids', []);
        $user = $request->user();

        if (empty($menuIds)) {
            return $this->success([]);
        }

        $likedIds = MenuFavorite::where('user_id', $user->id)
            ->whereIn('menu_id', $menuIds)
            ->pluck('menu_id')
            ->toArray();

        return $this->success(['liked_ids' => $likedIds]);
    }
}
