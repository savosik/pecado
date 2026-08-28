import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, NativeSelect, Text, VStack } from '@chakra-ui/react';
import { LuLock, LuLockOpen } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { Tooltip } from '@/components/ui/tooltip';
import RowActions from '@/shared/Panel/RowActions';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import MetricHint from '@/Crm/Components/MetricHint';
import DebtLevelBadge from '@/Crm/Components/DebtLevelBadge';
import DebtPauseDialog from '@/Crm/Components/DebtPauseDialog';

const rub = (value) => `${Math.round(Number(value || 0)).toLocaleString('ru-RU')} ₽`;

/**
 * Дебиторка — рабочий список, не настройки (карточка debt-05).
 *
 * Партнёры со ступенью, «почему» одной строкой, разблокировка до даты.
 * Пороги живут в конфиге; здесь их только читают. РОП видит заказы,
 * заведённые в 1С клиентам с закрытыми заказами: гейт сайта обходится
 * одной кнопкой в 1С, и обход должен быть виден.
 */
export default function DebtIndex({
    rows = [],
    totals = {},
    shadow = false,
    live_actions: liveActions = [],
    filters = {},
    levels = [],
    managers = [],
    seesAll = false,
    pauseMaxDays = 14,
    thresholds = {},
}) {
    const [pauseFor, setPauseFor] = useState(null);
    const [releaseFor, setReleaseFor] = useState(null);
    const [releasing, setReleasing] = useState(false);

    const patchQuery = (patch) => {
        const query = new URLSearchParams(window.location.search);
        Object.entries(patch).forEach(([key, value]) => {
            query.delete(key);
            if (value !== undefined && value !== null && value !== '') query.set(key, value);
        });
        router.get(`/crm/debt?${query.toString()}`, {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    const reload = () => router.reload({ only: ['rows', 'totals'] });

    const release = () => {
        if (!releaseFor) return;
        setReleasing(true);
        router.delete(route('crm.debt.pauses.release', releaseFor.id), {
            preserveScroll: true,
            onFinish: () => { setReleasing(false); setReleaseFor(null); reload(); },
        });
    };

    const byLevel = totals.by_level || {};
    const restricting = ['no_preorders', 'no_orders', 'hold'].reduce((sum, key) => sum + (byLevel[key] || 0), 0);

    const columns = [
        {
            key: 'client',
            label: 'Партнёр',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontWeight="medium" fontSize="sm">{row.client.name}</Text>
                    {row.client.manager && <Text fontSize="xs" color="fg.muted">{row.client.manager}</Text>}
                </VStack>
            ),
        },
        {
            key: 'level',
            label: 'Ступень',
            render: (_, row) => (
                <DebtLevelBadge debt={{ ...row, label: row.level_label, color: row.level_color }} pause={row.pause} />
            ),
        },
        {
            key: 'overdue_amount',
            label: 'Просрочка',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" fontWeight="semibold">{rub(row.overdue_amount)}</Text>
                    <Text fontSize="xs" color="fg.muted">из долга {rub(row.debt_amount)}</Text>
                </VStack>
            ),
        },
        {
            key: 'age_days',
            label: 'Возраст',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{row.age_days} дн.</Text>
                    <Text fontSize="xs" color="fg.muted">с {row.oldest_due_date || '—'}</Text>
                </VStack>
            ),
        },
        {
            key: 'contractors',
            label: 'Контрагенты',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    {row.contractors.slice(0, 4).map((item) => (
                        <HStack key={item.company_id} gap={2} fontSize="xs">
                            <Text lineClamp={1} maxW="220px">{item.company_name}</Text>
                            <Badge colorPalette={item.level_color} variant="subtle" size="xs">{item.level_label}</Badge>
                            <Text color="fg.muted" whiteSpace="nowrap">{rub(item.overdue_amount)} · {item.age_days} дн.</Text>
                        </HStack>
                    ))}
                    {row.contractors.length > 4 && (
                        <Text fontSize="xs" color="fg.muted">и ещё {row.contractors.length - 4}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'reason',
            label: 'Почему',
            render: (_, row) => <Text fontSize="xs" color="fg.muted" maxW="280px">{row.reason}</Text>,
        },
        ...(seesAll ? [{
            key: 'erp_orders',
            label: 'Заказы из 1С, 30 дн.',
            render: (_, row) => row.erp_orders ? (
                <Tooltip content="Заказы, заведённые менеджером в 1С в обход закрытого чекаута" openDelay={300}>
                    <Badge colorPalette="red" variant="solid" size="sm">
                        {row.erp_orders.count} на {rub(row.erp_orders.amount)}
                    </Badge>
                </Tooltip>
            ) : <Text fontSize="xs" color="fg.muted">—</Text>,
        }] : []),
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    view={{ href: route('crm.clients.show', row.client.id), label: 'Открыть карточку партнёра' }}
                    extra={row.pause ? [{
                        icon: LuLock,
                        label: `Снять разблокировку (до ${row.pause.until})`,
                        colorPalette: 'red',
                        onClick: () => setReleaseFor(row.pause),
                    }] : [{
                        icon: LuLockOpen,
                        label: 'Разблокировать до даты',
                        colorPalette: 'green',
                        onClick: () => setPauseFor({ id: row.client.id, name: row.client.name, contractors: row.contractors }),
                    }]}
                />
            ),
        },
    ];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Дебиторка' }]}>
            <Head title="Дебиторка — CRM" />
            <PageHeader
                title="Дебиторка"
                description="Партнёры со ступенью лестницы долга: кому закрыты предзаказы, кому заказы, кому отгрузки"
            />

            {shadow && (
                <Alert status="info" title="Теневой расчёт" mb={3}>
                    Ступени считаются каждую ночь, но писем, ограничений и задач пока нет. Это отчёт «что бы сделала система».
                </Alert>
            )}
            {!shadow && liveActions.length > 0 && (
                <Text fontSize="xs" color="fg.muted" mb={3}>
                    В бою: {liveActions.map((item) => ({ mail: 'письма', gate: 'ограничения', tasks: 'задачи', cabinet: 'кабинет' }[item] || item)).join(', ')}.
                </Text>
            )}

            <Box borderWidth="1px" borderRadius="lg" px={4} py={3} mb={3} bg="bg.panel">
                <Flex gap={{ base: 3, md: 6 }} wrap="wrap" align="center">
                    <Metric label="Просрочено" value={rub(totals.overdue)} tone="red" hint="Значимая просрочка партнёров со ступенью — старше льготного периода, без заказов" />
                    <Metric label="Партнёров" value={totals.partners || 0} />
                    <Metric label="С ограничениями" value={restricting} tone={restricting ? 'orange' : undefined} hint="Предзаказы, заказы контрагента или все заказы закрыты" />
                    <Metric label="Стоп-отгрузка" value={byLevel.hold || 0} tone={byLevel.hold ? 'red' : undefined} />
                    <Metric label="Разблокировано" value={totals.paused || 0} tone={totals.paused ? 'green' : undefined} hint="Действует разблокировка до даты" />
                    {seesAll && <Metric label="Заказы из 1С в обход" value={totals.erp_orders || 0} tone={totals.erp_orders ? 'red' : undefined} hint="Клиенты с закрытыми заказами, которым за 30 дней завели заказ в 1С" />}
                </Flex>
            </Box>

            <Flex gap={2} wrap="wrap" align="center" mb={3}>
                <Button size="xs" variant={!filters.level ? 'solid' : 'outline'} onClick={() => patchQuery({ level: '' })}>Все ступени</Button>
                {levels.filter((item) => item.value !== 'clean').map((item) => (
                    <Button
                        key={item.value}
                        size="xs"
                        colorPalette={item.color}
                        variant={filters.level === item.value ? 'solid' : 'outline'}
                        onClick={() => patchQuery({ level: item.value })}
                    >
                        {item.label} · {byLevel[item.value] || 0}
                    </Button>
                ))}
                {seesAll && managers.length > 0 && (
                    <NativeSelect.Root size="xs" maxW="220px" ml="auto">
                        <NativeSelect.Field
                            value={filters.manager_id ? String(filters.manager_id) : ''}
                            onChange={(e) => patchQuery({ manager_id: e.target.value })}
                        >
                            <option value="">Все менеджеры</option>
                            {managers.map((manager) => (
                                <option key={manager.id} value={String(manager.id)}>{manager.name}</option>
                            ))}
                        </NativeSelect.Field>
                        <NativeSelect.Indicator />
                    </NativeSelect.Root>
                )}
            </Flex>

            <DataTable
                data={rows}
                columns={columns}
                emptyMessage={filters.level ? 'На этой ступени никого нет' : 'Просрочки выше отсечки нет — лестница пуста'}
            />

            <Text fontSize="xs" color="fg.muted" mt={3}>
                Пороги: отсечка {Number(thresholds.min_overdue || 0).toLocaleString('ru-RU')} ₽, льготный период {thresholds.grace_bank_days} банк. дн.;
                предзаказы — с {thresholds.no_preorders_days} дн., заказы контрагента — с {thresholds.no_orders_days} дн.,
                стоп — с {thresholds.hold_days} дн. при просрочке ≥ {Math.round((thresholds.hold_share || 0) * 100)} % долга.
                Вверх ступень идёт сразу по оплате из 1С, вниз — не больше шага за ночь.
            </Text>

            <DebtPauseDialog
                open={pauseFor !== null}
                client={pauseFor}
                maxDays={pauseMaxDays}
                onClose={() => setPauseFor(null)}
                onSaved={reload}
            />

            <ConfirmDialog
                open={releaseFor !== null}
                onClose={() => setReleaseFor(null)}
                onConfirm={release}
                isLoading={releasing}
                title="Снять разблокировку?"
                description="Ограничения по текущей ступени начнут действовать сразу."
                confirmLabel="Снять"
                colorPalette="red"
            />
        </CrmLayout>
    );
}

function Metric({ label, value, tone, hint }) {
    const color = tone ? `${tone}.fg` : 'fg';

    return (
        <Box minW="110px">
            <HStack gap={1}>
                <Text fontSize="xs" color="fg.muted">{label}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <Text fontSize="lg" fontWeight="semibold" color={color}>{value}</Text>
        </Box>
    );
}
