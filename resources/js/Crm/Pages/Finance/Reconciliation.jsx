import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, Grid, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuTriangleAlert, LuX } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import PeriodFilter from '@/Crm/Components/PeriodFilter';
import RowActions from '@/shared/Panel/RowActions';
import { formatRub } from './components/format';

/**
 * Акт сверки взаиморасчётов.
 *
 * Отвечает на вопрос «сколько клиент оплатил и когда» в том виде, в каком его
 * задаёт бухгалтерия: сальдо на начало, обороты по дебету и кредиту, сальдо
 * на конец. Формула расшифрована словами внизу страницы — менеджеру приходится
 * объяснять её клиенту, а не только читать самому.
 *
 * Отбор применяется сразу, без кнопки «Сформировать»: она требовала лишнего
 * действия и, хуже того, до неё справочник контрагентов оставался пустым —
 * выбрать юрлицо можно было только со второго захода.
 *
 * Партнёра можно не выбирать вовсе: достаточно контрагента, партнёр выводится
 * из его движений на сервере. Сверку чаще начинают именно с юрлица — оно
 * фигурирует в переписке, а под каким партнёром оно заведено, менеджер
 * в этот момент не знает.
 */
export default function FinanceReconciliation({ client = null, act = null, options = {}, form = {}, notice = null }) {
    const apply = (patch) => {
        const next = { ...form, ...patch };

        const query = Object.entries(next).reduce((acc, [key, value]) => {
            if (value !== null && value !== undefined && value !== '') acc[key] = value;

            return acc;
        }, {});

        router.get('/crm/finance/reconciliation', query, { preserveState: true, replace: true });
    };

    // Ссылкой, а не router.get: это файл, и Inertia на него не рассчитан.
    const exportUrl = `/crm/finance/reconciliation/export?${new URLSearchParams(
        Object.entries(form).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    )}`;

    // Выбор из длинного справочника — тем же контролом, что и остальные фильтры
    // раздела. Значение одно, поэтому из набора берётся последнее добавленное:
    // так повторный клик по строке меняет выбор, а не копит его.
    const pickOne = (key) => (ids) => apply({ [key]: ids.length ? ids[ids.length - 1] : undefined });

    // Смена партнёра сбрасывает зависящие от него фильтры: контрагент и наша
    // организация прежнего партнёра для нового бессмысленны, а молча оставить
    // их в запросе значит получить пустой акт без видимой причины.
    const pickClient = (ids) => apply({
        client_id: ids.length ? ids[ids.length - 1] : undefined,
        company_id: undefined,
        organization_id: undefined,
    });

    const reset = () => router.get('/crm/finance/reconciliation', {}, { preserveState: false, replace: true });

    const hasSelection = Boolean(form.client_id || form.company_id || form.organization_id);

    // Колонка стороны нужна ровно до тех пор, пока сторона не задана фильтром:
    // в акте по одному юрлицу это столбец с одним и тем же именем в каждой строке.
    const showCompany = !form.company_id;
    const showOrganization = !form.organization_id;

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Акт сверки' }]}>
            <Head title="Акт сверки — CRM" />

            <PageHeader
                title="Акт сверки"
                description="Движения взаиморасчётов за период: сальдо на начало, обороты, сальдо на конец"
            />

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={4} bg="bg.panel">
                <VStack align="stretch" gap={3}>
                    <Grid
                        gap={3}
                        templateColumns={{ base: '1fr', md: 'repeat(2, minmax(0, 1fr))', xl: 'repeat(3, minmax(0, 1fr))' }}
                    >
                        <MultiSelectFilter
                            label="Партнёр"
                            options={options.clients ?? []}
                            selectedIds={form.client_id ? [form.client_id] : []}
                            onChange={pickClient}
                            allLabel="Не выбран"
                            minW="0"
                        />

                        {/* Контрагент доступен без партнёра: выберут юрлицо —
                            партнёр подставится сам. */}
                        <MultiSelectFilter
                            label={form.client_id ? 'Контрагент' : 'Контрагент (можно без партнёра)'}
                            options={options.companies ?? []}
                            selectedIds={form.company_id ? [form.company_id] : []}
                            onChange={pickOne('company_id')}
                            allLabel="Все контрагенты"
                            minW="0"
                        />

                        <MultiSelectFilter
                            label="Наша организация"
                            options={options.organizations ?? []}
                            selectedIds={form.organization_id ? [form.organization_id] : []}
                            onChange={pickOne('organization_id')}
                            allLabel="Все организации"
                            minW="0"
                        />
                    </Grid>

                    <HStack gap={4} wrap="wrap" align="center">
                        {/* Периода «сегодня» у акта не бывает, и пустым он тоже
                            не бывает: сальдо считается на границы окна. */}
                        <PeriodFilter
                            from={form.date_from}
                            to={form.date_to}
                            presets={['thisMonth', 'prevMonth', 'year']}
                            clearable={false}
                            onChange={(patch) => apply(patch)}
                        />

                        {(options.currencies ?? ['RUB']).length > 1 && (
                            <HStack gap={2}>
                                <Text fontSize="xs" color="fg.muted">Валюта</Text>
                                {(options.currencies ?? []).map((code) => (
                                    <Button
                                        key={code}
                                        size="xs"
                                        variant={form.currency === code ? 'solid' : 'outline'}
                                        colorPalette={form.currency === code ? 'pecado' : 'gray'}
                                        onClick={() => apply({ currency: code })}
                                    >
                                        {code}
                                    </Button>
                                ))}
                            </HStack>
                        )}

                        <HStack gap={2} ml="auto">
                            {hasSelection && (
                                <Button size="sm" variant="outline" colorPalette="red" onClick={reset}>
                                    <LuX /> Сбросить
                                </Button>
                            )}

                            {client && act && (
                                <Button size="sm" variant="outline" asChild>
                                    <a href={exportUrl}><LuDownload /> Выгрузить в XLSX</a>
                                </Button>
                            )}
                        </HStack>
                    </HStack>
                </VStack>
            </Box>

            {/* Снятый или не сработавший фильтр объясняется словами: иначе
                выбор, который ничего не изменил, читается как поломка. */}
            {notice && (
                <Box borderWidth="1px" borderColor="orange.muted" bg="orange.subtle" borderRadius="lg" p={3} mb={4}>
                    <HStack gap={2} align="flex-start">
                        <Box color="orange.fg" mt="2px"><LuTriangleAlert /></Box>
                        <Text fontSize="sm">{notice}</Text>
                    </HStack>
                </Box>
            )}

            {!client && (
                <Box borderWidth="1px" borderRadius="lg" p={6} textAlign="center">
                    <Text color="fg.muted">
                        Выберите партнёра или сразу контрагента — акт соберётся сам.
                    </Text>
                </Box>
            )}

            {client && act && (
                <Act
                    client={client}
                    act={act}
                    showCompany={showCompany}
                    showOrganization={showOrganization}
                />
            )}
        </CrmLayout>
    );
}

