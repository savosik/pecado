import { Fragment, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight, LuListPlus } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import TaskDialog from '@/Crm/Components/TaskDialog';
import { usePermission } from '@/shared/Panel/usePermission';
import FinanceFilterBar from './components/FinanceFilterBar';
import { formatRub } from './components/format';

/** «2 контрагента», но «5 контрагентов» — иначе бейдж читается как опечатка. */
const contractorsLabel = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (!teen && tail >= 2 && tail <= 4) return `${count} контрагента`;

    return `${count} контрагентов`;
};

/**
 * Балансы взаиморасчётов — проекция регистра 1С.
 *
 * 1С ведёт расчёты по контрагентам, а у партнёра их бывает несколько. Поэтому
 * верхняя строка — партнёр с итогом, внутри неё раскрываются его контрагенты
 * с ИНН: долг остаётся «по контрагентам», но имя партнёра не повторяется подряд.
 *
 * Сводной сверки в шапке больше нет (v16.3.0). Она сравнивала «просрочку 1С»
 * с «нашим расчётом по графику» и объясняла, почему числа не обязаны совпадать.
 * После перехода на регистр оба берутся из одних и тех же плановых строк ленты,
 * то есть плашка сравнивала число с самим собой.
 */
export default function FinanceBalances({
    balances = [],
    summary = {},
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const { can } = usePermission();
    const [taskFor, setTaskFor] = useState(null);
    const [expanded, setExpanded] = useState({});

    const toggle = (id) => setExpanded((prev) => ({ ...prev, [id]: !prev[id] }));

    const totals = balances.reduce((acc, row) => ({
        balance: acc.balance + row.current_balance,
        overdue: acc.overdue + row.overdue_debt,
        contractors: acc.contractors + row.contractors.length,
    }), { balance: 0, overdue: 0, contractors: 0 });

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Балансы' }]}>
            <Head title="Балансы — CRM" />

            <PageHeader
                title="Балансы"
                description="Сальдо взаиморасчётов и просроченная задолженность по данным 1С"
            />

            <FinanceFilterBar
                routeName="crm.finance.balances"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
            />

            <Box borderWidth="1px" borderRadius="lg" p={3} mb={4} bg="bg.subtle">
                <Text fontSize="sm">
                    Сальдо взаиморасчётов: <b>{formatRub(totals.balance)}</b>.
                    Из них просрочено — <b>{formatRub(totals.overdue)}</b>.
                </Text>
                <Text fontSize="xs" color="fg.muted" mt={1}>
                    Обе цифры — из регистра взаиморасчётов 1С. Сальдо складывается из всех движений,
                    просрочка — из строк графика оплаты, срок которых уже прошёл. Планы платежей
                    по заказам просрочкой не считаются: долг создаёт отгрузка, а не заказ.
                </Text>
            </Box>

            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden">
                <Box overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader width="40px" />
                                <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Сальдо</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Просрочено</Table.ColumnHeader>
                                <Table.ColumnHeader>Данные 1С от</Table.ColumnHeader>
                                <Table.ColumnHeader />
                            </Table.Row>
                        </Table.Header>

                        <Table.Body>
                            {balances.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={6}>
                                        <Text py={8} textAlign="center" color="fg.muted">
                                            Балансы по вашим партнёрам ещё не приходили из 1С
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}

                            {balances.map((row) => {
                                const isOpen = !!expanded[row.id];
                                const many = row.contractors.length > 1;

                                return (
                                    // Fragment с ключом, а не <>: строка партнёра и
                                    // строки его контрагентов — соседи одного уровня.
                                    <Fragment key={row.id}>
                                        <Table.Row _hover={{ bg: 'bg.muted' }}>
                                            <Table.Cell>
                                                <Button
                                                    size="xs"
                                                    variant="ghost"
                                                    onClick={() => toggle(row.id)}
                                                    aria-label={isOpen ? 'Свернуть контрагентов' : 'Показать контрагентов'}
                                                >
                                                    {isOpen ? <LuChevronDown /> : <LuChevronRight />}
                                                </Button>
                                            </Table.Cell>

                                            <Table.Cell>
                                                <VStack align="start" gap={0}>
                                                    <Box
                                                        as="a"
                                                        href={row.client.url}
                                                        fontSize="sm"
                                                        fontWeight="600"
                                                        _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                                    >
                                                        {row.client.name}
                                                    </Box>
                                                    <HStack gap={2}>
                                                        <Text fontSize="10px" color="fg.muted">
                                                            {row.manager_name || 'без менеджера'}
                                                        </Text>
                                                        {many && (
                                                            <Badge size="xs" colorPalette="gray">
                                                                {contractorsLabel(row.contractors.length)}
                                                            </Badge>
                                                        )}
                                                    </HStack>
                                                </VStack>
                                            </Table.Cell>

                                            <Table.Cell textAlign="end">
                                                <Text
                                                    fontSize="sm"
                                                    fontWeight="600"
                                                    whiteSpace="nowrap"
                                                    color={row.current_balance < 0 ? 'red.fg' : undefined}
                                                >
                                                    {formatRub(row.current_balance)}
                                                </Text>
                                            </Table.Cell>

                                            <Table.Cell textAlign="end">
                                                <Text
                                                    fontSize="sm"
                                                    fontWeight="600"
                                                    whiteSpace="nowrap"
                                                    color={row.overdue_debt > 0 ? 'red.fg' : 'fg.muted'}
                                                >
                                                    {formatRub(row.overdue_debt)}
                                                </Text>
                                            </Table.Cell>

                                            <Table.Cell>
                                                <Text fontSize="xs" color="fg.muted">{row.erp_updated_at || '—'}</Text>
                                            </Table.Cell>

                                            <Table.Cell>
                                                {can('crm-tasks.create') && (
                                                    <Button size="xs" variant="ghost" onClick={() => setTaskFor(row)}>
                                                        <LuListPlus /> Задача
                                                    </Button>
                                                )}
                                            </Table.Cell>
                                        </Table.Row>

                                        {isOpen && row.contractors.map((contractor) => (
                                            <Table.Row key={`${row.id}-${contractor.id}`} bg="bg.subtle">
                                                <Table.Cell />
                                                <Table.Cell>
                                                    <VStack align="start" gap={0} pl={4}>
                                                        <Text fontSize="sm">
                                                            {contractor.company_name || 'Контрагент без карточки'}
                                                        </Text>
                                                        <Text fontSize="10px" color="fg.muted">
                                                            {contractor.tax_id ? `ИНН ${contractor.tax_id}` : 'без ИНН'}
                                                        </Text>
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">
                                                    <Text fontSize="sm" whiteSpace="nowrap">
                                                        {formatRub(contractor.current_balance)}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">
                                                    <Text fontSize="sm" whiteSpace="nowrap">
                                                        {formatRub(contractor.overdue_debt)}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text fontSize="xs" color="fg.muted">
                                                        {contractor.erp_updated_at || '—'}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell />
                                            </Table.Row>
                                        ))}
                                    </Fragment>
                                );
                            })}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Box>

            <Flex justify="space-between" mt={3} px={1}>
                <Text fontSize="xs" color="fg.muted">
                    Партнёров: {balances.length} · контрагентов: {totals.contractors} ·
                    суммарное сальдо {formatRub(totals.balance)}. Отрицательное сальдо — долг партнёра.
                </Text>
                <Text fontSize="xs" color="fg.muted">
                    Суммы приходят из 1С и подписаны как рубли: для мультивалютного
                    контрагента учётная система валюту не передаёт.
                </Text>
            </Flex>

            {taskFor && (
                <TaskDialog
                    open
                    onClose={() => setTaskFor(null)}
                    entity={{ type: 'client', id: taskFor.client.id }}
                    initialTitle={`Дебиторка: ${taskFor.client.name} — ${formatRub(taskFor.overdue_debt)}`}
                    onSaved={() => setTaskFor(null)}
                />
            )}
        </CrmLayout>
    );
}
