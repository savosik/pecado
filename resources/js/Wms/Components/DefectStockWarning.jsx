import { Text } from '@chakra-ui/react';
import { Alert } from '@/components/ui/alert';

/**
 * Остаток склада некондиции под заводимую партию: сколько числится в 1С,
 * сколько уже разобрано другими открытыми партиями и сколько осталось свободным.
 *
 * Брак должен быть сначала оприходован в 1С на склад некондиции: если товара
 * там нет, партия встанет на витрину поверх нулевого остатка и заказ по ней
 * не отгрузится. Второй случай — остаток есть, но его уже расписали партиями:
 * тогда новая партия задваивает один и тот же товар. Предупреждение не
 * блокирует заведение — кладовщик может заводить партию до того, как 1С
 * пришлёт остатки.
 *
 * Цифры берутся либо из выбранного товара (product.defect_stock /
 * product.defect_covered по складу), либо передаются напрямую числами —
 * так их отдаёт карточка уже заведённой партии.
 *
 * @param {object|null} product - выбранный товар
 * @param {string|number|null} warehouseId - выбранный склад некондиции
 * @param {number} quantity - количество в заводимой партии
 * @param {number|null} stock - остаток склада числом (в обход product)
 * @param {number} covered - объём других открытых партий числом (в обход product)
 */
export function DefectStockWarning({
    product = null,
    warehouseId = null,
    quantity = 1,
    stock = null,
    covered = 0,
}) {
    const resolved = resolveStock({ product, warehouseId, stock, covered });

    if (!resolved) {
        return null;
    }

    const available = resolved.stock;
    const inBatches = resolved.covered;
    const free = available - inBatches;
    const needed = Number(quantity) || 0;

    if (available <= 0) {
        return (
            <Alert status="warning" title="Товара нет на складе некондиции">
                По данным 1С на складе некондиции этого товара нет. Проверьте, оприходован ли
                брак — партию завести можно, но продать её не получится, пока не придут остатки.
            </Alert>
        );
    }

    if (free <= 0) {
        return (
            <Alert status="warning" title="Остаток уже расписан по партиям">
                На складе числится {available} шт., и все они уже разобраны другими партиями
                ({inBatches} шт.). Нераспределённого остатка нет — проверьте, не заводите ли
                вы тот же брак повторно.
            </Alert>
        );
    }

    if (free < needed) {
        return (
            <Alert status="warning" title="Больше, чем нераспределённый остаток">
                Нераспределённого остатка {free} шт. (на складе {available} шт., из них {inBatches} шт.
                уже в других партиях), а вы заводите {needed} шт. Проверьте остатки в 1С.
            </Alert>
        );
    }

    return (
        <Text fontSize="xs" color="fg.muted">
            Нераспределённый остаток: <Text as="span" fontWeight="medium" color="fg">{free} шт.</Text>
            {' '}(на складе {available} шт.
            {inBatches > 0 ? `, из них ${inBatches} шт. уже в других партиях` : ''})
        </Text>
    );
}

/**
 * @returns {{stock: number, covered: number}|null}
 */
function resolveStock({ product, warehouseId, stock, covered }) {
    if (stock !== null && stock !== undefined) {
        return { stock: Number(stock) || 0, covered: Number(covered) || 0 };
    }

    if (!product || !warehouseId) {
        return null;
    }

    const key = String(warehouseId);

    return {
        stock: Number(product.defect_stock?.[key] ?? 0),
        covered: Number(product.defect_covered?.[key] ?? 0),
    };
}
