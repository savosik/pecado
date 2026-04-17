<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class UserPreviewController extends Controller
{
    public function show(string $token): Response
    {
        $user = User::where('view_token', $token)
            ->with(['questionnaire', 'region', 'clientStatus'])
            ->firstOrFail();

        return Inertia::render('UserPreview', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'country' => $user->country?->value,
                'city' => $user->city,
                'status' => $user->status->value,
                'status_label' => $user->status_label,
                'comment' => $user->comment,
                'region' => $user->region?->name,
                'client_status' => $user->clientStatus?->name,
                'is_subscribed' => $user->is_subscribed,
                'erp_id' => $user->erp_id,
                'created_at' => $user->created_at?->toDateTimeString(),
            ],
            'questionnaire' => $user->questionnaire && $user->questionnaire->isCompleted()
                ? [
                    'business_type' => $user->questionnaire->business_type,
                    'business_name' => $user->questionnaire->business_name,
                    'website_url' => $user->questionnaire->website_url,
                    'years_in_business' => $user->questionnaire->years_in_business,
                    'monthly_order_volume' => $user->questionnaire->monthly_order_volume,
                    'has_physical_store' => $user->questionnaire->has_physical_store,
                    'store_count' => $user->questionnaire->store_count,
                    'product_categories' => $user->questionnaire->product_categories,
                    'how_found_us' => $user->questionnaire->how_found_us,
                    'additional_info' => $user->questionnaire->additional_info,
                    'completed_at' => $user->questionnaire->completed_at?->toDateTimeString(),
                ]
                : null,
        ]);
    }
}
