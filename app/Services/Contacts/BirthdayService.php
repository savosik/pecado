<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\CrmTaskService;
use Illuminate\Support\Collection;

/**
 * Дни рождения контактов.
 *
 * Одна из двух причин, по которым менеджеру выгодно завести карточку для себя,
 * а не «для системы» (вторая — выгрузка в телефон). Поздравить бухгалтера
 * контрагента дешевле любой скидки.
 *
 * Год у половины людей неизвестен, поэтому всё считается по месяцу и числу,
 * а возраст не показывается вовсе.
 */
class BirthdayService
{
    /**
     * Ближайшие дни рождения.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function upcoming(User $actor, int $days = 14): Collection
    {
        $contacts = Contact::query()
            ->visibleInCrm($actor)
            ->active()
            ->whereNotNull('birthday')
            ->with('client:id,name,erp_name')
            ->get();

        $today = now()->startOfDay();

        return $contacts
            ->map(function (Contact $contact) use ($today, $days): ?array {
                $next = $this->nextOccurrence($contact);

                if ($next === null) {
                    return null;
                }

                $daysLeft = (int) $today->diffInDays($next, false);

                if ($daysLeft < 0 || $daysLeft > $days) {
                    return null;
                }

                return [
                    'id' => (int) $contact->getKey(),
                    'full_name' => $contact->full_name,
                    'position' => $contact->position,
                    'avatar_url' => $contact->avatarUrl(),
                    'client' => $contact->client === null ? null : [
                        'id' => (int) $contact->client->getKey(),
                        'name' => (string) $contact->client->display_name,
                    ],
                    'date_label' => $next->translatedFormat('d F'),
                    'days_left' => $daysLeft,
                    'is_today' => $daysLeft === 0,
                    'url' => route('crm.contacts.show', $contact->getKey()),
                ];
            })
            ->filter()
            ->sortBy('days_left')
            ->values();
    }

    /**
     * Поставить задачи «Поздравить» на завтрашние дни рождения.
     *
     * Идемпотентно: повторный прогон планировщика не плодит задачи — сверка
     * идёт по привязке и названию в пределах дня.
     *
     * @return int сколько задач заведено
     */
    public function scheduleGreetings(CrmTaskService $tasks, int $daysAhead = 1): int
    {
        $target = now()->addDays($daysAhead)->startOfDay();
        $created = 0;

        Contact::query()
            ->active()
            ->whereNotNull('birthday')
            ->whereNotNull('client_user_id')
            ->with('client')
            ->chunkById(200, function ($chunk) use ($target, $tasks, &$created): void {
                foreach ($chunk as $contact) {
                    $next = $this->nextOccurrence($contact);

                    if ($next === null || ! $next->isSameDay($target)) {
                        continue;
                    }

                    $manager = $this->managerOf($contact);

                    // Без персонального менеджера задачу ставить некому:
                    // поручение «никому» лежало бы мёртвым грузом.
                    if ($manager === null) {
                        continue;
                    }

                    if ($this->alreadyPlanned($contact, $target)) {
                        continue;
                    }

                    $tasks->create($manager, [
                        'title' => 'Поздравить: '.$contact->full_name,
                        'description' => $this->description($contact),
                        'assignee_id' => $manager->getKey(),
                        'due_at' => $target->copy()->setTime(10, 0)->toDateTimeString(),
                    ], $contact);

                    $created++;
                }
            });

        return $created;
    }

    /**
     * Ближайшее наступление дня рождения — в этом году или в следующем.
     */
    public function nextOccurrence(Contact $contact): ?\Carbon\CarbonInterface
    {
        if ($contact->birthday === null) {
            return null;
        }

        $today = now()->startOfDay();
        $next = $contact->birthday->copy()->setYear($today->year)->startOfDay();

        if ($next->lt($today)) {
            $next->addYear();
        }

        return $next;
    }

    private function managerOf(Contact $contact): ?User
    {
        $managerId = $contact->client?->personal_manager_id;

        if ($managerId === null) {
            return null;
        }

        return PersonalManager::query()->with('user')->find($managerId)?->user;
    }

    /**
     * Такая задача уже стоит.
     *
     * Сверяем по привязке к человеку и сроку: название менеджер мог поправить,
     * а поручение осталось тем же.
     */
    private function alreadyPlanned(Contact $contact, \Carbon\CarbonInterface $target): bool
    {
        return CrmTask::query()
            ->where('related_type', Contact::class)
            ->where('related_id', $contact->getKey())
            ->whereDate('due_at', $target->toDateString())
            ->exists();
    }

    private function description(Contact $contact): string
    {
        $parts = [];

        if (filled($contact->greeting_name)) {
            $parts[] = 'Обращаться: '.$contact->greeting_name;
        }

        if ($contact->preferred_channel !== null) {
            $parts[] = 'Предпочитает: '.$contact->preferred_channel->label();
        }

        if (filled($contact->phone)) {
            $parts[] = 'Телефон: '.$contact->phone;
        }

        return $parts === [] ? 'День рождения контакта.' : implode('. ', $parts).'.';
    }
}
