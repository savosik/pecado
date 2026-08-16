import { router } from '@inertiajs/react';
import { Badge, Box, HStack, Text } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';

/**
 * Страховой запас остатков (эпик buf-00, карточка buf-02).
 *
 * Галочку ставит только менеджер руками: рекомендация по анкете — бейдж,
 * а не автопроставление, потому что business_type может быть неточным.
 */
export default function StockBufferPanel({ clientId, stockBuffer, canEdit }) {
    if (!stockBuffer) {
        return null;
    }

    const toggle = (enabled) => {
        router.put(
            route('crm.clients.stock-buffer.update', clientId),
            { enabled },
            { preserveScroll: true },
        );
    };

    return (
        <Box pt={3} borderTopWidth="1px">
            <HStack gap={3} align="center" flexWrap="wrap">
                <Switch
                    size="sm"
                    checked={stockBuffer.enabled}
                    disabled={!canEdit}
                    onCheckedChange={(details) => toggle(details.checked)}
                >
                    <Text fontSize="sm" fontWeight="600">Страховой запас остатков</Text>
                </Switch>
                {stockBuffer.recommended && !stockBuffer.enabled && (
                    <Badge colorPalette="blue" variant="subtle">
                        похоже на интернет-магазин — рекомендуем включить
                    </Badge>
                )}
            </HStack>
            <Text fontSize="xs" color="fg.muted" mt={1}>
                Партнёр видит слегка заниженный остаток по позициям, которые склад
                отметил как рискованные — там чаще всего попадаются брак, порванная
                упаковка или неработающие экземпляры. Скрытый запас оставляет
                кладовщику выбор: не отгружать сомнительную единицу только потому,
                что она последняя. Остальные партнёры и витрина видят полный остаток.
            </Text>
        </Box>
    );
}
