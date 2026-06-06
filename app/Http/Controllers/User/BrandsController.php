<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class BrandsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('User/Brands/Index', [
            'seo' => [
                'title' => 'Все бренды',
                'description' => 'Полный список брендов магазина',
            ],
        ]);
    }
}
