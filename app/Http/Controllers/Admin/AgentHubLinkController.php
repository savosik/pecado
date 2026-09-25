<?php

namespace App\Http\Controllers\Admin;

use App\Models\AgentHubLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ссылки-хеши на пульт Agent Hub.
 *
 * Выдаются поимённо: «Админ 1С», «Наш админ». По ссылке человек попадает
 * на пульт без авторизации, а внешний агент авторизует ей API создания
 * топиков. Отзыв — одной кнопкой: ссылка перестаёт работать целиком.
 */
class AgentHubLinkController extends AdminController
{
    public function index(): Response
    {
        $links = AgentHubLink::query()
            ->with('creator:id,name')
            ->withCount('topics')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AgentHubLink $link) => [
                'id' => $link->id,
                'label' => $link->label,
                'note' => $link->note,
                'url' => $link->url(),
                'api_url' => url("/api/agent-hub/links/{$link->token}/topics"),
                'topics_count' => $link->topics_count,
                'created_by' => $link->creator?->name,
                'created_at' => $link->created_at?->format('d.m.Y H:i'),
                'last_used_at' => $link->last_used_at?->format('d.m.Y H:i'),
                'revoked_at' => $link->revoked_at?->format('d.m.Y H:i'),
            ]);

        return Inertia::render('Admin/Pages/AgentTopics/Links', [
            'links' => $links,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'note' => 'nullable|string|max:2000',
        ], [
            'label.required' => 'Укажите, кому выдаётся ссылка.',
        ]);

        AgentHubLink::create([
            'label' => $validated['label'],
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Ссылка создана. Скопируйте её и передайте адресату — показать повторно можно в этом же списке.');
    }

    /** Отзыв: ссылка и её API перестают работать, топики остаются. */
    public function destroy(AgentHubLink $agentHubLink): RedirectResponse
    {
        if ($agentHubLink->isRevoked()) {
            return back()->with('error', 'Ссылка уже отозвана.');
        }

        $agentHubLink->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', 'Ссылка отозвана.');
    }
}
