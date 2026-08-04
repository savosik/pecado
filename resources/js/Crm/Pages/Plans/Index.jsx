import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Box, Card, HStack, Input, SimpleGrid, Table, Tabs, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Pagination } from '@/Admin/Components/Pagination';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { LuCopy, LuSave } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';
import { usePermission } from '@/shared/Panel/usePermission';
import OpportunityPanel from '@/Crm/Components/OpportunityPanel';
import ProgressPanel from './components/ProgressPanel';

const fmtMoney = (value) => (value === null || value === undefined
    ? '—'
    : `${Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`);

const selectStyle = {
    padding: '0.4rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
};

/**
 * Ячейка ввода суммы плана.
 *
 * Пустое поле — «плана нет», а не «план ноль»: в отчёте о выполнении это разные
 * вещи, поэтому очистка ячейки снимает план, а не записывает нулевую цифру.
 */
function PlanCell({ value, onChange, disabled }) {
    return (
        <Input
            size="sm"
            type="number"
            min="0"
            step="1000"
            textAlign="right"
            maxW="160px"
            placeholder="—"
            value={value ?? ''}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

/**
 * Планы продаж на месяц: отдел, менеджеры, клиенты.
 *
 * Сетка сохраняется целиком одной кнопкой, а не по каждой ячейке: план расставляют
 * пачкой на месяц вперёд, и запрос на каждый ввод был бы и медленнее, и шумнее.
 */
export default function Index({
    month,
    monthLabel,
    previousMonth,
    previousMonthLabel,
    department,
    managers = [],
    managersSum = 0,
    clients,
    managerOptions = [],
    canSeeAll = false,
    canEdit = false,
    filters = {},
}) {
    const { can } = usePermission();
    const canSeeOpportunities = can('crm-opportunities.view');

    const [drafts, setDrafts] = useState({});
    const [busy, setBusy] = useState(false);
    const [copyOpen, setCopyOpen] = useState(false);
    // Выполнение открыто по умолчанию: план расставляют раз в месяц, а смотрят
    // на него каждый день.
    const [tab, setTab] = useState('progress');

    const dirtyCount = Object.keys(drafts).length;

    const setDraft = (key, value) => setDrafts((prev) => ({ ...prev, [key]: value }));

    const valueOf = (key, saved) => (key in drafts
        ? drafts[key]
        : (saved === null || saved === undefined ? '' : String(saved)));

    const navigate = (patch) => {
        router.get(route('crm.plans.index'), { ...filters, month, ...patch, page: undefined }, {
            preserveState: false,
            replace: true,
        });
    };

    const buildRows = () => Object.entries(drafts).map(([key, value]) => {
        const [type, id] = key.split(':');

        return {
            target_type: type,
            target_id: id ? Number(id) : null,
            amount: value === '' ? null : Number(value),
        };
    });

    const save = async () => {
        if (dirtyCount === 0) {
            return;
        }

        setBusy(true);
        try {
            const { data } = await axios.post(route('crm.plans.store'), { month, rows: buildRows() });

            setDrafts({});
            toastSuccess(
                'Планы сохранены',
                `Записано: ${data.saved}, снято: ${data.removed}${data.skipped ? `, пропущено: ${data.skipped}` : ''}`,
            );
            router.reload();
        } catch (e) {
            toastError('Не удалось сохранить', e?.response?.data?.message || 'Проверьте введённые суммы.');
        } finally {
            setBusy(false);
        }
    };

    const copyPrevious = async (overwrite) => {
        setBusy(true);
        try {
            const { data } = await axios.post(route('crm.plans.copy-previous'), { month, overwrite });

            setDrafts({});
            toastSuccess(
                'Скопировано из прошлого месяца',
                `Перенесено: ${data.copied}${data.skipped ? `, пропущено: ${data.skipped}` : ''}`,
            );
            router.reload();
        } catch (e) {
            toastError('Не удалось скопировать', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
            setCopyOpen(false);
        }
    };

    // Сумма планов клиентов у менеджера считается на сервере по сохранённым данным:
    // подсказка о расхождении показывает факт, а не незасохранённый черновик.
    const mismatched = useMemo(
        () => managers.filter((row) => row.amount !== null && row.clients_sum > Number(row.amount) + 0.01),
        [managers],
    );

    return (
        <>
            <Head title="CRM — Планы продаж" />
            <PageHeader
                title="Планы продаж"
                description={`Месяц: ${monthLabel}. Суммы в рублях, факт считается по отгрузкам.`}
                actions={(
                    <HStack gap={2}>
                        {canEdit && tab === 'input' && (
                            <Button size="sm" variant="outline" onClick={() => setCopyOpen(true)} disabled={busy}>
                                <LuCopy /> Скопировать {previousMonthLabel}
                            </Button>
                        )}
                        {canEdit && tab === 'input' && (
                            <Button size="sm" onClick={save} loading={busy} disabled={dirtyCount === 0}>
                                <LuSave /> Сохранить{dirtyCount > 0 ? ` (${dirtyCount})` : ''}
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Body>
                        <HStack gap={4} flexWrap="wrap" align="end">
                            <Box>
                                <Text fontSize="xs" color="fg.muted" mb="1">Месяц плана</Text>
                                <Input
                                    size="sm"
                                    type="month"
                                    maxW="180px"
                                    value={month}
                                    onChange={(e) => navigate({ month: e.target.value })}
                                />
                            </Box>
                            <Text fontSize="xs" color="fg.muted">
                                Для сравнения показан {previousMonthLabel}.
                            </Text>
                        </HStack>
                    </Card.Body>
                </Card.Root>

                <Tabs.Root value={tab} onValueChange={(e) => setTab(e.value)} lazyMount>
                    <Tabs.List>
                        <Tabs.Trigger value="progress">Выполнение</Tabs.Trigger>
                        {canSeeOpportunities && <Tabs.Trigger value="opportunities">Возможности</Tabs.Trigger>}
                        <Tabs.Trigger value="input">Ввод планов</Tabs.Trigger>
                    </Tabs.List>

                    <Tabs.Content value="progress" px={0} pt={4}>
                        <ProgressPanel month={month} canSeeAll={canSeeAll} />
                    </Tabs.Content>

                    {/* Отставание рядом с ответом на вопрос «и что с ним делать»:
                        цифра выполнения без списка звонков — отчёт, а не работа. */}
                    {canSeeOpportunities && (
                        <Tabs.Content value="opportunities" px={0} pt={4}>
                            <OpportunityPanel month={month} canSeeAll={canSeeAll} />
                        </Tabs.Content>
                    )}

                    <Tabs.Content value="input" px={0} pt={4}>
                        <VStack gap={4} align="stretch">
                            {(department.can_edit || department.amount !== null) && (
                                <Card.Root>
                                    <Card.Header>
                                        <Text fontWeight="semibold" fontSize="lg">План отдела</Text>
                                    </Card.Header>
                                    <Card.Body>
                                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                            <Box>
                                                <Text fontSize="xs" color="fg.muted" mb="1">{monthLabel}</Text>
                                                <PlanCell
                                                    value={valueOf('department', department.amount)}
                                                    disabled={!department.can_edit}
                                                    onChange={(value) => setDraft('department', value)}
                                                />
                                            </Box>
                                            <Box>
                                                <Text fontSize="xs" color="fg.muted" mb="1">{previousMonthLabel}</Text>
                                                <Text fontSize="sm">{fmtMoney(department.previous_amount)}</Text>
                                            </Box>
                                            <Box>
                                                <Text fontSize="xs" color="fg.muted" mb="1">Сумма планов менеджеров</Text>
                                                <Text fontSize="sm">{fmtMoney(managersSum)}</Text>
                                            </Box>
                                        </SimpleGrid>

                                        {department.amount !== null && managersSum > Number(department.amount) + 0.01 && (
                                            <Alert status="warning" mt={4} title="Менеджерам расписано больше плана отдела">
                                                Сумма планов менеджеров ({fmtMoney(managersSum)}) больше плана отдела
                                                ({fmtMoney(department.amount)}). Это не запрещено — но проверьте, так ли задумано.
                                            </Alert>
                                        )}
                                    </Card.Body>
                                </Card.Root>
                            )}

                            {managers.length > 0 && (
                                <Card.Root>
                                    <Card.Header>
                                        <Text fontWeight="semibold" fontSize="lg">Менеджеры</Text>
                                    </Card.Header>
                                    <Card.Body>
                                        <Table.Root size="sm">
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                                    <Table.ColumnHeader>{previousMonthLabel}</Table.ColumnHeader>
                                                    <Table.ColumnHeader>План на {monthLabel}</Table.ColumnHeader>
                                                    <Table.ColumnHeader>Расписано по клиентам</Table.ColumnHeader>
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {managers.map((row) => (
                                                    <Table.Row key={row.id}>
                                                        <Table.Cell>
                                                            <Text fontSize="sm" fontWeight="500">{row.name}</Text>
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <Text fontSize="sm" color="fg.muted">{fmtMoney(row.previous_amount)}</Text>
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <PlanCell
                                                                value={valueOf(`manager:${row.id}`, row.amount)}
                                                                disabled={!row.can_edit}
                                                                onChange={(value) => setDraft(`manager:${row.id}`, value)}
                                                            />
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <Text
                                                                fontSize="sm"
                                                                color={row.amount !== null && row.clients_sum > Number(row.amount) + 0.01
                                                                    ? 'orange.500'
                                                                    : 'fg.muted'}
                                                            >
                                                                {fmtMoney(row.clients_sum)}
                                                            </Text>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                ))}
                                            </Table.Body>
                                        </Table.Root>

                                        {mismatched.length > 0 && (
                                            <Alert status="info" mt={4} title="Сумма планов клиентов больше плана менеджера">
                                                {mismatched.map((row) => `${row.name}: расписано ${fmtMoney(row.clients_sum)} из ${fmtMoney(row.amount)}`).join('; ')}.
                                                Это подсказка, а не запрет.
                                            </Alert>
                                        )}
                                    </Card.Body>
                                </Card.Root>
                            )}

                            <Card.Root>
                                <Card.Header>
                                    <Text fontWeight="semibold" fontSize="lg">Клиенты</Text>
                                </Card.Header>
                                <Card.Body>
                                    <HStack gap={3} mb={4} flexWrap="wrap" align="center">
                                        <Input
                                            size="sm"
                                            maxW="260px"
                                            placeholder="Поиск по имени или email"
                                            defaultValue={filters.search || ''}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    navigate({ search: e.target.value || undefined });
                                                }
                                            }}
                                        />

                                        {canSeeAll && (
                                            <select
                                                style={selectStyle}
                                                value={filters.manager_id || ''}
                                                onChange={(e) => navigate({ manager_id: e.target.value || undefined })}
                                            >
                                                <option value="">Все менеджеры</option>
                                                {managerOptions.map((manager) => (
                                                    <option key={manager.id} value={manager.id}>{manager.name}</option>
                                                ))}
                                            </select>
                                        )}

                                        <Checkbox
                                            checked={!!filters.only_with_plan}
                                            onCheckedChange={(e) => navigate({ only_with_plan: e.checked ? 1 : undefined })}
                                        >
                                            Только с планом
                                        </Checkbox>
                                    </HStack>

                                    {clients.data.length === 0 ? (
                                        <Text fontSize="sm" color="fg.muted">Клиенты не найдены.</Text>
                                    ) : (
                                        <>
                                            <Table.Root size="sm">
                                                <Table.Header>
                                                    <Table.Row>
                                                        <Table.ColumnHeader>Клиент</Table.ColumnHeader>
                                                        {canSeeAll && <Table.ColumnHeader>Менеджер</Table.ColumnHeader>}
                                                        <Table.ColumnHeader>{previousMonthLabel}</Table.ColumnHeader>
                                                        <Table.ColumnHeader>План на {monthLabel}</Table.ColumnHeader>
                                                    </Table.Row>
                                                </Table.Header>
                                                <Table.Body>
                                                    {clients.data.map((row) => (
                                                        <Table.Row key={row.id}>
                                                            <Table.Cell>
                                                                <a href={route('crm.clients.show', row.id)}>
                                                                    <Text fontSize="sm" fontWeight="500">{row.name}</Text>
                                                                </a>
                                                            </Table.Cell>
                                                            {canSeeAll && (
                                                                <Table.Cell>
                                                                    <Text fontSize="sm" color="fg.muted">{row.manager || '—'}</Text>
                                                                </Table.Cell>
                                                            )}
                                                            <Table.Cell>
                                                                <Text fontSize="sm" color="fg.muted">{fmtMoney(row.previous_amount)}</Text>
                                                            </Table.Cell>
                                                            <Table.Cell>
                                                                <PlanCell
                                                                    value={valueOf(`client:${row.id}`, row.amount)}
                                                                    disabled={!row.can_edit}
                                                                    onChange={(value) => setDraft(`client:${row.id}`, value)}
                                                                />
                                                            </Table.Cell>
                                                        </Table.Row>
                                                    ))}
                                                </Table.Body>
                                            </Table.Root>

                                            <Box mt={4}>
                                                <Pagination
                                                    pagination={clients}
                                                    onPageChange={(page) => router.get(
                                                        route('crm.plans.index'),
                                                        { ...filters, month, page },
                                                        { preserveState: false, replace: true },
                                                    )}
                                                />
                                            </Box>
                                        </>
                                    )}

                                    {dirtyCount > 0 && (
                                        <Alert status="warning" mt={4} title="Есть несохранённые изменения">
                                            Изменено ячеек: {dirtyCount}. Переход на другую страницу или смена месяца их потеряет.
                                        </Alert>
                                    )}
                                </Card.Body>
                            </Card.Root>
                        </VStack>
                    </Tabs.Content>
                </Tabs.Root>
            </VStack>

            <ConfirmDialog
                open={copyOpen}
                onClose={() => setCopyOpen(false)}
                onConfirm={() => copyPrevious(false)}
                title={`Скопировать планы за ${previousMonthLabel}?`}
                description={`Планы ${previousMonthLabel} перенесутся в ${monthLabel}. Уже заданные суммы останутся как есть — копирование заполняет пустые ячейки, а не переписывает чужую работу.`}
                confirmLabel="Скопировать"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
