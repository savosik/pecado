import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@chakra-ui/react';
import MetricHint from '@/Crm/Components/MetricHint';
import FinanceFilterBar from './components/FinanceFilterBar';
import FinanceRowsTable from './components/FinanceRowsTable';
import ForecastChart from './components/ForecastChart';
import LastPayment from './components/LastPayment';
import { formatCompact, formatRub } from './components/format';

/**
 * План поступлений — ответ на вопрос финансового директора: «я верстаю
 * бюджет, сколько денег будет к такому-то числу».
 *
 * Раздел устроен слоями. Первый — сумма к выбранной дате с коридором
 * консервативного и оптимистичного сценария: это то, что переносят в бюджет.
 * Второй — кривая накопления, чтобы видеть, как деньги приходят внутри
 * периода. И только третий отвечает «от кого», потому что этот вопрос
 * возникает уже после того, как сумма не устроила.
 *
 * Прежняя версия раздела показывала плоский список плановых строк. Он
 * отвечал на вопрос «какие строки», которого никто не задавал: сумма графика
 * не равна деньгам, а без вероятностей и ритма отгрузок бюджет по нему было
 * не сверстать.
 */
export default function FinancePlan({
    forecast = {},
    partners = [],
    rows = null,
    showLines = false,
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const setTarget = (date) => {
        const query = new URLSearchParams(window.location.search);

        if (date) query.set('target', date);
        else query.delete('target');

        query.delete('page');
        router.get(`/crm/finance/plan?${query.toString()}`, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const toggleLines = () => {
        const query = new URLSearchParams(window.location.search);

        if (showLines) query.delete('group');
        else query.set('group', 'none');

        router.get(`/crm/finance/plan?${query.toString()}`, {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Коридор рисуется разностью: recharts не умеет интервал одной серией.
    const curve = (forecast.curve ?? []).map((point) => ({
        ...point,
        bandWidth: Math.max(0, point.high - point.low),
    }));

    const targetPoint = curve.find((point) => point.date === forecast.target);
    const confirmedShare = forecast.total > 0 ? Math.round((forecast.by_discipline / forecast.total) * 100) : 0;

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'План поступлений' }]}>
            <Head title="План поступлений — CRM" />

            <PageHeader
                title="План поступлений"
                description="Сколько денег придёт к выбранной дате и насколько этому можно верить"
            />

            <FinanceFilterBar
                routeName="crm.finance.plan"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
                hidePeriod
                passthrough={['target', 'group']}
            />

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={3} bg="bg.panel">
                <HStack gap={2} wrap="wrap" mb={3}>
                    <Text fontSize="xs" color="fg.muted">Деньги к дате</Text>

                    {TARGET_PRESETS.map((preset) => {
                        const date = preset.date();

                        return (
                            <Button
                                key={preset.key}
                                size="xs"
                                variant={forecast.target === date ? 'solid' : 'outline'}
                                colorPalette={forecast.target === date ? 'pecado' : 'gray'}
                                onClick={() => setTarget(date)}
                            >
                                {preset.label}
                            </Button>
                        );
                    })}

                    <Input
                        type="date"
                        size="sm"
                        width="160px"
                        aria-label="Прогноз на дату"
                        value={forecast.target ?? ''}
                        onChange={(event) => setTarget(event.target.value)}
                    />
                </HStack>

                <Flex gap={{ base: 4, md: 8 }} wrap="wrap" align="flex-end">
                    <VStack align="start" gap={0}>
                        <HStack gap={1}>
                            <Text fontSize="xs" color="fg.muted">
                                Ожидаем к {forecast.target_label}
                            </Text>
                            <MetricHint text="Сумма, которую реально ждём на счетах к этой дате. Модель снята с собственной истории: приход за период складывается из потока, не зависящего от графика (внеплановые платежи, погашение долгов, оплата будущих отгрузок), и доли того, что график обещает. Просроченные строки в обещанное не входят — их возврат уже сидит в первой части. Проверено прогоном по истории." />
                        </HStack>
                        <Text fontSize="3xl" fontWeight="700" lineHeight="1.1">
                            {formatRub(forecast.total)}
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            через {forecast.days_ahead} дн. · коридор {formatCompact(forecast.low)} — {formatCompact(forecast.high)}
                        </Text>
                        {forecast.overdue > 0 && (
                            <HStack gap={1}>
                                <Text fontSize="10px" color="fg.muted">
                                    сверх этого просрочено {formatCompact(forecast.overdue)}
                                </Text>
                                <MetricHint text="Просроченные строки в прогноз отдельной суммой не входят: срок по ним уже нарушен, и приписывать их к конкретному дню значило бы обещать деньги, которых может не быть. Их возврат учтён в потоке, не зависящем от графика, — он и посчитан по истории, где такие погашения происходили. Работа с самим долгом — в разделе «Просрочка»." />
                            </HStack>
                        )}
                    </VStack>

                    <VStack align="start" gap={0}>
                        <HStack gap={1}>
                            <Text fontSize="10px" color="fg.muted" textTransform="uppercase">Из графика ждём</Text>
                            <MetricHint text="Сколько из обещанного графиком реально ждём: сумма плановых строк, взвешенная на платёжную дисциплину каждого партнёра. Это ровно итог таблицы «от кого ждём» ниже — числа на экране обязаны сходиться." />
                        </HStack>
                        <Text fontSize="lg" fontWeight="600" color="green.fg">{formatRub(forecast.by_discipline)}</Text>
                        <Text fontSize="10px" color="fg.muted">
                            из {formatCompact(forecast.promised)} обещанных · график до {forecast.horizon_label ?? '—'}
                        </Text>
                    </VStack>

                    <VStack align="start" gap={0}>
                        <HStack gap={1}>
                            <Text fontSize="10px" color="fg.muted" textTransform="uppercase">Сверх графика</Text>
                            <MetricHint text="Оплата документов, которых ещё нет, погашение просроченного и внеплановые платежи. График из 1С короткий, и чем дальше дата, тем большую часть прихода даёт эта часть. Величина не выдумана: она снята с истории регрессией «приход за такой же срок ≈ постоянный поток плюс доля обещанного»." />
                        </HStack>
                        <Text fontSize="lg" fontWeight="600">{formatRub(forecast.beyond_plan)}</Text>
                        <Text fontSize="10px" color="fg.muted">
                            не зависит от графика {formatCompact(forecast.model?.base)}
                            {forecast.model?.extrapolated ? ' · экстраполяция' : ''}
                        </Text>
                    </VStack>

                    <VStack align="start" gap={0}>
                        <HStack gap={1}>
                            <Text fontSize="10px" color="fg.muted" textTransform="uppercase">Консервативно</Text>
                            <MetricHint text={`Нижняя граница: так выглядел бы приход, повтори он худший из наблюдавшихся периодов. Границы сняты с собственной истории — модель проверена на ${forecast.calibration?.observations ?? 0} периодах, и коридор построен так, чтобы накрывать факт примерно в девяти случаях из десяти. Именно это число стоит закладывать в бюджет, если кассовый разрыв недопустим.`} />
                        </HStack>
                        <Text fontSize="lg" fontWeight="600" color="orange.fg">{formatRub(forecast.low)}</Text>
                        <Text fontSize="10px" color="fg.muted">
                            проверено на {forecast.calibration?.observations ?? 0} периодах
                        </Text>
                    </VStack>
                </Flex>
            </Box>

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={3}>
                <HStack gap={2} mb={2}>
                    <Text fontWeight="600" fontSize="sm">Как деньги приходят внутри периода</Text>
                    <MetricHint text="Накопительная сумма: каждая точка — сколько всего будет собрано к этому дню. Зелёная линия — часть, подтверждённая графиком 1С; синяя — весь прогноз; полоса между ними — коридор сценариев. Пунктир отмечает конец графика: правее прогноз держится только на ритме отгрузок." />
                </HStack>

                <ForecastChart curve={curve} target={forecast.target} horizon={forecast.horizon} />

                {targetPoint && (
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Красная черта — выбранная дата. График 1С заканчивается {forecast.horizon_label ?? '—'}:
                        дальше плановых строк из учётной системы нет, и прогноз опирается на ритм отгрузок.
                    </Text>
                )}
            </Box>

            <Flex justify="space-between" align="baseline" mb={2} gap={3} wrap="wrap">
                <HStack gap={2}>
                    <Text fontWeight="600">От кого ждём</Text>
                    <MetricHint text="Второй слой ответа: кто именно должен принести эти деньги к выбранной дате. «Ожидаем» — сумма партнёра, взвешенная на его дисциплину; «обещано» — та же сумма по графику без поправок. Разрыв между ними и есть риск: у надёжного партнёра он мал, у молчащего — почти вся сумма." />
                </HStack>

                <Button size="xs" variant={showLines ? 'solid' : 'outline'} colorPalette={showLines ? 'pecado' : 'gray'} onClick={toggleLines}>
                    {showLines ? 'Скрыть строки' : 'Показать строки графика'}
                </Button>
            </Flex>

            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden" mb={showLines ? 6 : 0}>
                <Box overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">
                                    <ColumnLabel
                                        label="Ожидаем"
                                        hint="Сколько денег от этого партнёра реально придёт к выбранной дате. Это «обещано», уменьшенное на то, как партнёр платит на самом деле: тот, кто платит вовремя, отдаёт почти всё обещанное, а тот, кто молчит месяцами, — малую часть. Именно эти суммы складываются в прогноз наверху страницы."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">
                                    <ColumnLabel
                                        label="Обещано"
                                        hint="Сколько партнёр должен заплатить к этой дате по графику из 1С — сумма его плановых строк со сроком не позже выбранного дня. Это обязательство на бумаге, а не деньги: в бюджет его переносить нельзя, потому что часть партнёров платит позже срока, а часть не платит вовсе."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader width="130px">
                                    <ColumnLabel
                                        label="Вероятность"
                                        hint="Какая доля обещанного дойдёт до счёта: «ожидаем», делённое на «обещано». Складывается из двух вещей — как партнёр платит вообще (колонка «Дисциплина») и не нарушен ли срок уже сейчас: просроченное обещание стоит дешевле нового, и чем дольше оно висит, тем дешевле. Наведите на само число, чтобы увидеть расчёт по этому партнёру."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader>
                                    <ColumnLabel
                                        label="Дисциплина"
                                        hint="Как партнёр платит в последнее время — по фактам из 1С, без ручных оценок. «Платит вовремя» — деньги приходили в последний месяц и просрочки нет; «платит с задержкой» — платит, но какие-то сроки уже нарушил; «платежи затухают» — последний платёж был больше месяца назад; «не платит» — тишина дольше трёх месяцев или платежей не было вовсе."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Просрочено</Table.ColumnHeader>
                                <Table.ColumnHeader>Последний платёж</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>

                        <Table.Body>
                            {partners.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={7}>
                                        <Text py={8} textAlign="center" color="fg.muted">
                                            К этой дате поступлений по графику не ожидается
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}

                            {partners.map((partner) => (
                                <Table.Row key={partner.key} _hover={{ bg: 'bg.muted' }}>
                                    <Table.Cell>
                                        <VStack align="start" gap={0}>
                                            <Box
                                                as="a"
                                                href={partner.url}
                                                fontSize="sm"
                                                fontWeight="600"
                                                _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                            >
                                                {partner.title}
                                            </Box>
                                            {partner.subtitle && (
                                                <Text fontSize="10px" color="fg.muted">{partner.subtitle}</Text>
                                            )}
                                        </VStack>
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">
                                            {formatRub(partner.expected)}
                                        </Text>
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" color="fg.muted" whiteSpace="nowrap">
                                            {formatRub(partner.promised)}
                                        </Text>
                                    </Table.Cell>

                                    <Table.Cell>
                                        <HStack gap={2}>
                                            <Box bg="bg.muted" borderRadius="full" height="6px" flex="1" minW="40px" overflow="hidden">
                                                <Box
                                                    bg={partner.probability >= 0.7 ? 'green.solid' : partner.probability >= 0.4 ? 'orange.solid' : 'red.solid'}
                                                    height="6px"
                                                    width={`${Math.round(partner.probability * 100)}%`}
                                                />
                                            </Box>
                                            <HStack gap={1}>
                                                <Text fontSize="10px" color="fg.muted">
                                                    {Math.round(partner.probability * 100)}%
                                                </Text>
                                                <MetricHint text={probabilityBreakdown(partner)} label="Как получилось это число" />
                                            </HStack>
                                        </HStack>
                                    </Table.Cell>

                                    <Table.Cell>
                                        <Badge size="xs" colorPalette={partner.discipline?.palette ?? 'gray'} variant="subtle">
                                            {partner.discipline?.label ?? '—'}
                                        </Badge>
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" color={partner.overdue > 0 ? 'red.fg' : 'fg.muted'} whiteSpace="nowrap">
                                            {partner.overdue > 0 ? formatRub(partner.overdue) : '—'}
                                        </Text>
                                    </Table.Cell>

                                    <Table.Cell>
                                        <LastPayment
                                            date={partner.discipline?.last_payment_date}
                                            days={partner.discipline?.days_since_payment}
                                        />
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Box>

            {showLines && rows && (
                <>
                    <Text fontWeight="600" mb={2}>Строки графика</Text>
                    <FinanceRowsTable rows={rows} emptyMessage="В выбранном периоде поступлений не ожидается" />
                </>
            )}
        </CrmLayout>
    );
}

/** Заголовок колонки с пояснением: без него цифры выглядят взятыми с потолка. */
const ColumnLabel = ({ label, hint }) => (
    <HStack gap={1} justify="inherit">
        <Text fontSize="xs">{label}</Text>
        <MetricHint text={hint} />
    </HStack>
);

/**
 * Расчёт вероятности для конкретного партнёра — словами и числами.
 *
 * Общее объяснение в заголовке колонки отвечает «как считается вообще»,
 * а этот текст — «почему у него именно столько», и без второго первое
 * обычно не помогает.
 */
const probabilityBreakdown = (partner) => {
    const parts = [`Партнёр ${partner.discipline?.label ?? 'без истории платежей'}.`];

    if (partner.upcoming_promised > 0) {
        parts.push(
            `Со сроком впереди — ${formatCompact(partner.upcoming_promised)}, из них ждём ${formatCompact(partner.upcoming_expected)}.`,
        );
    }

    if (partner.overdue > 0) {
        parts.push(
            `Уже просрочено ${formatCompact(partner.overdue)} — из просроченного ждём только ${formatCompact(partner.overdue_expected)}: чем дольше висит долг, тем меньше шансов, что его закроют сейчас.`,
        );
    }

    parts.push(
        `Итого ${formatCompact(partner.expected)} из ${formatCompact(partner.promised)} — это ${Math.round(partner.probability * 100)}%.`,
    );

    return parts.join(' ');
};

/** Даты, о которых спрашивают чаще всего: закрытие недели, месяца и квартала. */
const TARGET_PRESETS = [
    {
        key: 'week',
        label: 'конец недели',
        date: () => {
            const date = new Date();
            date.setDate(date.getDate() + ((7 - date.getDay()) % 7));

            return date.toISOString().slice(0, 10);
        },
    },
    {
        key: 'month',
        label: 'конец месяца',
        date: () => {
            const date = new Date();

            return new Date(date.getFullYear(), date.getMonth() + 1, 0).toISOString().slice(0, 10);
        },
    },
    {
        key: 'next_month',
        label: 'конец следующего',
        date: () => {
            const date = new Date();

            return new Date(date.getFullYear(), date.getMonth() + 2, 0).toISOString().slice(0, 10);
        },
    },
    {
        key: 'quarter',
        label: 'конец квартала',
        date: () => {
            const date = new Date();
            const quarterEnd = (Math.floor(date.getMonth() / 3) + 1) * 3;

            return new Date(date.getFullYear(), quarterEnd, 0).toISOString().slice(0, 10);
        },
    },
];
