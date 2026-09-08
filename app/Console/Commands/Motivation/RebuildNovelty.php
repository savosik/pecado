<?php

namespace App\Console\Commands\Motivation;

use App\Models\Motivation\MotivationPartnerNovelty;
use App\Services\Motivation\NoveltyCalculator;
use Illuminate\Console\Command;

/**
 * Пересчёт кэша новизны партнёров.
 *
 * Кэш производен от отгрузок: пересчёт ничего не теряет и выполняется целиком
 * каждую ночь. Партнёр, впервые купивший сегодня, станет Новым к утру —
 * задержка сознательная: месячный расчёт от одного дня не зависит.
 */
class RebuildNovelty extends Command
{
    protected $signature = 'motivation:rebuild-novelty {--partner=* : id партнёров; по умолчанию все}';

    protected $description = 'Пересчитать кэш новизны партнёров: первая покупка, перерыв, границы периода новизны';

    public function handle(NoveltyCalculator $calculator): int
    {
        $partnerIds = array_map('intval', (array) $this->option('partner'));

        $count = $calculator->rebuild($partnerIds);

        $inNovelty = MotivationPartnerNovelty::query()
            ->whereNotNull('novelty_started_on')
            ->whereDate('novelty_ends_on', '>=', now()->startOfMonth())
            ->count();

        $incomplete = MotivationPartnerNovelty::query()->where('history_incomplete', true)->count();
        $neverBought = MotivationPartnerNovelty::query()->whereNull('first_shipment_on')->count();

        $this->info(sprintf('Пересчитано партнёров: %d', $count));
        $this->line(sprintf('В периоде новизны сейчас: %d', $inNovelty));
        $this->line(sprintf('Ни разу не покупали (станут Новыми при первой покупке): %d', $neverBought));
        $this->line(sprintf('Перерыв подтвердить нельзя — первая покупка в первом месяце истории: %d', $incomplete));

        return self::SUCCESS;
    }
}
