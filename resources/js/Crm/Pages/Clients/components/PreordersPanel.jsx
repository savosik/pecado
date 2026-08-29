import { router } from '@inertiajs/react';
import { Badge, Box, HStack, Text } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';

/**
 * Предзаказы партнёра: предлагать ли ему заказ товара без остатка у поставщика.
 *
 * Выключают тем, кто набивает количества «на автомате», а через день просит
 * удалить предзаказ из 1С. Партнёр может переключить то же сам в кабинете —
 * оба переключения пишутся в журнал статусов с автором.
 */
export default function PreordersPanel({ clientId, preorders, canEdit }) {
    if (!preorders) {
        return null;
    }

    const toggle = (enabled) => {
        router.put(
            route('crm.clients.preorders.update', clientId),
            { enabled },
            { preserveScroll: true },
        );
    };

    const lead = preorders.lead_label ? ` (поставка ${preorders.lead_label})` : '';

    return (
        <Box pt={3} borderTopWidth="1px">
            <HStack gap={3} align="center" flexWrap="wrap">
                <Switch
                    size="sm"
                    checked={preorders.enabled}
                    disabled={!canEdit}
                    onCheckedChange={(details) => toggle(details.checked)}
                >
                    <Text fontSize="sm" fontWeight="600">Предлагать предзаказ</Text>
                </Switch>
                {!preorders.enabled && (
                    <Badge colorPalette="gray" variant="subtle">
                        видит только наличие
                    </Badge>
                )}
            </HStack>
            <Text fontSize="xs" color="fg.muted" mt={1}>
                {preorders.enabled
                    ? `Товар без остатка партнёр может заказать у поставщика${lead}: корзина сама переливает лишнее в отдельный заказ-предзаказ. Если партнёр раз за разом просит удалить такие заказы — выключите: в каталоге и корзине у него останется только наличие.`
                    : 'Предзаказных складов для партнёра не существует: карточка товара без остатка показывает «Нет в наличии», корзина не даёт заказать больше, чем есть на складе, клиентское API предзаказ не создаёт. Партнёр может включить обратно сам в «Моих данных».'}
            </Text>
        </Box>
    );
}
