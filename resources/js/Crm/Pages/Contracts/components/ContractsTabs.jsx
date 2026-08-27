import { Link } from '@inertiajs/react';
import { Badge, Box, HStack, Text } from '@chakra-ui/react';

/**
 * Вкладки реестра: «Все», категории (бывшие вкладки Google-таблицы) и
 * «Без договора» — контрагенты с реализацией или заказом без договора.
 *
 * Вкладки — ссылки, а не состояние: адрес вкладки шлют коллеге и кладут в закладки.
 */
export default function ContractsTabs({ categories = [], activeCategoryId = null, missingCount = 0, missingActive = false, scope }) {
    const tabStyle = (active) => ({
        px: 3,
        py: 2,
        borderBottomWidth: '2px',
        borderColor: active ? 'colorPalette.solid' : 'transparent',
        color: active ? 'fg' : 'fg.muted',
        fontWeight: active ? '600' : '500',
        fontSize: 'sm',
        whiteSpace: 'nowrap',
        _hover: { color: 'fg' },
    });

    const total = categories.reduce((sum, item) => sum + (item.contracts_count || 0), 0);
    const allActive = !missingActive && !activeCategoryId;

    return (
        <Box borderBottomWidth="1px" overflowX="auto">
            <HStack gap={0} colorPalette="blue" minW="max-content">
                <Link href={route('crm.contracts.index', { scope })}>
                    <Box {...tabStyle(allActive)}>
                        Все <Text as="span" color="fg.muted" fontWeight="400">({total})</Text>
                    </Box>
                </Link>
                {categories.filter((item) => item.is_active !== false || item.contracts_count > 0).map((item) => (
                    <Link key={item.id} href={route('crm.contracts.index', { category_id: item.id, scope })}>
                        <Box {...tabStyle(!missingActive && Number(activeCategoryId) === item.id)}>
                            {item.name}
                            {' '}
                            <Text as="span" color="fg.muted" fontWeight="400">({item.contracts_count})</Text>
                        </Box>
                    </Link>
                ))}
                <Link href={route('crm.contracts.missing', { scope })}>
                    <Box {...tabStyle(missingActive)} colorPalette="red">
                        <HStack gap={2}>
                            <span>Без договора</span>
                            {missingCount > 0 && <Badge colorPalette="red" size="sm">{missingCount}</Badge>}
                        </HStack>
                    </Box>
                </Link>
            </HStack>
        </Box>
    );
}
