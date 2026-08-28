<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Скоуп «без документов внутренних юрлиц» для того, что видит клиент.
 *
 * В 1С есть техническая организация «Реклама»: на неё проводятся рекламные
 * образцы и прочие внутренние операции, к расчётам с клиентом они отношения
 * не имеют. Регистр взаиморасчётов такие движения отбрасывает на входе
 * (`organizations.is_settlements_excluded`), а вот документы — реализации,
 * печатные формы, платежи — сайт принимает и хранит: менеджеру в CRM они нужны.
 * Клиенту же реализация «Рекламы» на 0,58 ₽ со статусом «не оплачена» читается
 * как долг, а её счёт и акт сверки — как требование денег.
 *
 * Поэтому граница проходит на выдаче: всё, что уходит клиенту (кабинет,
 * клиентское API, письма), берёт документы через этот скоуп. Документы без
 * организации остаются: у них нечего исключать.
 */
trait HidesInternalOrganizations
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutInternalOrganizations(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(fn (Builder $inner) => $inner
            ->whereNull($table.'.organization_id')
            ->orWhereNotIn(
                $table.'.organization_id',
                Organization::withTrashed()->settlementsExcluded()->select('organizations.id'),
            ));
    }

    /**
     * Проведён ли документ от внутреннего юрлица — для одиночных карточек,
     * где модель уже загружена через route binding.
     */
    public function isFromInternalOrganization(): bool
    {
        if ($this->organization_id === null) {
            return false;
        }

        // Загруженная связь — без лишнего запроса на каждую строку списка.
        if ($this->relationLoaded('organization') && $this->organization !== null
            && array_key_exists('is_settlements_excluded', $this->organization->getAttributes())) {
            return (bool) $this->organization->getAttribute('is_settlements_excluded');
        }

        return in_array((int) $this->organization_id, Organization::settlementsExcludedIds(), true);
    }
}
