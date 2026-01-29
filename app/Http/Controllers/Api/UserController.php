<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Authorize: typically only admins can view the list
        \Illuminate\Support\Facades\Gate::authorize('viewAny', User::class);

        $users = User::paginate(15);

        return response()->json($users);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('view', $user);

        return response()->json($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('update', $user);

        // Validate request data
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'surname' => 'sometimes|nullable|string|max:255',
            'patronymic' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'country' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'is_subscribed' => 'sometimes|boolean',
            'terms_accepted' => 'sometimes|boolean',
            'comment' => 'sometimes|nullable|string',
            'status' => 'sometimes|string',
            'is_admin' => 'sometimes|boolean',
            'currency_id' => 'sometimes|nullable|exists:currencies,id',
        ]);

        // Only admin can change is_admin status
        if (isset($validated['is_admin']) && !$request->user()->is_admin) {
            unset($validated['is_admin']);
        }
        
        // Use user's policy or logic for sensitive fields like status/comment if needed
        // For now allowing update if authorized

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

}
