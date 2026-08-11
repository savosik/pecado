import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, Input, Table, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuTriangleAlert } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import FinanceTabs from './components/FinanceTabs';
import { formatRub } from './components/format';

/**
 * Акт сверки взаиморасчётов.
 *
 * Отвечает на вопрос «сколько клиент оплатил и когда» в том виде, в каком его
 * задаёт бухгалтерия: сальдо на начало, обороты по дебету и кредиту, сальдо
 * на конец. Формула расшифрована словами внизу страницы — менеджеру приходится
 * объяснять её клиенту, а не только читать самому.
 *
 * Клиент выбирается явно: акт — документ по одному контрагенту, «акт по всем
 * клиентам менеджера» не имеет смысла. Пока клиент не выбран, показывается
 * только форма.
 */
export default function FinanceReconciliation({ client = null, act = null, options = {}, form = {} }) {
    const [state, setState] = useState({
        client_id: form.client_id ?? null,
        organization_id: form.organization_id ?? null,
        agreement_id: form.agreement_id ?? null,
        without_agreement: form.without_agreement ?? false,
        currency: form.currency ?? 'RUB',
        date_from: form.date_from ?? '',
        date_to: form.date_to ?? '',
    });

    const set = (patch) => setState((prev) => ({ ...prev, ...patch }));

    const query = () => Object.fromEntries(
        Object.entries(state).filter(([, value]) => value !== null && value !== '' && value !== false),
    );

    const apply = () => router.get('/crm/finance/reconciliation', query(), { preserveState: false });

    // Ссылкой, а не router.get: это файл, и Inertia на него не рассчитан.
    const exportUrl = `/crm/finance/reconciliation/export?${new URLSearchParams(query())}`;

    // Выбор из длинного справочника — тем же контролом, что и остальные фильтры
    // раздела. Значение одно, поэтому из набора берётся последнее добавленное:
    // так повторный клик по строке меняет выбор, а не копит его.
    const pickOne = (key) => (ids) => set({ [key]: ids.length ? ids[ids.length - 1] : null });

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы', href: '/crm/finance' }, { label: 'Акт сверки' }]}>
            <Head title="Акт сверки — CRM" />

            <PageHeader
                title="Акт сверки"
                description="Движения взаиморасчётов за период: сальдо на начало, обороты, сальдо на конец"
            />

            <FinanceTabs active="reconciliation" />

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={4}>
                <HStack gap={3} wrap="wrap" align="flex-end">
                    <MultiSelectFilter
                        label="Клиент"
                        options={options.clients ?? []}
                        selectedIds={state.client_id ? [state.client_id] : []}
                        onChange={pickOne('client_id')}
                        allLabel="Не выбран"
                        minW="260px"
                    />

                    <MultiSelectFilter
                        label="Наша организация"
                        options={options.organizations ?? []}
                        selectedIds={state.organization_id ? [state.organization_id] : []}
                        onChange={pickOne('organization_id')}
                        allLabel="Все"
                        disabled={!client}
                    />

                    <MultiSelectFilter
                        label="Соглашение"
                        options={options.agreements ?? []}
                        selectedIds={state.agreement_id ? [state.agreement_id] : []}
                        onChange={pickOne('agreement_id')}
                        allLabel="Все"
                        disabled={!client || state.without_agreement}
                    />

                    <VStack align="stretch" gap={1}>
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">С даты</Text>
                        <Input
                            type="date"
                            size="sm"
                            value={state.date_from}
                            onChange={(e) => set({ date_from: e.target.value })}
                        />
                    </VStack>

                    <VStack align="stretch" gap={1}>
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">По дату</Text>
                        <Input
                            type="date"
                            size="sm"
                            value={state.date_to}
                            onChange={(e) => set({ date_to: e.target.value })}
                        />
                    </VStack>

                    <Button size="sm" onClick={apply} disabled={!state.client_id}>
                        Сформировать
                    </Button>

                    <Button size="sm" variant="outline" asChild disabled={!client}>
                        <a href={exportUrl}><LuDownload /> Выгрузить в XLSX</a>
                    </Button>
                </HStack>

                <HStack gap={4} mt={3} wrap="wrap">
                    <Checkbox
                        checked={state.without_agreement}
                        onCheckedChange={(e) => set({ without_agreement: !!e.checked, agreement_id: null })}
                        disabled={!client}
                    >
                        <Text fontSize="sm">Только без соглашения</Text>
                    </Checkbox>

                    {(options.currencies ?? ['RUB']).length > 1 && (
                        <HStack gap={2}>
                            <Text fontSize="sm" color="fg.muted">Валюта:</Text>
                            {(options.currencies ?? []).map((code) => (
                                <Button
                                    key={code}
                                    size="xs"
                                    variant={state.currency === code ? 'solid' : 'outline'}
                                    onClick={() => set({ currency: code })}
                                >
                                    {code}
                                </Button>
                            ))}
                        </HStack>
                    )}
                </HStack>
            </Box>

            {!client && (
                <Box borderWidth="1px" borderRadius="lg" p={6} textAlign="center">
                    <Text color="fg.muted">Выберите клиента и период, чтобы сформировать акт сверки.</Text>
                </Box>
            )}

            {client && act && <Act client={client} act={act} />}
        </CrmLayout>
    );
}

