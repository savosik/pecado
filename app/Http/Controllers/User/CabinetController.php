<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class CabinetController extends Controller
{
    public function dashboard()
    {
        return Inertia::render('User/Cabinet/Dashboard');
    }
}
