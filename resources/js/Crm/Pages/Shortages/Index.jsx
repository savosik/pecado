import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Input, Text, VStack, Wrap } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Button } from '@/components/ui/button';
import { Tooltip } from '@/components/ui/tooltip';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import PeriodFilter from '@/Crm/Components/PeriodFilter';
import { LuInfo, LuPackage, LuUser } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import CategoryChips from './components/CategoryChips';
import FulfillmentCard from './components/FulfillmentCard';
import ReasonLegend from './components/ReasonLegend';
import ReasonSelect from './components/ReasonSelect';
import ReasonsTab from './components/ReasonsTab';

/**
 * Журнал недоборов: что, у кого, по какой причине и на какую сумму отменилось.
 *
 * Замен сайт не предлагает — отмену делает и склад при сборке, и сам клиент
 * через менеджера. Экран отвечает на четыре вопроса: список отмен, разбор по
 * причинам, повторяемость по партнёрам и товарам и доля довезённого заказа.
 *
 * Причина отмены в протоколе 1С не приходит, поэтому её ставит человек — выбором
 * из справочника прямо в строке; справочник ведёт РОП на отдельной вкладке.
 */
const STATES = [
    { value: 'active', label: 'В работе' },
    { value: 'archived', label: 'Архив' },
    { value: 'all', label: 'Все' },
];

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

/**
 * Подсказка «кто отменил»: расходный ордер по заказу — единственный след,
 * по которому складскую отмену можно отличить от отказа клиента.
 */
function SourceHint({ hint }) {
    if (!hint) {
        return null;
    }

    const color = hint.kind === 'none' ? 'fg.muted' : 'orange.fg';
    const issues = (hint.issues || [])
        .map((issue) => `${issue.number} от ${issue.date ?? '—'} (${issue.status_label})`)
        .join('; ');

    return (
        <Tooltip content={issues ? `${hint.description} Ордера: ${issues}` : hint.description} openDelay={300}>
            <HStack gap={1} color={color} fontSize="xs" cursor="help">
                <LuInfo size={12} style={{ flexShrink: 0 }} />
                <Text>{hint.label}</Text>
            </HStack>
        </Tooltip>
    );
}

/**
 * Комментарий к отмене: сохраняется по Enter или уходу фокуса — отдельная
 * кнопка «Сохранить» на полсотни строк только мешала бы.
 */
function NoteCell({ row, canEdit, onSave }) {
    const [value, setValue] = useState(row.note ?? '');

    if (!canEdit) {
        return row.note ? <Text fontSize="sm">{row.note}</Text> : <Text color="fg.muted">—</Text>;
    }

    const commit = () => {
        if ((row.note ?? '') !== value) {
            onSave(row, value);
        }
    };

    return (
        <Input
            size="xs"
            variant="subtle"
            placeholder="Комментарий"
            value={value}
            maxLength={500}
            onChange={(event) => setValue(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.currentTarget.blur();
                }
            }}
        />
    );
}

/** Разбивка сводной строки по категориям причин — цветными бейджами. */
function CategoryBreakdown({ categories = [], unmarked = 0 }) {
    if (categories.length === 0 && unmarked === 0) {
        return <Text color="fg.muted">—</Text>;
    }

    return (
        <Wrap gap={1}>
            {categories.map((category) => (
                <Tooltip key={category.value} content={category.label} openDelay={300}>
                    <Badge colorPalette={category.color} variant="subtle">
                        {category.label}: {category.lines_count}
                    </Badge>
                </Tooltip>
            ))}
            {unmarked > 0 && (
                <Badge colorPalette="gray" variant="outline">без причины: {unmarked}</Badge>
            )}
        </Wrap>
    );
}

