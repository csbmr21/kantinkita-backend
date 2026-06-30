<?php
namespace App\Services;

use App\Models\Order;
use App\Models\SystemSetting;
use App\Jobs\SendEmailNotification;
use App\Jobs\SendWhatsAppNotification;

class NotificationService
{
    public function notifyOrderCreated(Order $order): void
    {
        if (!SystemSetting::get('notif_order_created', true)) return;
        $this->sendToCustomer($order, 'order_created');
    }

    public function notifyOrderPaid(Order $order): void
    {
        if (!SystemSetting::get('notif_order_paid', true)) return;
        $this->sendToCustomer($order, 'order_paid');
        $this->sendToStaff($order, 'new_order_staff');
    }

    public function notifyOrderProcessing(Order $order): void
    {
        if (!SystemSetting::get('notif_order_processing', true)) return;
        $this->sendToCustomer($order, 'order_processing');
    }

    public function notifyOrderCompleted(Order $order): void
    {
        if (!SystemSetting::get('notif_order_completed', true)) return;
        $this->sendToCustomer($order, 'order_completed');
    }

    private function sendToCustomer(Order $order, string $template): void
    {
        $user = $order->user;
        try {
            if ($user->email_notif) {
                SendEmailNotification::dispatchSync($user, $order, $template);
            }
            if ($user->wa_notif && $user->phone) {
                SendWhatsAppNotification::dispatchSync($user, $order, $template);
            }
        } catch (\Exception $e) {
            \Log::warning('Customer notification failed: ' . $e->getMessage());
        }
    }

    private function sendToStaff(Order $order, string $template): void
    {
        foreach ($order->tenant->staff as $staff) {
            try {
                if ($staff->email_notif) {
                    SendEmailNotification::dispatchSync($staff, $order, $template);
                }
                if ($staff->wa_notif && $staff->phone) {
                    SendWhatsAppNotification::dispatchSync($staff, $order, $template);
                }
            } catch (\Exception $e) {
                \Log::warning('Staff notification failed: ' . $e->getMessage());
            }
        }
    }

    public function sendWhatsApp(string $phone, string $message): void
    {
        $token = config('services.fonnte.token');
        if (!$token) return;

        $phone = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $phone));

        try {
            \Illuminate\Support\Facades\Http::withHeaders(['Authorization' => $token])
                ->post(config('services.fonnte.url', 'https://api.fonnte.com/send'), [
                    'target'  => $phone,
                    'message' => $message,
                ]);
        } catch (\Exception $e) {
            // Silent fail
        }
    }
}
