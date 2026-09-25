import { Box, HStack, Stack, Text } from '@chakra-ui/react';
import { LuShieldCheck } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

const ARG_LABELS = {
    products: 'Позиции',
    company_id: 'Юрлицо (id)',
    delivery_method: 'Доставка',
    address: 'Адрес',
    comment: 'Комментарий',
    reserve: 'В резерв',
    apply_promotions: 'Акции',
    subject: 'Тема',
    body: 'Текст',
    reason: 'Причина',
    cart_id: 'Корзина (id)',
    order: 'Заказ (id)',
    items: 'Позиции',
};

const DELIVERY = { delivery: 'доставка', pickup: 'самовывоз' };

function renderValue(key, value) {
    if (value === null || value === undefined || value === '') return null;
    if (key === 'delivery_method') return DELIVERY[value] || String(value);
    if (typeof value === 'boolean') return value ? 'да' : 'нет';
    if (Array.isArray(value)) {
        if (value.length === 0) return null;
        if (typeof value[0] === 'object' && value[0] !== null) {
            return (
                <Stack gap="0.5" as="ul" pl="4" style={{ listStyle: 'disc' }}>
                    {value.slice(0, 15).map((row, i) => (
                        <li key={i}>
                            {row.identifier ?? row.sku ?? row.product ?? row.id ?? '?'}
                            {row.quantity !== undefined ? ` × ${row.quantity}` : ''}
                        </li>
                    ))}
                    {value.length > 15 && <li>… ещё {value.length - 15}</li>}
                </Stack>
            );
        }
        return value.join(', ');
    }
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

/**
 * Карточка необратимого действия: что именно будет сделано и две кнопки.
 * Заказ уходит в 1С — модель предлагает, человек нажимает.
 */
export default function ConfirmationCard({ confirmation, onDecide, disabled = false }) {
    const summary = confirmation.summary || {};
    const args = summary.arguments || {};
    const entries = Object.entries(args)
        .filter(([key]) => key !== 'idempotency_key')
        .map(([key, value]) => [key, renderValue(key, value)])
        .filter(([, v]) => v !== null);

    return (
        <Box borderWidth="1px" borderColor="pecado.300" bg="pecado.50" borderRadius="lg" p="3" alignSelf="stretch">
            <HStack gap="2" mb="2">
                <LuShieldCheck size={16} />
                <Text fontWeight="semibold" fontSize="sm">
                    {summary.label || confirmation.operation}
                </Text>
            </HStack>
            {summary.company?.name && (
                <Text fontSize="xs" color="fg.muted" mb="1">
                    Юрлицо: {summary.company.name}
                </Text>
            )}
            <Stack gap="1" fontSize="xs" mb="3">
                {entries.map(([key, value]) => (
                    <HStack key={key} align="flex-start" gap="2">
                        <Text color="fg.subtle" minW="90px">{ARG_LABELS[key] || key}:</Text>
                        <Box flex="1" wordBreak="break-word">{value}</Box>
                    </HStack>
                ))}
            </Stack>
            <HStack gap="2">
                <Button size="sm" colorPalette="pecado" onClick={() => onDecide(confirmation.id, true)} disabled={disabled}>
                    Подтвердить
                </Button>
                <Button size="sm" variant="outline" onClick={() => onDecide(confirmation.id, false)} disabled={disabled}>
                    Отмена
                </Button>
            </HStack>
            <Text fontSize="xs" color="fg.subtle" mt="2">
                Действие необратимо: заказ уходит в 1С и на склад.
            </Text>
        </Box>
    );
}
