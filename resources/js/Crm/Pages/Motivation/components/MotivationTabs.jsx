import { Link, usePage } from '@inertiajs/react';
import { Box, HStack } from '@chakra-ui/react';
import { usePermission } from '@/shared/Panel/usePermission';
import { HUBS } from './hubs';

/**
 * Вкладки раздела: одна строка ссылок на экраны раздела с сохранением
 * работника и месяца. Заменяет одиннадцать пунктов меню у работника
 * и двенадцать у руководителя.
 */
export default function MotivationTabs({ hub, current }) {
    const { url } = usePage();
    const { can } = usePermission();
    const canEdit = can('crm-motivation.edit');
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const carry = new URLSearchParams();
    ['manager', 'month', 'quarter'].forEach((k) => { if (params.get(k)) carry.set(k, params.get(k)); });
    const suffix = carry.toString() ? `?${carry.toString()}` : '';
    const tabs = HUBS[hub].tabs.filter((t) => (t.permission !== 'edit-only' || canEdit) && (t.permission !== 'view-only' || !canEdit));

    return (
        <HStack gap={1} flexWrap="wrap" mb={4} borderBottomWidth="1px" borderColor="border" pb={2} role="tablist">
            {tabs.map((t) => {
                const active = t.key === current;
                return (
                    <Link key={t.key} href={`${t.path}${suffix}`} preserveScroll role="tab" aria-selected={active}>
                        <Box
                            px={3}
                            py={1.5}
                            borderRadius="md"
                            fontSize="sm"
                            fontWeight={active ? '700' : '500'}
                            bg={active ? 'bg.emphasized' : 'transparent'}
                            color={active ? 'fg' : 'fg.muted'}
                            _hover={{ bg: 'bg.subtle', color: 'fg' }}
                        >
                            {t.label}
                        </Box>
                    </Link>
                );
            })}
        </HStack>
    );
}
