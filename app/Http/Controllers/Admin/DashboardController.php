<?php

namespace App\Http\Controllers\Admin;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends AdminController
{
    /**
     * Display the admin dashboard.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                // Placeholder statistics - will be populated in Phase 8
                'totalOrders' => 0,
                'totalProducts' => 0,
                'totalUsers' => 0,
                'totalRevenue' => 0,
            ],
        ]);
    }
}