function Act({ client, act }) {
    const isRub = act.currency === 'RUB';
    const money = (value) => (isRub ? formatRub(value) : `${Number(value || 0).toLocaleString('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} ${act.currency}`);

    return (
        <VStack align="stretch" gap={4}>
            {act.discrepancy && (
                <Box borderWidth="1px" borderRadius="lg" p={3} borderColor="red.400" bg="red.subtle">
                    <HStack gap={2} align="flex-start">
                        <Box color="red.fg" mt={1}><LuTriangleAlert /></Box>
                        <Box>
                            <Text fontSize="sm" fontWeight="600" color="red.fg">
                                Расхождение с данными 1С — акт отправлять нельзя
                            </Text>
                            <Text fontSize="sm" mt={1}>
                                По движениям: <b>{money(act.discrepancy.ledger)}</b>,
                                по балансу 1С: <b>{money(act.discrepancy.erp)}</b>,
                                разница <b>{money(act.discrepancy.delta)}</b>.
                            </Text>
                            <Text fontSize="xs" color="fg.muted" mt={1}>
                                Скорее всего, часть движений не доехала из 1С. Сообщите администратору —
                                сайт ничего не пересчитывает сам, приоритет у учётной системы.
                            </Text>
                        </Box>
                    </HStack>
                </Box>
            )}

            {act.before_ledger && (
                <Box borderWidth="1px" borderRadius="lg" p={3} bg="bg.subtle">
                    <Text fontSize="sm">
                        Период целиком раньше {act.ledger_starts_at}. Регистр взаиморасчётов ведётся
                        с этой даты — более ранней истории на сайте нет.
                    </Text>
                </Box>
            )}

            <Box borderWidth="1px" borderRadius="lg" p={4}>
                <HStack justify="space-between" wrap="wrap" gap={3}>
                    <Box>
                        <Text fontSize="sm" color="fg.muted">Клиент</Text>
                        <Link href={client.url}>
                            <Text fontWeight="600" textDecoration="underline">{client.name}</Text>
                        </Link>
                    </Box>
                    <Box>
                        <Text fontSize="sm" color="fg.muted">Период</Text>
                        <Text fontWeight="600">
                            {act.period.from} — {act.period.to}
                        </Text>
                    </Box>
                    <Box>
                        <Text fontSize="sm" color="fg.muted">Сальдо на начало</Text>
                        <Text fontWeight="600">{money(act.opening_balance)}</Text>
                    </Box>
                    <Box>
                        <Text fontSize="sm" color="fg.muted">Сальдо на конец</Text>
                        <Text fontWeight="700" color={act.closing_balance < 0 ? 'red.fg' : 'green.fg'}>
                            {money(act.closing_balance)}
                        </Text>
                    </Box>
                </HStack>
            </Box>

            {act.rows.length === 0 && !act.before_ledger && (
                <Box borderWidth="1px" borderRadius="lg" p={6} textAlign="center">
                    <Text color="fg.muted">За выбранный период движений не было.</Text>
                </Box>
            )}

            {act.rows.length > 0 && (
                <Box borderWidth="1px" borderRadius="lg" overflowX="auto">
                    <Table.Root size="sm" stickyHeader>
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                <Table.ColumnHeader>Документ</Table.ColumnHeader>
                                <Table.ColumnHeader>Операция</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Дебет</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Кредит</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Сальдо</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {act.rows.map((row) => (
                                <Table.Row key={row.id}>
                                    <Table.Cell whiteSpace="nowrap">{row.date_label}</Table.Cell>
                                    <Table.Cell>
                                        {row.document_url ? (
                                            <Link href={row.document_url}>
                                                <Text textDecoration="underline">{row.document}</Text>
                                            </Link>
                                        ) : (
                                            <Text>{row.document}</Text>
                                        )}
                                        {row.settlement_object_name && (
                                            <Text fontSize="xs" color="fg.muted">{row.settlement_object_name}</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="sm" variant="subtle">{row.type_label}</Badge>
                                    </Table.Cell>
                                    <Table.Cell textAlign="end">{row.debit ? money(row.debit) : '—'}</Table.Cell>
                                    <Table.Cell textAlign="end">{row.credit ? money(row.credit) : '—'}</Table.Cell>
                                    <Table.Cell textAlign="end" fontWeight="600">{money(row.balance)}</Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                        <Table.Footer>
                            <Table.Row>
                                <Table.Cell colSpan={3} fontWeight="600">Обороты за период</Table.Cell>
                                <Table.Cell textAlign="end" fontWeight="600">{money(act.turnover_debit)}</Table.Cell>
                                <Table.Cell textAlign="end" fontWeight="600">{money(act.turnover_credit)}</Table.Cell>
                                <Table.Cell textAlign="end" fontWeight="700">{money(act.closing_balance)}</Table.Cell>
                            </Table.Row>
                        </Table.Footer>
                    </Table.Root>
                </Box>
            )}

            {act.truncated && (
                <Box borderWidth="1px" borderRadius="lg" p={3} bg="bg.subtle">
                    <Text fontSize="sm">
                        Показаны первые {act.rows_count} движений — период слишком длинный.
                        Сузьте даты, чтобы сальдо на конец было верным.
                    </Text>
                </Box>
            )}

            <Box borderWidth="1px" borderRadius="lg" p={3} bg="bg.subtle">
                <Text fontSize="sm" fontWeight="600" mb={1}>Как считается</Text>
                <Text fontSize="sm">
                    Сальдо на начало + Оплаты клиента + Возвраты товара − Реализации − Возврат денег клиенту
                    = Сальдо на конец.
                </Text>
                <Text fontSize="xs" color="fg.muted" mt={1}>
                    Отрицательное сальдо означает, что клиент должен нам. Дебет — что мы начислили клиенту,
                    кредит — что клиент погасил. Данные приходят из 1С, сайт их не пересчитывает.
                </Text>
            </Box>
        </VStack>
    );
}