function Act({ client, act, showCompany = true, showOrganization = true }) {
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
                                История расходится со сверенными данными 1С — акт отправлять нельзя
                            </Text>
                            <Text fontSize="sm" mt={1}>
                                На {act.discrepancy.as_of_label} по нашим движениям{' '}
                                <b>{money(act.discrepancy.ledger)}</b>, по сверенной цифре 1С{' '}
                                <b>{money(act.discrepancy.erp)}</b>, разница{' '}
                                <b>{money(act.discrepancy.delta)}</b>.
                            </Text>
                            <Text fontSize="xs" color="fg.muted" mt={1}>
                                Часть движений до этой даты не доехала из 1С. Сообщите администратору —
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
                        <Text fontSize="sm" color="fg.muted">Партнёр</Text>
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
                                {showCompany && <Table.ColumnHeader>Контрагент</Table.ColumnHeader>}
                                {showOrganization && <Table.ColumnHeader>Наша организация</Table.ColumnHeader>}
                                <Table.ColumnHeader textAlign="end">Дебет</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Кредит</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Сальдо</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Действия</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {act.rows.map((row) => (
                                <Table.Row key={row.id}>
                                    <Table.Cell whiteSpace="nowrap">{row.date_label}</Table.Cell>
                                    <Table.Cell>
                                        <Text>{row.document}</Text>
                                        {row.settlement_object_name && (
                                            <Text fontSize="xs" color="fg.muted">{row.settlement_object_name}</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="sm" variant="subtle">{row.type_label}</Badge>
                                    </Table.Cell>
                                    {showCompany && (
                                        <Table.Cell>
                                            <Text fontSize="sm" color={row.company_name ? undefined : 'fg.muted'}>
                                                {row.company_name || 'не указан'}
                                            </Text>
                                        </Table.Cell>
                                    )}
                                    {showOrganization && (
                                        <Table.Cell>
                                            <Text fontSize="sm" color={row.organization_name ? undefined : 'fg.muted'}>
                                                {row.organization_name || 'не указана'}
                                            </Text>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell textAlign="end">{row.debit ? money(row.debit) : '—'}</Table.Cell>
                                    <Table.Cell textAlign="end">{row.credit ? money(row.credit) : '—'}</Table.Cell>
                                    <Table.Cell textAlign="end" fontWeight="600">{money(row.balance)}</Table.Cell>
                                    <Table.Cell>
                                        <RowActions
                                            size="xs"
                                            view={row.document_url ? { label: 'Открыть документ', href: row.document_url } : null}
                                        />
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                        <Table.Footer>
                            <Table.Row>
                                <Table.Cell
                                    colSpan={3 + (showCompany ? 1 : 0) + (showOrganization ? 1 : 0)}
                                    fontWeight="600"
                                >
                                    Обороты за период
                                </Table.Cell>
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
