import { Badge, HStack } from '@chakra-ui/react';

/**
 * Состояние партии некондиции одним взглядом.
 *
 * Состояние — производное, а не поле в БД: партия продаётся, когда назначена цена,
 * включена публикация, партия не закрыта и есть свободный остаток.
 * Порядок проверок повторяет ProductDefect::scopeSellable + DefectStockService.
 *
 * @param {Object} defect - Партия с полями price, is_published, closed_at, available_quantity
 */
export function DefectStatusBadge({ defect }) {
    if (defect.closed_at) {
        return (
            <Badge colorPalette="gray" variant="subtle">
                {defect.closed_reason_label || 'Закрыта'}
            </Badge>
        );
    }

    if (defect.price === null) {
        return <Badge colorPalette="orange" variant="subtle">Без цены</Badge>;
    }

    if (!defect.is_published) {
        return <Badge colorPalette="yellow" variant="subtle">Не опубликована</Badge>;
    }

    if (defect.available_quantity === 0) {
        return <Badge colorPalette="purple" variant="subtle">Вся в резерве</Badge>;
    }

    return (
        <HStack gap={1}>
            <Badge colorPalette="green" variant="subtle">В продаже</Badge>
            {defect.reserved_quantity > 0 && (
                <Badge colorPalette="purple" variant="outline">
                    в резерве: {defect.reserved_quantity}
                </Badge>
            )}
        </HStack>
    );
}
