<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends AdminController
{
    /**
     * Display the admin dashboard.
     */
    public function index(): Response
    {
        // Basic statistics
        // ofClients() везде, где считаются продажи: заказы сотрудников и служебных
        // учёток бывают тестовыми и в выручке со средним чеком им не место.
        // Партнёрские заказы из 1С (без user_id) скоуп сохраняет.
        $totalOrders = Order::query()->ofClients()->count();
        $totalProducts = Product::count();
        // Пользователей считаем всех: это счётчик учёток, а не продаж.
        $totalUsers = User::count();
        $totalRevenue = Order::query()->ofClients()->where('status', 'closed')->sum('total_amount');

        // Orders by status
        $pendingOrders = Order::query()->ofClients()->where('status', OrderStatus::PENDING_APPROVAL->value)->count();
        $completedOrders = Order::query()->ofClients()->where('status', OrderStatus::CLOSED->value)->count();
        $cancelledOrders = 0; // Статус cancelled удалён в v12.3

        // Average order value
        $avgOrderValue = Order::query()->ofClients()->where('status', OrderStatus::CLOSED->value)->avg('total_amount') ?? 0;

        // Sales chart data (last 30 days)
        $salesChartData = Order::query()
            ->ofClients()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as revenue')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('d.m'),
                    'orders' => $item->count,
                    'revenue' => round($item->revenue, 2),
                ];
            });

        // Recent orders
        $recentOrders = Order::query()
            ->ofClients()
            ->with(['user', 'company'])
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->erp_number ?? $order->number,
                    'user_name' => $order->user->name ?? 'N/A',
                    'total_amount' => $order->total_amount,
                    'status' => $order->status?->value,
                    'status_label' => $order->status?->label() ?? 'Неизвестно',
                    'created_at' => $order->created_at->format('d.m.Y H:i'),
                ];
            });

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalOrders' => $totalOrders,
                'totalProducts' => $totalProducts,
                'totalUsers' => $totalUsers,
                'totalRevenue' => $totalRevenue,
                'pendingOrders' => $pendingOrders,
                'completedOrders' => $completedOrders,
                'cancelledOrders' => $cancelledOrders,
                'avgOrderValue' => round($avgOrderValue, 2),
            ],
            'salesChartData' => $salesChartData,
            'recentOrders' => $recentOrders,
        ]);
    }
}
