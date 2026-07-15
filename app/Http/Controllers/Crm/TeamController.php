<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends CrmController
{
    public function index(Request $request): Response
    {
        $managers = PersonalManager::query()
            ->with(['user:id,name,email', 'media'])
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (PersonalManager $manager) => [
                'id' => $manager->id,
                'name' => $manager->name,
                'phone' => $manager->phone,
                'email' => $manager->email,
                'photo_url' => $manager->getFirstMediaUrl('photo'),
                'clients_count' => $manager->users_count,
                'has_erp_uuid' => filled($manager->erp_uuid),
                'account' => $manager->user ? [
                    'name' => $manager->user->name,
                    'email' => $manager->user->email,
                ] : null,
            ]);

        return Inertia::render('Crm/Pages/Team/Index', [
            'managers' => $managers,
        ]);
    }
}
