import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Box, Flex, HStack, Input, Text } from '@chakra-ui/react';
import { LuDownload, LuRotateCcw } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';

const GRANULARITIES = [
    { value: 'day', label: 'По дням' },
    { value: 'week', label: 'По неделям' },
    { value: 'month', label: 'По месяцам' },
];

/**
 * Фильтры финансового раздела: период, менеджеры, организации.
 *
 * Один бар на все страницы раздела — набор фильтров у них общий, и разъехавшиеся
 * копии дали бы разные цифры на пульте и в таблице. Что показывать, страница
 * задаёт флагами (`showGranularity`, `showOverdueToggle`).
 */
export default function FinanceFilterBar({
    routeName,
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
    showGranularity = false,
    showOverdueToggle = false,
}) {
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');

    const current = (extra = {}) => ({
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        manager_ids: filters.manager_ids?.length ? filters.manager_ids : undefined,
        organization_ids: filters.organization_ids?.length ? filters.organization_ids : undefined,
        only_overdue: filters.only_overdue ? 1 : undefined,
        include_no_schedule: filters.include_no_schedule === false ? 0 : undefined,
        granularity: filters.granularity && filters.granularity !== 'week' ? filters.granularity : undefined,
        ...extra,
    });

    const apply = (extra = {}) => {
        router.get(route(routeName), current(extra), { preserveState: true, preserveScroll: true, replace: true });
    };

    const reset = () => {
        setDateFrom('');
        setDateTo('');
        router.get(route(routeName), {}, { preserveState: true, replace: true });
    };

    /**
     * Выгрузка уходит обычным переходом, а не router.visit: Inertia ждёт JSON,
     * а сервер отдаёт файл. Тот же приём, что в журналах документов.
     */
    const exportXlsx = () => {
        const query = new URLSearchParams();

        Object.entries(current()).forEach(([key, value]) => {
            if (value === undefined || value === null || value === '') return;
            if (Array.isArray(value)) value.forEach((item) => query.append(`${key}[]`, item));
            else query.append(key, value);
        });

        window.location.href = `${route('crm.finance.export')}?${query.toString()}`;
    };

    return (
        <Box borderWidth="1px" borderRadius="lg" p={3} bg="bg.subtle" mb={4}>
            <Flex gap={3} wrap="wrap" align="end">
                <Box>
                    <Text fontSize="xs" color="fg.muted" mb={1}>Плановая дата с</Text>
                    <Input
                        type="date"
                        size="sm"
                        value={dateFrom}
                        onChange={(e) => setDateFrom(e.target.value)}
                        onBlur={() => apply()}
                        maxW="160px"
                    />
                </Box>

                <Box>
                    <Text fontSize="xs" color="fg.muted" mb={1}>по</Text>
                    <Input
                        type="date"
                        size="sm"
                        value={dateTo}
                        onChange={(e) => setDateTo(e.target.value)}
                        onBlur={() => apply()}
                        maxW="160px"
                    />
                </Box>

                {seesAll && managers.length > 0 && (
                    <MultiSelectFilter
                        label="Менеджер"
                        options={managers}
                        selectedIds={filters.manager_ids || []}
                        onChange={(ids) => apply({ manager_ids: ids.length ? ids : undefined })}
                        minW="180px"
                    />
                )}

                {organizations.length > 0 && (
                    <MultiSelectFilter
                        label="Организация"
                        options={organizations}
                        selectedIds={filters.organization_ids || []}
                        onChange={(ids) => apply({ organization_ids: ids.length ? ids : undefined })}
                        minW="180px"
                    />
                )}

                {showGranularity && (
                    <HStack gap={1}>
                        {GRANULARITIES.map((item) => (
                            <Button
                                key={item.value}
                                size="xs"
                                variant={(filters.granularity || 'week') === item.value ? 'solid' : 'outline'}
                                onClick={() => apply({ granularity: item.value })}
                            >
                                {item.label}
                            </Button>
                        ))}
                    </HStack>
                )}

                {showOverdueToggle && (
                    <Checkbox
                        checked={!!filters.only_overdue}
                        onCheckedChange={(e) => apply({ only_overdue: e.checked ? 1 : undefined })}
                    >
                        <Text fontSize="sm">Только просроченные</Text>
                    </Checkbox>
                )}

                <HStack gap={2} ml="auto">
                    <Button size="sm" variant="outline" onClick={reset}>
                        <LuRotateCcw /> Сбросить
                    </Button>
                    <Button size="sm" onClick={exportXlsx}>
                        <LuDownload /> XLSX
                    </Button>
                </HStack>
            </Flex>
        </Box>
    );
}
