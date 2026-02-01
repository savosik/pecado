<?php

namespace App\Http\Controllers\Admin;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class CertificateController extends AdminController
{
    /**
     * Display a listing of the certificates.
     */
    public function index(Request $request): Response
    {
        $query = Certificate::query()->with('media');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('external_id', 'like', "%{$search}%");
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'external_id', 'created_at', 'issued_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $certificates = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Certificates/Index', [
            'certificates' => $certificates,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new certificate.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Certificates/Create');
    }

    /**
     * Store a newly created certificate in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'issued_at' => 'nullable|date',
            'files' => 'nullable|array',
            'files.*' => 'file|max:10240',
        ]);

        $certificate = Certificate::create($validated);

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $certificate->addMedia($file)
                    ->toMediaCollection('files');
            }
        }

        return redirect()
            ->route('admin.certificates.index')
            ->with('success', 'Сертификат успешно создан');
    }

    /**
     * Show the form for editing the specified certificate.
     */
    public function edit(Certificate $certificate): Response
    {
        $certificate->load('media');

        return Inertia::render('Admin/Pages/Certificates/Edit', [
            'certificate' => [
                'id' => $certificate->id,
                'name' => $certificate->name,
                'external_id' => $certificate->external_id,
                'type' => $certificate->type,
                'issued_at' => $certificate->issued_at?->format('Y-m-d'),
                'media' => $certificate->getMedia('files')->map(fn($m) => [
                    'id' => $m->id,
                    'url' => $m->getUrl(),
                    'name' => $m->file_name,
                ]),
            ],
        ]);
    }

    /**
     * Update the specified certificate in storage.
     */
    public function update(Request $request, Certificate $certificate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'issued_at' => 'nullable|date',
            'files' => 'nullable|array',
            'files.*' => 'file|max:10240',
        ]);

        $certificate->update($validated);

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $certificate->addMedia($file)
                    ->toMediaCollection('files');
            }
        }

        return redirect()
            ->route('admin.certificates.index')
            ->with('success', 'Сертификат успешно обновлен');
    }

    /**
     * Remove the specified certificate from storage.
     */
    public function destroy(Certificate $certificate): RedirectResponse
    {
        $certificate->delete();

        return redirect()
            ->route('admin.certificates.index')
            ->with('success', 'Сертификат успешно удален');
    }

    /**
     * Delete a specific media file from the certificate.
     */
    public function deleteMedia(Certificate $certificate, Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $certificate->media()->findOrFail($validated['media_id']);
        $media->delete();

        return response()->json(['success' => true]);
    }
}
