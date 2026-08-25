import { Fragment, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight, LuListPlus } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import TaskDialog from '@/Crm/Components/TaskDialog';
import { usePermission } from '@/shared/Panel/usePermission';
import FinanceFilterBar from './components/FinanceFilterBar';
import { formatRub } from './components/format';

/** Подпись оси в заголовке первой колонки — читатель должен понимать, что за строки видит. */
/** Сколько узлов в поддереве — для счётчика внизу. */
const countNodes = (rows, depth = 0) => rows.reduce((acc, row) => {
    acc[depth] = (acc[depth] ?? 0) + 1;

    return row.children?.length ? countNodes(row.children, depth + 1).reduce(
        (inner, value, index) => {
            inner[index] = (inner[index] ?? 0) + value;

            return inner;
        },
        acc,
    ) : acc;
}, []);

/**
 * Балансы взаиморасчётов — проекция регистра 1С.
 *
 * Разрез выбирается на экране: 1С ведёт расчёты по тройке «партнёр × наша
 * организация × контрагент», и какая ось верхняя — зависит от вопроса.
 * Менеджер спрашивает «сколько должен клиент», бухгалтерия — «сколько нам
 * должны по этому нашему юрлицу», сверка — «покажите все юрлица списком».
 * Сервер считает одну и ту же сетку ячеек и складывает её в дерево по осям,
 * поэтому итог во всех разрезах одинаковый — меняется только вложенность.
 */
