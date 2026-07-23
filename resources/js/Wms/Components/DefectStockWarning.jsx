import { Text } from '@chakra-ui/react';
import { Alert } from '@/components/ui/alert';

/**
 * Предупреждение о нехватке остатка на складе некондиции.
 *
 * Брак должен быть сначала оприходован в 1С на склад некондиции: если товара
 * там нет, партия встанет на витрину поверх нулевого остатка и заказ по ней
 * не отгрузится. Предупреждение не блокирует заведение — кладовщик может
 * заводить партию до того, как 1С пришлёт остатки.
 *
 * @param {object|null} product - выбранный товар; остаток берётся из product.defect_stock
 * @param {string|number} warehouseId - выбранный склад некондиции
 * @param {number} quantity - количество в заводимой партии
 */
export function DefectStockWarning({ product, warehouseId, quantity = 1 }) {
    if (!product || !warehouseId) {
        return null;
    }

    const available = Number(product.defect_stock?.[String(warehouseId)] ?? 0);
    const needed = Number(quantity) || 0;

    if (available <= 0) {
        return (
            <Alert status="warning" title="Товара нет на складе некондиции">
                По данным 1С на складе некондиции этого товара нет. Проверьте, оприходован ли
                брак — партию завести можно, но продать её не получится, пока не придут остатки.
            </Alert>
        );
    }

    if (available < needed) {
        return (
            <Alert status="warning" title="Остатка меньше, чем в партии">
                На складе некондиции числится {available} шт., а вы заводите {needed} шт.
                Проверьте остатки в 1С.
            </Alert>
        );
    }

    return (
        <Text fontSize="xs" color="fg.muted">
            Остаток на складе некондиции: {available} шт.
        </Text>
    );
}
