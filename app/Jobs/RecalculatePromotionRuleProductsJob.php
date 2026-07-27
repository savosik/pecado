<?php

namespace App\Jobs;

use App\Models\PromotionRule;
use App\Services\Promotion\PromotionRuleProductResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Материализация товаров-участников правила акции в promotion_rule_product.
 *
 * Раскрывает селекторы условий (товары, категории с потомками, бренды, теги,
 * ERP-промо) и товары наград в плоский список, чтобы каталог дёшево показывал
 * бейдж «участвует в акции» без разбора JSON на каждый товар.
 *
 * Джоба идемпотентна: список правила пересобирается целиком в транзакции
 * (delete + insert), инкрементальных правок нет. Повторный запуск дублей не плодит.
 *
 * Триггеры: обсервер PromotionRuleObserver (сохранение/удаление правила) и
 * команда promo:rebuild-rule-products (ночной батч + ручной запуск из админки).
 */
class RecalculatePromotionRuleProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $promotionRuleId)
    {
        $this->queue = 'default';
    }

    public function handle(PromotionRuleProductResolver $resolver): void
    {
        $rule = PromotionRule::withTrashed()->find($this->promotionRuleId);

        if (! $rule) {
            // Правило удалено окончательно — записи ушли каскадом
            return;
        }

        // Архивное правило участников не имеет: бейджи в каталоге не показываем
        $rows = $rule->trashed() ? [] : $this->buildRows($rule, $resolver);

        DB::transaction(function () use ($rows) {
            DB::table('promotion_rule_product')
                ->where('promotion_rule_id', $this->promotionRuleId)
                ->delete();

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('promotion_rule_product')->insert($chunk);
            }
        });
    }

    /**
     * @return array<int, array{promotion_rule_id: int, product_id: int, role: string}>
     */
    private function buildRows(PromotionRule $rule, PromotionRuleProductResolver $resolver): array
    {
        $conditionIds = $resolver->resolveConditionProducts($rule->conditions ?? []);
        $rewardIds = $resolver->resolveRewardProducts($rule->rewards ?? []);

        $rows = [];

        foreach ($conditionIds as $productId) {
            $rows[] = [
                'promotion_rule_id' => $rule->id,
                'product_id' => $productId,
                'role' => PromotionRule::ROLE_CONDITION,
            ];
        }

        foreach ($rewardIds as $productId) {
            $rows[] = [
                'promotion_rule_id' => $rule->id,
                'product_id' => $productId,
                'role' => PromotionRule::ROLE_REWARD,
            ];
        }

        return $rows;
    }
}
