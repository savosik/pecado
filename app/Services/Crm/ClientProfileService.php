<?php

namespace App\Services\Crm;

use App\Models\CrmClientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Единственная точка изменения профиля партнёра.
 *
 * Через неё ходят и форма карточки, и (в волне 5) ИИ-агент менеджера: правило
 * «изменилась заметка — сохрани предыдущую версию» должно выполняться независимо
 * от того, кто именно сохраняет.
 */
class ClientProfileService
{
    /**
     * Профиль партнёра — существующий или пустой заготовкой.
     *
     * Незаписанный экземпляр вместо null: карточка партнёра должна открываться
     * и у партнёра, которого ещё никто не описывал.
     */
    public function forClient(User $client): CrmClientProfile
    {
        return $client->crmProfile()->firstOrNew([]);
    }

    /**
     * @param  array<string, mixed>  $data  уже провалидированные поля профиля;
     *                                      ключ 'interests' (list<string>) — теги интересов
     */
    public function update(User $client, array $data, User $actor): CrmClientProfile
    {
        return DB::transaction(function () use ($client, $data, $actor): CrmClientProfile {
            $profile = $this->forClient($client);

            $previousNotes = $profile->notes_md;
            $notesChanged = array_key_exists('notes_md', $data)
                && $this->normalizeNotes($data['notes_md']) !== $this->normalizeNotes($previousNotes);

            $profile->fill(array_diff_key($data, ['interests' => null]));

            if ($notesChanged) {
                $profile->notes_md = $this->normalizeNotes($data['notes_md']);
                $profile->notes_updated_at = now();
                $profile->notes_updated_by = $actor->getKey();
            }

            $profile->client()->associate($client);
            $profile->save();

            // Первое заполнение ревизией не считаем: сохранять «до этого было пусто»
            // нечего, а лишняя запись только мусорит историю.
            if ($notesChanged && $this->normalizeNotes($previousNotes) !== null) {
                $profile->revisions()->create([
                    'user_id' => $actor->getKey(),
                    'notes_md' => $previousNotes,
                ]);
            }

            if (array_key_exists('interests', $data)) {
                $client->syncTagsWithType($data['interests'] ?? [], User::INTEREST_TAG_TYPE);
            }

            return $profile;
        });
    }

    /**
     * Интересы партнёра как простой список названий.
     *
     * @return list<string>
     */
    public function interests(User $client): array
    {
        return $client->tagsWithType(User::INTEREST_TAG_TYPE)
            ->map(fn (Model $tag): string => (string) $tag->getAttribute('name'))
            ->values()
            ->all();
    }

    /**
     * Пустая строка из редактора — это «заметок нет», а не текст из нуля символов.
     */
    private function normalizeNotes(?string $notes): ?string
    {
        $notes = $notes === null ? null : trim($notes);

        return $notes === '' ? null : $notes;
    }
}
