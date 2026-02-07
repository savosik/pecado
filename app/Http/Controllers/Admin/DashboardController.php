<?php

namespace App\Http\Controllers\Admin;

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
        $totalOrders = Order::count();
        $totalProducts = Product::count();
        $totalUsers = User::count();
        $totalRevenue = Order::where('status', 'completed')->sum('total_amount');

        // Orders by status
        $pendingOrders = Order::where('status', 'pending')->count();
        $completedOrders = Order::where('status', 'completed')->count();
        $cancelledOrders = Order::where('status', 'cancelled')->count();

        // Average order value
        $avgOrderValue = Order::where('status', 'completed')->avg('total_amount') ?? 0;

        // Sales chart data (last 30 days)
        $salesChartData = Order::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as revenue')
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
        $recentOrders = Order::with(['user', 'company'])
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_name' => $order->user->name ?? 'N/A',
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'status_label' => $order->status_label,
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
