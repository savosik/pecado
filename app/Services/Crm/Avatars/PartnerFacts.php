<?php

namespace App\Services\Crm\Avatars;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Факты о партнёре, из которых текстовая модель придумывает промт.
 *
 * Ровно то, что делает партнёра узнаваемым в списке: кто он (человек или
 * компания), в какой лиге по деньгам, чем торгует, откуда и как давно с нами.
 * Ни долгов, ни просрочки, ни жалоб — аватарка должна веселить, а не
 * припоминать; шутка про должника, которую менеджер показывает коллеге,
 * рано или поздно окажется на экране при самом должнике.
 */
class PartnerFacts
{
    /** Годовой оборот, ниже которого партнёр «небольшой». */
    private const LEAGUE_SMALL = 300_000.0;

    /** Порог «крупного» партнёра. */
    private const LEAGUE_LARGE = 3_000_000.0;

    /**
     * @return array{
     *     name: string,
     *     kind: string,
     *     gender: string,
     *     league: string,
     *     revenue_year: float,
     *     city: string|null,
     *     business_type: string|null,
     *     years_with_us: int|null,
     *     interests: list<string>
     * }
     */
    public function for(User $client): array
    {
        $profile = $client->crmProfile;
        $revenue = $this->revenueLastYear($client);

        return [
            'name' => (string) $client->display_name,
            'kind' => $this->kind($client),
            'gender' => $this->gender($client),
            'league' => $this->league($revenue),
            'revenue_year' => $revenue,
            'city' => $client->city ?: null,
            'business_type' => $profile?->business_type?->label(),
            'years_with_us' => (int) ($client->created_at?->diffInYears(now()) ?? 0) ?: null,
            'interests' => $this->interests($client),
        ];
    }

    /**
     * Человек или организация: у ИП аватарка про человека, у ООО — про дело.
     *
     * Формы ищутся отдельными словами, а не подстрокой: «ао» внутри «Каолина»
     * и «ип» внутри «предпринимателя» превращали бы кого попало в юрлицо.
     * Признак человека сильнее: «Индивидуальный предприниматель Дорофеева Анна
     * Сергеевна» — это человек, хотя слово «предприниматель» длиннее фамилии.
     */
    private function kind(User $client): string
    {
        $name = Str::lower($client->display_name);

        if (preg_match('/(^|\W)(ип|индивидуальный предприниматель|глава кфх)(\W|$)/u', $name)
            || $this->looksLikeFullName($client->display_name)
        ) {
            return 'человек';
        }

        return 'организация';
    }

    /**
     * Пол по русскому ФИО: сперва отчество (оно однозначно), потом имя.
     *
     * Возвращает «неизвестен» на всём, что не разобралось, — выдуманный пол
     * хуже отсутствующего: промт с «мужчина» на имя Оксана даёт аватарку,
     * которую менеджер сразу и удалит.
     */
    private function gender(User $client): string
    {
        $words = preg_split('/\s+/u', trim($client->display_name)) ?: [];

        foreach ($words as $word) {
            $word = Str::lower(trim($word, '"«»().,'));

            if (Str::endsWith($word, ['овна', 'евна', 'ична', 'инична'])) {
                return 'женщина';
            }

            if (Str::endsWith($word, ['ович', 'евич', 'ич']) && mb_strlen($word) > 5) {
                return 'мужчина';
            }
        }

        return 'неизвестен';
    }

    /**
     * Лига по обороту за год — грубая шкала для промта, а не отчётный
     * показатель: считается прямо по отгрузкам, потому что в шутку про
     * «королеву опта» точность до копейки не входит. Всё, что показывают
     * менеджеру как выручку, по-прежнему считает ShipmentAnalyticsService.
     */
    private function revenueLastYear(User $client): float
    {
        return (float) Shipment::query()
            ->where('user_id', $client->getKey())
            ->whereNull('deleted_at')
            ->where('erp_created_at', '>=', now()->subYear())
            ->sum('total_amount');
    }

    private function league(float $revenue): string
    {
        return match (true) {
            $revenue <= 0.0 => 'пока ничего не покупал',
            $revenue < self::LEAGUE_SMALL => 'небольшой',
            $revenue < self::LEAGUE_LARGE => 'средний',
            default => 'крупный',
        };
    }

    /**
     * Интересы партнёра — теги, которые ставит менеджер («вибраторы премиум»,
     * «БДСМ»). Берём не больше пяти: длинный список делает промт размытым,
     * и модель рисует всё сразу и ничего конкретно.
     *
     * @return list<string>
     */
    private function interests(User $client): array
    {
        return $client->tagsWithType(User::INTEREST_TAG_TYPE)
            ->map(fn ($tag): string => (string) $tag->getAttribute('name'))
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * Похоже на ФИО: три слова подряд с большой буквы, в любом месте строки.
     *
     * Именно подряд и в любом месте: в базе встречается и «Дорофеева Анна
     * Сергеевна», и «Индивидуальный предприниматель Дорофеева Анна Сергеевна»,
     * и «Дорофеева Анна Сергеевна ИП, г.Москва».
     */
    private function looksLikeFullName(string $name): bool
    {
        $words = preg_split('/[\s,]+/u', trim($name)) ?: [];
        $streak = 0;

        foreach ($words as $word) {
            $streak = preg_match('/^[А-ЯЁ][а-яё-]{1,}$/u', $word) ? $streak + 1 : 0;

            if ($streak >= 3) {
                return true;
            }
        }

        return false;
    }
}
