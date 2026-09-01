import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Box, Flex, Grid, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import FilterChips from '@/Crm/Components/FilterChips';
import PeriodFilter from '@/Crm/Components/PeriodFilter';

const GRANULARITIES = [
    { value: 'day', label: 'По дням' },
    { value: 'week', label: 'По неделям' },
    { value: 'month', label: 'По месяцам' },
];

/** ISO-дата в человеческий вид для чипа и подписи. */
const humanDate = (value) => (value ? value.split('-').reverse().join('.') : '');

/** Дата без часового пояса — как её понимает <input type="date">. */
const iso = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

/**
 * Быстрые точки отсчёта для баланса. «Конец прошлого месяца» — то, что
 * спрашивают чаще всего: бухгалтерия сверяется по закрытым периодам.
 */
const AS_OF_PRESETS = [
    {
        key: 'prevMonthEnd',
        label: 'Конец прошлого месяца',
        date: () => iso(new Date(new Date().getFullYear(), new Date().getMonth(), 0)),
    },
    {
        key: 'yearStart',
        label: 'Начало года',
        date: () => iso(new Date(new Date().getFullYear(), 0, 1)),
    },
];

/**
 * Фильтры финансового раздела: период, менеджеры, организации.
 *
 * Один бар на все страницы раздела — набор фильтров у них общий, и разъехавшиеся
 * копии дали бы разные цифры на пульте и в таблице. Что показывать, страница
 * задаёт флагами (`showGranularity`, `showOverdueToggle`, `asOfMode`).
 *
 * Форма панели та же, что у журналов платежей и реализаций: режим и выгрузка
 * сверху, справочники сеткой, диапазоны снизу, активные фильтры чипами. Раздел
 * из семи страниц с разной раскладкой фильтров читается как семь разных продуктов.
 */
