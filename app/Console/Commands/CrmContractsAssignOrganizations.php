<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractCategory;
use Illuminate\Console\Command;

/**
 * Разнести наше юрлицо по договорам, у которых оно не указано.
 *
 * Источник — организация категории-вкладки: реестр менеджеров и был заведён
 * «по юрлицу». Нужна после того, как РОП привяжет к категории организацию,
 * которой не было в справочнике на момент миграции (ИП Кербер).
 */
class CrmContractsAssignOrganizations extends Command
{
    protected $signature = 'crm:contracts-assign-organizations {--dry-run : Только показать, ничего не менять}';

    protected $description = 'Проставить договорам наше юрлицо по организации их категории';

    public function handle(): int
    {
        $rows = [];
        $total = 0;

        foreach (ContractCategory::query()->with('organization:id,name')->ordered()->get() as $category) {
            $pending = Contract::query()
                ->where('category_id', $category->getKey())
                ->whereNull('organization_id');

            $count = (clone $pending)->count();

            if ($category->organization_id === null) {
                $rows[] = [$category->name, '— не привязана', $count, 'ждёт привязки категории'];

                continue;
            }

            if ($count > 0 && ! $this->option('dry-run')) {
                $pending->update(['organization_id' => $category->organization_id]);
            }

            $total += $count;
            $rows[] = [$category->name, $category->organization?->name, $count, $this->option('dry-run') ? 'будет проставлено' : 'проставлено'];
        }

        $this->table(['Категория', 'Организация', 'Договоров без юрлица', 'Результат'], $rows);

        $left = Contract::query()->whereNull('organization_id')->count();
        $this->info(sprintf('%s: %d, без юрлица осталось: %d.', $this->option('dry-run') ? 'К разносу' : 'Разнесено', $total, $left));

        return self::SUCCESS;
    }
}