export default function FinanceBalances({
    balances = [],
    asOf = null,
    view = 'partner',
    views = [],
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const { can } = usePermission();
    const [taskFor, setTaskFor] = useState(null);
    const [expanded, setExpanded] = useState({});

    const toggle = (id) => setExpanded((previous) => ({ ...previous, [id]: ! previous[id] }));

    const totals = balances.reduce((acc, row) => ({
        balance: acc.balance + row.current_balance,
        overdue: acc.overdue + row.overdue_debt,
    }), { balance: 0, overdue: 0 });

    const levels = countNodes(balances);
    const axes = (views.find((item) => item.value === view)?.label ?? '').split(' → ');

    const changeView = (value) => router.get(
        '/crm/finance/balances',
        { ...(asOf ? { as_of: asOf } : {}), ...(filters.scope === 'department' ? { scope: 'department' } : {}), view: value },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Балансы' }]}>
            <Head title="Балансы — CRM" />

            <PageHeader
                title="Балансы"
                description="Сальдо взаиморасчётов и просроченная задолженность по данным 1С"
            />

            <FinanceFilterBar
                routeName="crm.finance.balances"
                filters={{ ...filters, as_of: asOf, view }}
                asOfMode
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
            />

            {/* Разрез отдельной строкой над таблицей, а не среди фильтров: он меняет
                не состав строк, а форму отчёта — это ближе к выбору представления. */}
            <HStack gap={2} wrap="wrap" mb={3} px={1}>
                <Text fontSize="xs" color="fg.muted">Разрез:</Text>
                {views.map((item) => (
                    <Button
                        key={item.value}
                        size="xs"
                        variant={view === item.value ? 'solid' : 'outline'}
                        colorPalette={view === item.value ? 'pecado' : 'gray'}
                        onClick={() => changeView(item.value)}
                    >
                        {item.label}
                    </Button>
                ))}
            </HStack>

            <Box borderWidth="1px" borderRadius="lg" p={3} mb={4} bg="bg.subtle">
                <Text fontSize="sm">
                    {asOf
                        ? <>Сальдо взаиморасчётов на <b>{asOf.split('-').reverse().join('.')}</b>: </>
                        : <>Сальдо взаиморасчётов: </>}
                    <b>{formatRub(totals.balance)}</b>.
                    Из них просрочено — <b>{formatRub(totals.overdue)}</b>.
                </Text>
                <Text fontSize="xs" color="fg.muted" mt={1}>
                    {asOf && 'Отчёт ретроспективный: движения после выбранной даты не учитываются, '
                        + 'просрочка — та, что была на неё. '}
                    Обе цифры — из регистра взаиморасчётов 1С. Сальдо складывается из всех движений,
                    просрочка — из строк графика оплаты, срок которых уже прошёл. Планы платежей
                    по заказам просрочкой не считаются: долг создаёт отгрузка, а не заказ.
                    Итог не зависит от разреза: меняется только группировка одних и тех же движений.
                </Text>
            </Box>

            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden">
                <Box overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader width="40px" />
                                <Table.ColumnHeader>{axes[0] || 'Строка'}</Table.ColumnHeader>
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
                                            Движений взаиморасчётов по вашим партнёрам ещё не приходило из 1С
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}

                            {balances.map((row) => (
                                <BalanceRows
                                    key={row.id}
                                    row={row}
                                    depth={0}
                                    expanded={expanded}
                                    onToggle={toggle}
                                    onTask={can('crm-tasks.create') ? setTaskFor : null}
                                />
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Box>

            <Flex justify="space-between" mt={3} px={1} gap={4} wrap="wrap">
                <Text fontSize="xs" color="fg.muted">
                    {axes.map((axis, index) => `${axis.toLowerCase()}: ${levels[index] ?? 0}`).join(' · ')}
                    {' · '}суммарное сальдо {formatRub(totals.balance)}. Отрицательное сальдо — долг партнёра.
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
                    entity={{ type: 'client', id: taskFor.clientId }}
                    initialTitle={`Дебиторка: ${taskFor.title} — ${formatRub(taskFor.overdue_debt)}`}
                    onSaved={() => setTaskFor(null)}
                />
            )}
        </CrmLayout>
    );
}

/**
 * Узел дерева и его потомки — одной рекурсивной строкой на любом уровне.
 *
 * Вложенность рисуется отступом, а не отдельными таблицами: у всех уровней
 * одни и те же колонки, и разъехавшиеся ширины читались бы как разные отчёты.
 */
function BalanceRows({ row, depth, expanded, onToggle, onTask }) {
    const hasChildren = (row.children?.length ?? 0) > 0;
    const isOpen = !! expanded[row.id];
    // Задача ставится на партнёра: у контрагента и нашей организации карточки
    // клиента нет, и вешать дебиторку не на кого.
    const clientId = row.axis === 'partner' ? row.entity_id : null;

    return (
        <Fragment>
            <Table.Row _hover={{ bg: 'bg.muted' }} bg={depth > 0 ? 'bg.subtle' : undefined}>
                <Table.Cell>
                    {hasChildren && (
                        <Button
                            size="xs"
                            variant="ghost"
                            onClick={() => onToggle(row.id)}
                            aria-label={isOpen ? 'Свернуть' : 'Развернуть'}
                        >
                            {isOpen ? <LuChevronDown /> : <LuChevronRight />}
                        </Button>
                    )}
                </Table.Cell>

                <Table.Cell>
                    <VStack align="start" gap={0} pl={depth * 4}>
                        {row.url ? (
                            <Box
                                as="a"
                                href={row.url}
                                fontSize="sm"
                                fontWeight={depth === 0 ? '600' : '400'}
                                _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                            >
                                {row.title}
                            </Box>
                        ) : (
                            <Text fontSize="sm" fontWeight={depth === 0 ? '600' : '400'}>{row.title}</Text>
                        )}
                        {(row.subtitle || row.manager_name) && (
                            <HStack gap={1} color="fg.muted" fontSize="10px">
                                {row.subtitle && <Text>{row.subtitle}</Text>}
                                {row.subtitle && row.manager_name && <Text>·</Text>}
                                {/* Менеджер мелким шрифтом виден в любом разрезе:
                                    «кому звонить по этому долгу» — первый вопрос
                                    к любой строке, на каком бы уровне она ни была. */}
                                {row.manager_name && <Text>{row.manager_name}</Text>}
                            </HStack>
                        )}
                    </VStack>
                </Table.Cell>

                <Table.Cell textAlign="end">
                    <Text
                        fontSize="sm"
                        fontWeight={depth === 0 ? '600' : '400'}
                        whiteSpace="nowrap"
                        color={row.current_balance < 0 ? 'red.fg' : undefined}
                    >
                        {formatRub(row.current_balance)}
                    </Text>
                </Table.Cell>

                <Table.Cell textAlign="end">
                    <Text
                        fontSize="sm"
                        fontWeight={depth === 0 ? '600' : '400'}
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
                    {onTask && clientId != null && (
                        <Button
                            size="xs"
                            variant="ghost"
                            onClick={() => onTask({ ...row, clientId })}
                        >
                            <LuListPlus /> Задача
                        </Button>
                    )}
                </Table.Cell>
            </Table.Row>

            {isOpen && row.children.map((child) => (
                <BalanceRows
                    key={child.id}
                    row={child}
                    depth={depth + 1}
                    expanded={expanded}
                    onToggle={onToggle}
                    onTask={onTask}
                />
            ))}
        </Fragment>
    );
}