export default function FinanceFilterBar({
    routeName,
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
    showGranularity = false,
    showOverdueToggle = false,
    asOfMode = false,
    hidePeriod = false,
    exportRoute = 'crm.finance.export',
    extraControls = null,
    extraChips = [],
    passthrough = [],
}) {
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');
    const [asOf, setAsOf] = useState(filters.as_of || '');

    const current = (extra = {}) => ({
        // Разрез переезжает между отборами и попадает в выгрузку: иначе
        // расфокусированный экран выгружал бы только своих.
        scope: filters.scope === 'department' ? 'department' : undefined,
        // Баланс — состояние на момент, а не оборот за период: страница отдаёт
        // одну дату вместо диапазона, и слать «с/по» ей нечего.
        date_from: asOfMode || hidePeriod ? undefined : (dateFrom || undefined),
        date_to: asOfMode || hidePeriod ? undefined : (dateTo || undefined),
        as_of: asOfMode ? (asOf || undefined) : undefined,
        manager_ids: filters.manager_ids?.length ? filters.manager_ids : undefined,
        organization_ids: filters.organization_ids?.length ? filters.organization_ids : undefined,
        only_overdue: filters.only_overdue ? 1 : undefined,
        include_no_schedule: filters.include_no_schedule === false ? 0 : undefined,
        granularity: filters.granularity && filters.granularity !== 'week' ? filters.granularity : undefined,
        // Разрез отчёта переживает любой отбор: выбор фильтра не должен
        // возвращать таблицу к группировке по умолчанию.
        view: filters.view || undefined,
        // Отборы, которые панель не рисует сама (корзины старения, порог суммы,
        // разрез просрочки), но обязана переносить: иначе клик по «Менеджер»
        // молча сбрасывал бы то, что выбрано выше по странице.
        ...Object.fromEntries(passthrough.map((key) => [
            key,
            Array.isArray(filters[key])
                ? (filters[key].length ? filters[key] : undefined)
                : (filters[key] || undefined),
        ])),
        ...extra,
    });

    const apply = (extra = {}) => {
        router.get(route(routeName), current(extra), { preserveState: true, preserveScroll: true, replace: true });
    };

    const reset = () => {
        setDateFrom('');
        setDateTo('');
        setAsOf('');
        // Сброс убирает отбор, но не форму отчёта: разрез — не фильтр.
        router.get(
            route(routeName),
            { scope: filters.scope, view: filters.view || undefined, group: filters.group || undefined },
            { preserveState: true, replace: true },
        );
    };

    const setPeriod = (patch) => {
        const from = 'date_from' in patch ? (patch.date_from ?? '') : dateFrom;
        const to = 'date_to' in patch ? (patch.date_to ?? '') : dateTo;

        setDateFrom(from);
        setDateTo(to);
        apply({ date_from: from || undefined, date_to: to || undefined });
    };

    const setAsOfDate = (value) => {
        setAsOf(value);
        apply({ as_of: value || undefined });
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

        window.location.href = `${route(exportRoute)}?${query.toString()}`;
    };

    const chipsFor = (key, options, label) => (filters[key] || []).map((value) => ({
        key: `${key}:${value}`,
        label,
        value: options.find((option) => String(option.id) === String(value))?.name ?? String(value),
        onRemove: () => apply({
            [key]: (filters[key] || []).filter((item) => String(item) !== String(value)).length
                ? (filters[key] || []).filter((item) => String(item) !== String(value))
                : undefined,
        }),
    }));

    const chips = [
        ...chipsFor('manager_ids', managers, 'Менеджер'),
        ...chipsFor('organization_ids', organizations, 'Организация'),
        ...extraChips,
    ];

    if (asOfMode && asOf) {
        chips.push({
            key: 'as_of',
            label: 'На дату',
            value: humanDate(asOf),
            onRemove: () => setAsOfDate(''),
        });
    }

    if (! asOfMode && ! hidePeriod && (dateFrom || dateTo)) {
        chips.push({
            key: 'period',
            label: 'Плановая дата',
            value: [humanDate(dateFrom) || '…', humanDate(dateTo) || '…'].join(' — '),
            onRemove: () => setPeriod({ date_from: '', date_to: '' }),
        });
    }

    if (filters.only_overdue) {
        chips.push({
            key: 'only_overdue',
            label: 'Отбор',
            value: 'только просроченные',
            onRemove: () => apply({ only_overdue: undefined }),
        });
    }

    return (
        <>
            <Box borderWidth="1px" borderRadius="lg" p={4} mb={3} bg="bg.panel">
                <VStack align="stretch" gap={3}>
                    <HStack gap={3} align="center" wrap="wrap">
                        <ScopeToggle section="finance" scope={filters.scope} available={seesAll} />

                        {showGranularity && (
                            <HStack gap={1}>
                                {GRANULARITIES.map((item) => (
                                    <Button
                                        key={item.value}
                                        size="xs"
                                        variant={(filters.granularity || 'week') === item.value ? 'solid' : 'outline'}
                                        colorPalette={(filters.granularity || 'week') === item.value ? 'pecado' : 'gray'}
                                        onClick={() => apply({ granularity: item.value })}
                                    >
                                        {item.label}
                                    </Button>
                                ))}
                            </HStack>
                        )}

                        {showOverdueToggle && (
                            <Checkbox
                                checked={!! filters.only_overdue}
                                onCheckedChange={(event) => apply({ only_overdue: event.checked ? 1 : undefined })}
                            >
                                <Text fontSize="sm">Только просроченные</Text>
                            </Checkbox>
                        )}

                        <Button size="sm" variant="outline" onClick={exportXlsx} ml="auto">
                            <LuDownload /> XLSX
                        </Button>
                    </HStack>

                    {(managers.length > 0 || organizations.length > 0) && (
                        <Grid
                            gap={3}
                            templateColumns={{ base: '1fr', md: 'repeat(2, minmax(0, 1fr))', xl: 'repeat(3, minmax(0, 1fr))' }}
                        >
                            {seesAll && managers.length > 0 && (
                                <MultiSelectFilter
                                    label="Менеджер"
                                    options={managers}
                                    allLabel="Все менеджеры"
                                    selectedIds={filters.manager_ids || []}
                                    onChange={(ids) => apply({ manager_ids: ids.length ? ids : undefined })}
                                    minW="0"
                                />
                            )}

                            {organizations.length > 0 && (
                                <MultiSelectFilter
                                    label="Организация"
                                    options={organizations}
                                    allLabel="Все организации"
                                    selectedIds={filters.organization_ids || []}
                                    onChange={(ids) => apply({ organization_ids: ids.length ? ids : undefined })}
                                    minW="0"
                                />
                            )}
                        </Grid>
                    )}

                    {extraControls}

                    <Flex gap={4} wrap="wrap" align="center">
                        {hidePeriod ? null : asOfMode ? (
                            <HStack gap={2} wrap="wrap" align="center">
                                <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">Баланс на дату</Text>

                                <Button
                                    size="xs"
                                    variant={asOf ? 'outline' : 'solid'}
                                    colorPalette={asOf ? 'gray' : 'pecado'}
                                    onClick={() => setAsOfDate('')}
                                >
                                    Сейчас
                                </Button>

                                {AS_OF_PRESETS.map((preset) => (
                                    <Button
                                        key={preset.key}
                                        size="xs"
                                        variant={asOf === preset.date() ? 'solid' : 'outline'}
                                        colorPalette={asOf === preset.date() ? 'pecado' : 'gray'}
                                        onClick={() => setAsOfDate(preset.date())}
                                    >
                                        {preset.label}
                                    </Button>
                                ))}

                                <Input
                                    type="date"
                                    size="sm"
                                    width="160px"
                                    aria-label="Баланс на дату"
                                    value={asOf}
                                    onChange={(event) => setAsOfDate(event.target.value)}
                                />
                            </HStack>
                        ) : (
                            <PeriodFilter
                                from={dateFrom}
                                to={dateTo}
                                presets={['thisMonth', 'prevMonth', 'year']}
                                onChange={setPeriod}
                            />
                        )}

                        {chips.length > 0 && (
                            <Button size="xs" variant="outline" colorPalette="red" onClick={reset} ml="auto">
                                <LuX /> Сбросить всё
                            </Button>
                        )}
                    </Flex>
                </VStack>
            </Box>

            <FilterChips items={chips} onReset={reset} />
        </>
    );
}
