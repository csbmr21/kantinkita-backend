<?php
namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ActivityLog;
use App\Services\OrderService;
use App\Services\NotificationService;
use App\Services\MidtransService;
use App\Events\OrderStatusChanged;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderService        $orderService,
        private NotificationService $notificationService,
        private MidtransService     $midtrans,
    ) {}

    private function getTenant(Request $request)
    {
        $user = $request->user();
        $tenant = $user->isOwner() ? $user->tenant : $user->staffTenants()->first();
        
        if (!$tenant) {
            abort(403, 'Akses ditolak. Anda belum terhubung ke tenant mana pun.');
        }
        return $tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->getTenant($request);

        $orders = Order::with(['items.menu', 'user', 'payment'])
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['cart', 'expired', 'cancelled'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->latest()
            ->paginate(20);

        return $this->success($orders);
    }

    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:paid,processing,completed,cancelled',
            'estimated_ready_time' => 'nullable|integer|min:0',
        ]);

        $user   = $request->user();
        $tenant = $this->getTenant($request);

        $order = Order::where('id', $id)->where('tenant_id', $tenant->id)->firstOrFail();

        if (!$this->orderService->isValidTransition($order->status, $request->status)) {
            return $this->error("Tidak bisa mengubah status dari '{$order->status}' ke '{$request->status}'.", 422);
        }

        $oldStatus = $order->status;
        $updateData = [
            'status' => $request->status, 
            'updated_by' => $user->username
        ];

        if ($request->status === 'paid') {
            $updateData['paid_at'] = now();
            if ($order->payment) {
                $order->payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'updated_by' => $user->username
                ]);
            }
        } elseif ($request->status === 'processing') {
            $updateData['processing_at'] = now();
        } elseif ($request->status === 'completed') {
            $updateData['completed_at'] = now();
        }

        if ($request->has('estimated_ready_time')) {
            $updateData['estimated_ready_time'] = $request->estimated_ready_time;
        }

        $order->update($updateData);

        event(new OrderStatusChanged($order->fresh(['items', 'user']), $request->status));

        if ($request->status === 'paid') {
            event(new \App\Events\NewOrderReceived($order->fresh(['items', 'user'])));
            $this->notificationService->notifyOrderPaid($order);
        } else {
            match ($request->status) {
                'processing' => $this->notificationService->notifyOrderProcessing($order),
                'completed'  => $this->notificationService->notifyOrderCompleted($order),
                default      => null,
            };
        }

        ActivityLog::record('status_change', "Order {$order->order_number}: {$oldStatus} → {$request->status}");

        return $this->success($order->fresh(['items', 'user', 'payment']), 'Status order berhasil diperbarui');
    }

    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'ids'    => 'required|array',
            'ids.*'  => 'integer|exists:orders,id',
            'status' => 'required|in:paid,processing,completed,cancelled',
        ]);

        $user   = $request->user();
        $tenant = $this->getTenant($request);
        $count  = 0;

        $orders = Order::whereIn('id', $request->ids)
            ->where('tenant_id', $tenant->id)
            ->get();

        /** @var Order $order */
        foreach ($orders as $order) {
            if ($this->orderService->isValidTransition($order->status, $request->status)) {
                $updateData = [
                    'status'     => $request->status,
                    'updated_by' => $user->username
                ];

                if ($request->status === 'paid') {
                    $updateData['paid_at'] = now();
                    if ($order->payment) {
                        $order->payment->update([
                            'status' => 'paid',
                            'paid_at' => now(),
                            'updated_by' => $user->username
                        ]);
                    }
                } elseif ($request->status === 'processing') {
                    $updateData['processing_at'] = now();
                } elseif ($request->status === 'completed') {
                    $updateData['completed_at'] = now();
                }

                $order->update($updateData);
                
                event(new OrderStatusChanged($order->fresh(['items', 'user']), $request->status));
                
                if ($request->status === 'paid') {
                    event(new \App\Events\NewOrderReceived($order->fresh(['items', 'user'])));
                    $this->notificationService->notifyOrderPaid($order);
                } else {
                    match ($request->status) {
                        'processing' => $this->notificationService->notifyOrderProcessing($order),
                        'completed'  => $this->notificationService->notifyOrderCompleted($order),
                        default      => null,
                    };
                }
                
                $count++;
            }
        }

        ActivityLog::record('bulk_status_change', "Bulk update {$count} orders to {$request->status}");

        return $this->success(null, "{$count} pesanan berhasil diperbarui ke status {$request->status}");
    }

    public function store(Request $request)
    {
        $request->validate([
            'items'          => 'required|array|min:1',
            'items.*.menu_id'=> 'required|exists:menus,id',
            'items.*.qty'    => 'required|integer|min:1',
            'notes'          => 'nullable|string|max:500',
            'payment_method' => 'required|in:cash,qris_manual,midtrans',
        ]);

        $user   = $request->user();
        $tenant = $this->getTenant($request);

        DB::beginTransaction();
        try {
            $totalAmount = 0;
            $orderItems  = [];

            foreach ($request->items as $itemData) {
                $menu = \App\Models\Menu::where('id', $itemData['menu_id'])
                    ->where('tenant_id', $tenant->id)
                    ->firstOrFail();

                if (!$menu->is_available) {
                    throw new \Exception("Menu '{$menu->name}' tidak tersedia.");
                }

                $subtotal = $menu->price * $itemData['qty'];
                $totalAmount += $subtotal;

                $orderItems[] = [
                    'menu_id'   => $menu->id,
                    'menu_name' => $menu->name,
                    'quantity'  => $itemData['qty'],
                    'price'     => $menu->price,
                    'subtotal'  => $subtotal,
                ];
            }

            $serviceFee  = $this->orderService->calculateFee($totalAmount);
            $grandTotal  = $totalAmount + $serviceFee;
            $orderNumber = $this->orderService->generateOrderNumber();
            $payMethod   = $request->payment_method;
            $isMidtrans  = $payMethod === 'midtrans';

            $order = Order::create([
                'tenant_id'    => $tenant->id,
                'user_id'      => $user->id,
                'order_number' => $orderNumber,
                'status'       => $isMidtrans ? Order::STATUS_PENDING : Order::STATUS_PAID,
                'paid_at'      => $isMidtrans ? null : now(),
                'total_amount' => $totalAmount,
                'service_fee'  => $serviceFee,
                'grand_total'  => $grandTotal,
                'notes'        => $request->notes,
                'payment_method' => $payMethod,
            ]);

            foreach ($orderItems as $item) {
                $order->items()->create($item);
            }

            $snapToken = null;
            $paymentUrl = null;

            if ($isMidtrans) {
                $snapData = $this->midtrans->createSnapToken($order->fresh(['items', 'user']));
                $snapToken = $snapData['snap_token'];
                $paymentUrl = $snapData['payment_url'];
            } else {
                $order->payment()->create([
                    'order_id'       => $order->id,
                    'transaction_id' => 'WALK-' . $orderNumber,
                    'payment_type'   => $payMethod,
                    'gross_amount'   => $grandTotal,
                    'status'         => 'paid',
                    'paid_at'        => now(),
                ]);
            }

            DB::commit();

            ActivityLog::record('walkin_order', "Created walk-in order: {$orderNumber}");

            return $this->success([
                'order'       => $order->load(['items', 'payment']),
                'snap_token'  => $snapToken,
                'payment_url' => $paymentUrl,
            ], 'Pesanan walk-in berhasil dibuat');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 422);
        }
    }

    public function summary(Request $request)
    {
        $tenant = $this->getTenant($request);

        $today = today();

        $dailyStats = Order::where('tenant_id', $tenant->id)
            ->whereDate('created_at', $today)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED])
            ->selectRaw('COUNT(*) as total_orders, SUM(grand_total) as total_revenue')
            ->first();

        $pendingOrders = Order::where('tenant_id', $tenant->id)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING])
            ->count();

        return $this->success([
            'total_orders'  => (int) ($dailyStats->total_orders ?? 0),
            'total_revenue' => (float) ($dailyStats->total_revenue ?? 0),
            'pending_count' => $pendingOrders,
        ]);
    }
}