export default function Index({
    rows,
    totals,
    chips = [],
    fulfillment = null,
    partners = [],
    products = [],
    reasons = [],
    reasonUsage = [],
    categories = [],
    filters,
    managers = [],
    canSeeAll = false,
    canEdit = false,
    canSeeReasons = false,
    canManageReasons = false,
    canCreateReasons = false,
    canDeleteReasons = false,
}) {
    const apply = (patch) => {
        router.get('/crm/shortages', { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const applyPeriod = (patch) => {
        const next = {};

        // PeriodFilter говорит на языке date_from/date_to; раздел живёт на from/to.
        // Переносим только присланные ключи: иначе выбор одной даты сбрасывал бы вторую.
        if ('date_from' in patch) next.from = patch.date_from;
        if ('date_to' in patch) next.to = patch.date_to;

        apply(next);
    };

    const setReason = (row, reasonId) => {
        router.post(`/crm/shortages/${row.id}/reason`, { reason_id: reasonId, note: row.note ?? null }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const setNote = (row, note) => {
        router.post(`/crm/shortages/${row.id}/reason`, { reason_id: row.reason_id ?? null, note }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    // Категория и конкретная причина — один отбор в двух видах: чип задаёт зону,
    // список — строку справочника. Держать оба разом значило бы показывать
    // «Склад» и «Ошибка учёта в 1С» одновременно, то есть пустой журнал.
    const selectReason = (value) => {
        if (value === '') {
            apply({ reason_id: undefined, category: undefined });
        } else if (value === 'none') {
            apply({ category: 'none', reason_id: undefined });
        } else {
            apply({ reason_id: Number(value), category: undefined });
        }
    };

    const selectCategory = (value) => apply({ category: value || undefined, reason_id: undefined });

    const reset = () => {
        router.get('/crm/shortages', {
            tab: filters.tab,
            scope: filters.scope,
            state: filters.state,
            fulfillment: filters.fulfillment,
        }, { replace: true });
    };

    const reasonFilterValue = filters.category === 'none'
        ? 'none'
        : (filters.reason_id ? String(filters.reason_id) : '');

    const tabs = [
        { value: 'log', label: 'Журнал' },
        { value: 'partners', label: 'По партнёрам' },
        { value: 'products', label: 'По товарам' },
        ...(canSeeReasons ? [{ value: 'reasons', label: 'Причины' }] : []),
    ];

    const logColumns = [
        {
            key: 'cancelled_at',
            label: 'Отменено',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{value ?? '—'}</Text>
                    {row.order_date && (
                        <Text fontSize="xs" color="fg.muted">заказ от {row.order_date}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'order_number',
            label: 'Заказ',
            render: (value) => <Text fontWeight="medium">{value || '—'}</Text>,
        },
        {
            key: 'client',
            label: 'Партнёр',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{value}</Text>
                    {row.company && <Text fontSize="xs" color="fg.muted">{row.company}</Text>}
                </VStack>
            ),
        },
        { key: 'manager', label: 'Менеджер' },
        {
            key: 'product',
            label: 'Товар',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{value}</Text>
                    {row.sku && <Text fontSize="xs" color="fg.muted">арт. {row.sku}</Text>}
                </VStack>
            ),
        },
        { key: 'quantity', label: 'Кол-во' },
        {
            key: 'amount',
            label: 'Сумма, ₽',
            render: (value) => <Text>{money(value)}</Text>,
        },
        {
            key: 'reason_id',
            label: 'Причина недобора',
            render: (_value, row) => (
                <VStack align="start" gap={1}>
                    {row.archived_at && (
                        <Badge colorPalette="gray" variant="outline">архив от {row.archived_at}</Badge>
                    )}

                    <ReasonSelect
                        value={row.reason_id}
                        reasons={reasons}
                        categories={categories}
                        canEdit={canEdit}
                        onChange={(reasonId) => setReason(row, reasonId)}
                    />

                    {row.reason_category_label && (
                        <Badge colorPalette={row.reason_color} variant="subtle">{row.reason_category_label}</Badge>
                    )}

                    {row.source_user && (
                        <Text fontSize="xs" color="fg.muted">{row.source_user}, {row.source_at}</Text>
                    )}

                    {!row.reason_id && <SourceHint hint={row.hint} />}
                </VStack>
            ),
        },
        {
            key: 'note',
            label: 'Комментарий',
            render: (_value, row) => <NoteCell row={row} canEdit={canEdit} onSave={setNote} />,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_value, row) => (
                <RowActions
                    size="xs"
                    view={{ href: row.order_id ? `/crm/orders/${row.order_id}` : null, label: 'Открыть заказ' }}
                    extra={[
                        {
                            icon: LuUser,
                            label: 'Карточка партнёра',
                            href: row.client_id ? `/crm/partners/${row.client_id}` : null,
                        },
                        {
                            icon: LuPackage,
                            label: 'Карточка товара',
                            href: row.slug ? `/products/${row.slug}` : null,
                        },
                    ]}
                />
            ),
        },
    ];

    const partnerColumns = [
        { key: 'name', label: 'Партнёр' },
        { key: 'manager', label: 'Менеджер' },
        { key: 'lines_count', label: 'Отмен' },
        { key: 'orders_count', label: 'Заказов' },
        { key: 'quantity', label: 'Штук' },
        { key: 'amount', label: 'Сумма, ₽', render: (value) => money(value) },
        {
            key: 'categories',
            label: 'Причины',
            render: (value, row) => <CategoryBreakdown categories={value} unmarked={row.unmarked_count} />,
        },
        { key: 'last_cancelled_at', label: 'Последняя' },
        {
            key: 'actions',
            label: 'Действия',
            render: (_value, row) => (
                <RowActions
                    size="xs"
                    view={{ href: row.user_id ? `/crm/partners/${row.user_id}` : null, label: 'Карточка партнёра' }}
                />
            ),
        },
    ];

    const productColumns = [
        {
            key: 'name',
            label: 'Товар',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{value}</Text>
                    {row.sku && <Text fontSize="xs" color="fg.muted">арт. {row.sku}</Text>}
                </VStack>
            ),
        },
        { key: 'lines_count', label: 'Отмен' },
        { key: 'partners_count', label: 'Партнёров' },
        { key: 'quantity', label: 'Штук' },
        { key: 'amount', label: 'Сумма, ₽', render: (value) => money(value) },
        {
            key: 'categories',
            label: 'Причины',
            render: (value, row) => <CategoryBreakdown categories={value} unmarked={row.unmarked_count} />,
        },
        { key: 'last_cancelled_at', label: 'Последняя' },
        {
            key: 'actions',
            label: 'Действия',
            render: (_value, row) => (
                <RowActions
                    size="xs"
                    view={{ href: row.slug ? `/products/${row.slug}` : null, label: 'Карточка товара' }}
                />
            ),
        },
    ];

    const hasFilters = Boolean(
        filters.search || filters.category || filters.reason_id || filters.manager_id
        || filters.user_id || filters.product_id
    );

    return (
        <>
            <Head title="Недоборы — CRM" />

            <PageHeader
                title="Недоборы"
                description="Журнал отменённых строк заказов: что, у кого, почему и на какую сумму не уехало"
            />

            <VStack align="stretch" gap={4}>
                <Flex gap={3} align="center" wrap="wrap">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search ?? ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Номер заказа, товар или партнёр..."
                        />
                    </Box>

                    <Box minW="220px">
                        <NativeSelectRoot size="sm">
                            <NativeSelectField
                                value={reasonFilterValue}
                                onChange={(event) => selectReason(event.target.value)}
                                aria-label="Причина недобора"
                            >
                                <option value="">Причина — любая</option>
                                <option value="none">Не размечено</option>
                                {categories.map((category) => {
                                    const inCategory = reasons.filter((reason) => reason.category === category.value);

                                    if (inCategory.length === 0) {
                                        return null;
                                    }

                                    return (
                                        <optgroup key={category.value} label={category.label}>
                                            {inCategory.map((reason) => (
                                                <option key={reason.value} value={String(reason.value)}>
                                                    {reason.label}
                                                </option>
                                            ))}
                                        </optgroup>
                                    );
                                })}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>

                    {canSeeAll && managers.length > 0 && (
                        <Box minW="200px">
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={filters.manager_id ? String(filters.manager_id) : ''}
                                    onChange={(event) => apply({ manager_id: event.target.value || undefined })}
                                    aria-label="Менеджер"
                                >
                                    <option value="">Менеджер — любой</option>
                                    {managers.map((manager) => (
                                        <option key={manager.value} value={String(manager.value)}>{manager.label}</option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </Box>
                    )}

                    <ScopeToggle section="shortages" scope={filters.scope} available={canSeeAll} label="Только мои" />

                    {hasFilters && (
                        <Button size="sm" variant="outline" onClick={reset}>Сбросить</Button>
                    )}
                </Flex>

                <PeriodFilter
                    from={filters.from}
                    to={filters.to}
                    onChange={applyPeriod}
                    presets={['week', 'month30', 'thisMonth', 'prevMonth', 'year']}
                    clearable={false}
                />

                <FulfillmentCard
                    data={fulfillment}
                    onChangeBasis={(basis) => apply({ fulfillment: basis })}
                />

                <CategoryChips
                    chips={chips}
                    active={filters.category ?? ''}
                    onSelect={selectCategory}
                />

                <HStack gap={4} wrap="wrap" borderWidth="1px" borderRadius="lg" p={3} bg="bg.subtle">
                    <Text fontSize="sm">Отмен: <b>{totals.lines_count}</b></Text>
                    <Text fontSize="sm">Штук: <b>{totals.quantity}</b></Text>
                    <Text fontSize="sm">Сумма: <b>{money(totals.amount)} ₽</b></Text>
                    <Badge colorPalette="gray" variant="subtle">Без причины: {totals.unmarked_count}</Badge>
                </HStack>

                <ReasonLegend
                    categories={categories}
                    reasons={reasons}
                    activeCategory={filters.category ?? ''}
                    onSelectCategory={selectCategory}
                />

                <HStack gap={2} wrap="wrap" justify="space-between">
                    <HStack gap={2}>
                        {tabs.map((tab) => (
                            <Button
                                key={tab.value}
                                size="xs"
                                variant={filters.tab === tab.value ? 'solid' : 'outline'}
                                onClick={() => apply({ tab: tab.value })}
                            >
                                {tab.label}
                            </Button>
                        ))}
                    </HStack>

                    {/* Архив — отмены, выведенные из работы без разбора: разбирать
                        их задним числом никто не будет, но в сводках они остаются. */}
                    {filters.tab !== 'reasons' && (
                        <HStack gap={1}>
                            {STATES.map((state) => (
                                <Button
                                    key={state.value}
                                    size="xs"
                                    variant={filters.state === state.value ? 'subtle' : 'ghost'}
                                    onClick={() => apply({ state: state.value })}
                                >
                                    {state.label}
                                </Button>
                            ))}
                        </HStack>
                    )}
                </HStack>

                {filters.tab === 'log' && (
                    <DataTable data={rows.data || []} columns={logColumns} pagination={rows} />
                )}

                {filters.tab === 'partners' && (
                    <DataTable data={partners} columns={partnerColumns} />
                )}

                {filters.tab === 'products' && (
                    <DataTable data={products} columns={productColumns} />
                )}

                {filters.tab === 'reasons' && canSeeReasons && (
                    <ReasonsTab
                        reasons={reasonUsage}
                        categories={categories}
                        canManage={canManageReasons}
                        canCreate={canCreateReasons}
                        canDelete={canDeleteReasons}
                    />
                )}

                <Text fontSize="xs" color="fg.muted">
                    Строка попадает в журнал, когда 1С присылает её отменённой в order.updated. Причину 1С не
                    передаёт — её выбирает менеджер из справочника, а подсказка лишь показывает, был ли по
                    заказу расходный ордер. Степень удовлетворения считается по заказам периода: журнал
                    отвечает, что отменилось, а процент — какую долю заказанного мы довезли.
                </Text>
            </VStack>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
