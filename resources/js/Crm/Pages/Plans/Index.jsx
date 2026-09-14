import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, Card, HStack, Input, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { LuLock, LuSave, LuSigma } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';
import MetricHint from '@/Crm/Components/MetricHint';
import ProgressPanel from './components/ProgressPanel';

const fmtMoney = (value) => (value === null || value === undefined
    ? '—'
    : `${Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`);

const fmtDay = (iso) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : '');

/**
 * Планы продаж: план отдела на месяц, планы менеджеров из приказов на квартал
 * и выполнение.
 *
 * С экрана ставится только план отдела. План менеджера пишет приказ на квартал
 * («Мотивация → Планы на квартал»), планов на партнёра больше нет — они были
 * инструментом методики «сверху вниз» и из расчёта оплаты исключены.
 */
export default function Index({
    month,
    monthLabel,
    previousMonthLabel,
    quarter,
    department,
    managers = [],
    managersSum = 0,
    canSeeAll = false,
    canSeeMotivation = false,
}) {
    const [draft, setDraft] = useState(null);
    const [busy, setBusy] = useState(false);

    const value = draft === null ? (department.amount ?? '') : draft;
    const dirty = draft !== null && String(draft) !== String(department.amount ?? '');

    const navigate = (patch) => {
        router.get(route('crm.plans.index'), { month, ...patch }, { preserveState: false, replace: true });
    };

    const save = async (amount) => {
        setBusy(true);
        try {
            const { data } = await axios.post(route('crm.plans.store'), {
                month,
                rows: [{ target_type: 'department', amount: amount === '' ? null : Number(amount) }],
            });
            toastSuccess(data.removed ? 'План отдела снят' : 'План отдела сохранён');
            setDraft(null);
            router.reload();
        } catch (e) {
            toastError('Не удалось сохранить', e?.response?.data?.message || 'Проверьте сумму.');
        } finally {
            setBusy(false);
        }
    };

    const mismatch = department.amount !== null && managersSum > 0 && Math.abs(managersSum / Number(department.amount) - 1) > 0.15;

    return (
        <>
            <Head title="CRM — Планы продаж" />
            <PageHeader
                title="Планы продаж"
                description={`Месяц: ${monthLabel}. План отдела ставится здесь, планы менеджеров — приказом на квартал; факт считается по отгрузкам.`}
                actions={(
                    <Input
                        size="sm"
                        type="month"
                        maxW="180px"
                        aria-label="Месяц"
                        value={month}
                        onChange={(e) => navigate({ month: e.target.value })}
                    />
                )}
            />

            <VStack gap={4} align="stretch">
                {(department.can_edit || department.amount !== null) && (
                    <Card.Root>
                        <Card.Header>
                            <HStack gap={1}>
                                <Text fontWeight="semibold" fontSize="lg">План отдела</Text>
                                <MetricHint text="Цель отдела на месяц. Личные планы менеджеров из неё не выводятся: они считаются по формуле Положения и утверждаются приказом на квартал. Если план отдела расходится с суммой личных больше чем на 15 %, стоит привести его в соответствие." />
                            </HStack>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4} alignItems="end">
                                <Box>
                                    <Text fontSize="xs" color="fg.muted" mb="1">{monthLabel}</Text>
                                    {department.can_edit ? (
                                        <HStack gap={2}>
                                            <Input
                                                size="sm"
                                                type="number"
                                                min="0"
                                                step="10000"
                                                textAlign="right"
                                                maxW="180px"
                                                placeholder="—"
                                                aria-label="План отдела"
                                                value={value}
                                                onChange={(e) => setDraft(e.target.value)}
                                            />
                                            <Button size="sm" onClick={() => save(value)} loading={busy} disabled={!dirty}>
                                                <LuSave /> Сохранить
                                            </Button>
                                        </HStack>
                                    ) : (
                                        <Text fontSize="lg" fontWeight="700">{fmtMoney(department.amount)}</Text>
                                    )}
                                </Box>
                                <Box>
                                    <Text fontSize="xs" color="fg.muted" mb="1">{previousMonthLabel}</Text>
                                    <Text fontSize="sm">{fmtMoney(department.previous_amount)}</Text>
                                </Box>
                                <Box>
                                    <Text fontSize="xs" color="fg.muted" mb="1">Сумма планов менеджеров</Text>
                                    <HStack gap={2}>
                                        <Text fontSize="sm">{fmtMoney(managersSum)}</Text>
                                        {department.can_edit && managersSum > 0 && Number(department.amount ?? 0) !== managersSum && (
                                            <Button size="xs" variant="outline" loading={busy} onClick={() => save(managersSum)}>
                                                <LuSigma /> Поставить сумму
                                            </Button>
                                        )}
                                    </HStack>
                                </Box>
                            </SimpleGrid>

                            {mismatch && (
                                <Alert status="warning" mt={4} title="План отдела расходится с суммой личных планов">
                                    Отдел: {fmtMoney(department.amount)}, менеджеры вместе: {fmtMoney(managersSum)}.
                                    Личные планы поставлены по формуле Положения; план отдела стоит привести к их сумме.
                                </Alert>
                            )}
                        </Card.Body>
                    </Card.Root>
                )}

                {managers.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <HStack justify="space-between" flexWrap="wrap" gap={2}>
                                <HStack gap={1}>
                                    <Text fontWeight="semibold" fontSize="lg">Планы менеджеров</Text>
                                    <MetricHint text="Читаются из «Планов на квартал»: медиана продаж за рабочий день × рабочие дни × сезон × прирост, утверждается приказом до начала квартала. Здесь не правятся." />
                                </HStack>
                                {canSeeMotivation && (
                                    <Button size="sm" variant="outline" asChild>
                                        <Link href={`/crm/motivation/plans?quarter=${quarter}`}>Планы на квартал</Link>
                                    </Button>
                                )}
                            </HStack>
                        </Card.Header>
                        <Card.Body>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">{previousMonthLabel}</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">План на {monthLabel}</Table.ColumnHeader>
                                        <Table.ColumnHeader>Основание</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {managers.map((row) => (
                                        <Table.Row key={row.id}>
                                            <Table.Cell><Text fontSize="sm" fontWeight="500">{row.name}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" color="fg.muted">{fmtMoney(row.previous_amount)}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums">{fmtMoney(row.amount)}</Text></Table.Cell>
                                            <Table.Cell>
                                                {row.order
                                                    ? <Badge colorPalette="green" variant="subtle"><LuLock size={11} /> приказ на квартал, версия {row.order.version} · {fmtDay(row.order.approved_at)}</Badge>
                                                    : (row.amount !== null
                                                        ? <Badge colorPalette="gray" variant="subtle">поставлен по прежней методике</Badge>
                                                        : <Text fontSize="xs" color="fg.subtle">плана нет</Text>)}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                )}

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Выполнение</Text>
                    </Card.Header>
                    <Card.Body>
                        <ProgressPanel month={month} canSeeAll={canSeeAll} />
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
