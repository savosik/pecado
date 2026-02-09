<?php

namespace App\Http\Controllers\Admin\Traits;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Trait for handling conditional redirect after save.
 *
 * Supports two actions:
 * - "Сохранить" (stays on the edit page)
 * - "Сохранить и закрыть" (redirects to the index list)
 *
 * The frontend sends `_close=1` when "Save and Close" is clicked.
 */
trait RedirectsAfterSave
{
    /**
     * Redirect after successful store/update.
     *
     * @param Request $request
     * @param string $indexRoute Route name for the index page (e.g., 'admin.brands.index')
     * @param string $editRoute Route name for the edit page (e.g., 'admin.brands.edit')
     * @param mixed $model The model instance or ID to pass to the edit route
     * @param string $successMessage Flash message
     * @return RedirectResponse
     */
    protected function redirectAfterSave(
        Request $request,
        string $indexRoute,
        string $editRoute,
        mixed $model,
        string $successMessage = 'Сохранено успешно'
    ): RedirectResponse {
        if ($request->boolean('_close')) {
            return redirect()
                ->route($indexRoute)
                ->with('success', $successMessage);
        }

        return redirect()
            ->route($editRoute, $model)
            ->with('success', $successMessage);
    }
}
