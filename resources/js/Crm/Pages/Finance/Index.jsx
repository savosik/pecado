import { Head } from '@inertiajs/react';
import { Box, Flex, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, XAxis } from 'recharts';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import FinanceFilterBar from './components/FinanceFilterBar';
import { formatCompact, formatRub } from './components/format';

/**
 * Пульт платежей — выжимка для руководителя, который не ведёт клиентов.
 *
 * Раздел отвечает на пять вопросов и ни на один больше: сколько нам должны,
 * сколько из этого просрочено, сколько денег придёт до конца месяца, как идёт
 * месяц на фоне обычного и кто главные должники. Каждый ответ — фразой, а не
 * набором терминов: читателю не обязательно знать, что такое «график оплаты»
 * или «сальдо взаиморасчётов».
 *
 * Прежняя версия показывала пять плиток с пересекающимися смыслами, график
 * план/факт по неделям, две колонки сводок и две построчные таблицы — то есть
 * всё, что есть в остальных разделах, сразу. Пульт, набитый деталями,
 * перестаёт быть пультом: подробности живут своими пунктами меню.
 */
export default function FinanceDashboard({
    money = {},
    history = [],
    debtors = [],
    aging = {},
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const share = money.overdue_share ?? 0;
    const monthPace = money.month?.days_total
        ? (money.month.days_passed / money.month.days_total)
        : 0;
    // Ожидаемый темп месяца: сравнивать факт неполного месяца с целым обычным
    // нельзя — 26-го числа любой месяц выглядел бы провальным.
    const expectedByNow = (money.month?.typical ?? 0) * monthPace;
    const pace = expectedByNow > 0
        ? Math.round(((money.month?.received ?? 0) / expectedByNow) * 100)
        : null;

    const verdict = buildVerdict(share, pace);

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Пульт платежей' }]}>
            <Head title="Пульт платежей — CRM" />

            <PageHeader
                title="Пульт платежей"
                description="Главное о деньгах клиентов: сколько должны, сколько просрочено, сколько придёт"
            />

            <FinanceFilterBar
                routeName="crm.finance.index"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
                hidePeriod
            />

            {/* Одна фраза вместо шапки из терминов: руководителю нужен вывод,
                а не приборная панель, которую надо расшифровывать. */}
            <Box borderWidth="1px" borderRadius="lg" p={5} mb={3} bg={verdict.bg} borderColor={verdict.border}>
                <Text fontSize={{ base: 'lg', md: 'xl' }} lineHeight="1.5">
                    Клиенты должны нам <b>{formatRub(money.debt)}</b>.{' '}
                    Из них <Text as="span" color="red.fg" fontWeight="700">{formatRub(money.overdue)}</Text>{' '}
                    просрочено — это {share}% долга, {money.overdue_clients} {pluralPartners(money.overdue_clients)} платят с опозданием.
                </Text>

                <HStack gap={2} mt={2} align="baseline">
                    <Text fontSize="sm" fontWeight="600" color={verdict.color}>{verdict.title}</Text>
                    <Text fontSize="sm" color="fg.muted">{verdict.text}</Text>
                </HStack>
            </Box>

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3} mb={3}>
                <Card
                    question="Сколько ждём в ближайшие 30 дней"
                    value={formatRub(money.expected_30)}
                    note="по графику платежей из 1С, без просроченного"
                />

                <Card
                    question={`Сколько уже пришло в ${monthName(money.month?.days_total, history)}`}
                    value={formatRub(money.month?.received)}
                    note={pace !== null
                        ? `${pace}% от обычного темпа на ${money.month?.days_passed}-й день месяца`
                        : 'сравнивать пока не с чем'}
                    tone={pace !== null && pace < 80 ? 'red' : undefined}
                />

                <Card
                    question="Обычный месяц приносит"
                    value={formatRub(money.month?.typical)}
                    note="середина по последним пяти месяцам"
                />
            </SimpleGrid>

            <SimpleGrid columns={{ base: 1, xl: 2 }} gap={3}>
                <Box borderWidth="1px" borderRadius="lg" p={4}>
                    <Text fontWeight="600" mb={1}>Сколько денег приходило по месяцам</Text>
                    <Text fontSize="xs" color="fg.muted" mb={3}>
                        Все поступления от клиентов, за вычетом возвратов. Последний столбик — текущий месяц, он ещё не закончился.
                    </Text>

                    <Box height="200px">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={history} margin={{ top: 20, right: 8, left: 8, bottom: 0 }}>
                                <XAxis dataKey="label" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                                <Bar dataKey="amount" radius={[4, 4, 0, 0]} isAnimationActive={false}>
                                    <LabelList
                                        dataKey="amount"
                                        position="top"
                                        fontSize={10}
                                        formatter={(value) => formatCompact(value)}
                                    />
                                    {history.map((row) => (
                                        <Cell
                                            key={row.month}
                                            fill={row.current
                                                ? 'var(--chakra-colors-blue-muted)'
                                                : 'var(--chakra-colors-blue-solid)'}
                                        />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </Box>
                </Box>

                <Box borderWidth="1px" borderRadius="lg" p={4}>
                    <Text fontWeight="600" mb={1}>Кто задерживает больше всех</Text>
                    <Text fontSize="xs" color="fg.muted" mb={3}>
                        Пятёрка клиентов с самой крупной просрочкой. Справа — сколько дней ждём самый давний платёж.
                    </Text>

                    {debtors.length === 0 ? (
                        <Text fontSize="sm" color="green.fg">Просроченных платежей нет — все рассчитались в срок.</Text>
                    ) : (
                        <VStack align="stretch" gap={3}>
                            {debtors.map((debtor) => (
                                <Box key={debtor.id}>
                                    <Flex justify="space-between" align="baseline" gap={3} mb={1}>
                                        <Box
                                            as="a"
                                            href={debtor.url}
                                            fontSize="sm"
                                            fontWeight="600"
                                            _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                        >
                                            {debtor.name}
                                        </Box>
                                        <Text fontSize="sm" fontWeight="700" color="red.fg" whiteSpace="nowrap">
                                            {formatRub(debtor.amount)}
                                        </Text>
                                    </Flex>

                                    <Flex justify="space-between" align="center" gap={2}>
                                        <Box bg="bg.muted" borderRadius="full" height="6px" flex="1" overflow="hidden">
                                            <Box
                                                bg="red.solid"
                                                height="6px"
                                                width={`${money.overdue > 0
                                                    ? Math.max(3, Math.round((debtor.amount / money.overdue) * 100))
                                                    : 0}%`}
                                            />
                                        </Box>
                                        <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">
                                            ждём {debtor.max_days} дн. · менеджер {debtor.manager_name || 'не назначен'}
                                        </Text>
                                    </Flex>
                                </Box>
                            ))}

                            {aging.total > 0 && (
                                <Text fontSize="xs" color="fg.muted" pt={1} borderTopWidth="1px">
                                    Всего просрочено {formatRub(aging.total)} по {aging.count} {pluralLines(aging.count)}.
                                    Разбор — в разделе «Просрочка».
                                </Text>
                            )}
                        </VStack>
                    )}
                </Box>
            </SimpleGrid>
        </CrmLayout>
    );
}

/**
 * Вывод словами: пульт должен сказать, хорошо это или плохо.
 *
 * Пороги грубые намеренно — руководителю нужен сигнал «смотреть или не
 * смотреть», а не точная шкала. Просрочка до пятой части долга для оптовой
 * торговли с отсрочкой обычна, треть — уже повод спросить.
 */
function buildVerdict(share, pace) {
    if (share >= 30) {
        return {
            title: 'Требует внимания.',
            text: 'Просрочена почти треть долга — деньги задерживаются системно, а не единичными случаями.',
            color: 'red.fg',
            bg: 'red.subtle',
            border: 'red.muted',
        };
    }

    if (share >= 15 || (pace !== null && pace < 80)) {
        return {
            title: 'Есть на что посмотреть.',
            text: pace !== null && pace < 80
                ? 'Месяц идёт медленнее обычного: денег пришло меньше, чем к этому дню приходило раньше.'
                : 'Просрочка заметная, но в пределах обычного для отсрочки платежа.',
            color: 'orange.fg',
            bg: 'orange.subtle',
            border: 'orange.muted',
        };
    }

    return {
        title: 'В норме.',
        text: 'Просрочка невелика, деньги приходят в обычном темпе.',
        color: 'green.fg',
        bg: 'green.subtle',
        border: 'green.muted',
    };
}

const monthName = (_daysTotal, history) => history.find((row) => row.current)?.label ?? 'этом месяце';

const pluralPartners = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (! teen && tail === 1) return 'клиент';
    if (! teen && tail >= 2 && tail <= 4) return 'клиента';

    return 'клиентов';
};

const pluralLines = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (! teen && tail === 1) return 'счёту';

    return 'счетам';
};

/** Карточка-ответ: вопрос сверху, число крупно, пояснение под ним. */
const Card = ({ question, value, note, tone }) => (
    <Box borderWidth="1px" borderRadius="lg" p={4}>
        <Text fontSize="xs" color="fg.muted" mb={1}>{question}</Text>
        <Text fontSize="2xl" fontWeight="700" lineHeight="1.2" color={tone === 'red' ? 'red.fg' : undefined}>
            {value}
        </Text>
        {note && <Text fontSize="10px" color="fg.muted" mt={1}>{note}</Text>}
    </Box>
);
