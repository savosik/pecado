import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, Text } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import { LuTriangleAlert } from 'react-icons/lu';

/**
 * Очередь недоборов: подборки замен по отменам строк 1С.
 *
 * Нагрузка — единицы событий в неделю, поэтому никакого поиска и сложных
 * фильтров: пресеты статусов (видимость воронки дожима) и переключатель
 * «только мои». Срочные (сумма выше порога) всегда сверху.
 */
const PRESETS = [
    { value: 'open', label: 'В работе', counter: 'pending' },
    { value: 'sent', label: 'Отправлено, не открыто', counter: 'sent_not_viewed' },
    { value: 'viewed', label: 'Открыто, не согласовано', counter: 'viewed_not_confirmed' },
    { value: 'confirmed', label: 'Согласовано', counter: null },
    { value: 'dismissed', label: 'Закрыто без замены', counter: null },
    { value: 'expired', label: 'Просрочено', counter: null },
    { value: 'all', label: 'Все', counter: null },
];

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

export default function Index({ offers, counters = {}, filters, canSeeAll }) {
    const apply = (patch) => {
        router.get('/crm/shortages', { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        {
            key: 'order_number',
            label: 'Заказ',
            render: (value, row) => (
                <Link href={`/crm/shortages/${row.id}`}>
                    <HStack gap={2}>
                        {row.urgent && (
                            <LuTriangleAlert size={14} color="var(--chakra-colors-red-500)" title="Срочный: сумма выше порога" />
                        )}
                        <Text fontWeight="medium" color="colorPalette.fg" _hover={{ textDecoration: 'underline' }}>
                            {value || '—'}
                        </Text>
                    </HStack>
                </Link>
            ),
        },
        { key: 'client', label: 'Клиент' },
        { key: 'manager', label: 'Менеджер' },
        {
            key: 'cancelled_amount',
            label: 'Потеря, ₽',
            render: (value) => <Text>{money(value)}</Text>,
        },
        {
            key: 'candidates_count',
            label: 'Кандидатов',
            render: (value) => (value > 0 ? value : <Text color="fg.muted">нет</Text>),
        },
        {
            key: 'status',
            label: 'Статус',
            render: (_value, row) => (
                <Badge colorPalette={row.status_color} variant="subtle">
                    {row.status_label}
                </Badge>
            ),
        },
        {
            key: 'sent_at',
            label: 'Отправлено',
            render: (value) => value || <Text color="fg.muted">—</Text>,
        },
        {
            key: 'expires_at',
            label: 'Действует до',
        },
    ];

    return (
        <>
            <Head title="Недоборы — CRM" />

            <PageHeader
                title="Недоборы"
                description="Отменённые 1С строки заказов и подборки замен для клиентов"
            />

            <HStack mb={4} gap={2} flexWrap="wrap" justify="space-between">
                <HStack gap={2} flexWrap="wrap">
                    {PRESETS.map((preset) => (
                        <Button
                            key={preset.value}
                            size="xs"
                            variant={filters.status === preset.value ? 'solid' : 'outline'}
                            onClick={() => apply({ status: preset.value })}
                        >
                            {preset.label}
                            {preset.counter && counters?.[preset.counter] > 0 && (
                                <Badge ml={1} colorPalette={preset.counter === 'pending' ? 'red' : 'gray'} variant="subtle">
                                    {counters[preset.counter]}
                                </Badge>
                            )}
                        </Button>
                    ))}
                </HStack>
                <HStack gap={3}>
                    <Link href="/crm/shortages/links">
                        <Button size="xs" variant="ghost">Связи замен</Button>
                    </Link>
                    <Link href="/crm/analytics/shortages">
                        <Button size="xs" variant="ghost">Аналитика</Button>
                    </Link>
                    <ScopeToggle section="shortages" scope={filters.scope} available={canSeeAll} label="Только мои" />
                </HStack>
            </HStack>

            <Box>
                <DataTable
                    data={offers.data || []}
                    columns={columns}
                    pagination={offers}
                />
            </Box>

            <Text mt={4} fontSize="xs" color="fg.muted">
                Недобор рождается на столе упаковщика: 1С отменяет строки при контроле сборки и присылает
                order.updated. Карточка недобора собирает всё решение на одном экране.
            </Text>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
