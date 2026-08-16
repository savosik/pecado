import { Head, router, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Box, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuChevronLeft, LuChevronRight, LuDownload } from 'react-icons/lu';

/**
 * Табель отдела продаж (abs-03): месячная сетка явок и отсутствий.
 *
 * Клетки производны от записей раздела «Отсутствия» и производственного
 * календаря — отдельного ввода дневных отметок нет. «Я» проставляется
 * только за прошедшие рабочие дни, будущие остаются пустыми.
 */
export default function Timesheet() {
    const { timesheet = {}, canEditAbsences = false } = usePage().props;
    const { month = '', month_label: monthLabel = '', days = [], rows = [], legend = [] } = timesheet;

    const shiftMonth = (delta) => {
        const [year, monthNumber] = month.split('-').map(Number);
        const target = new Date(year, monthNumber - 1 + delta, 1);
        const value = `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}`;
        router.get(route('crm.timesheet.index'), { month: value }, { preserveState: true });
    };

    const codeColors = {
        'Я': { color: 'green.600' },
        'В': { color: 'fg.muted' },
        'ОТ': { color: 'blue.600' },
        'ОД': { color: 'gray.600' },
        'Б': { color: 'orange.600' },
        'ПР': { color: 'red.600' },
    };

    const cellProps = (cell, row) => ({
        cursor: canEditAbsences && cell.absence_id ? 'pointer' : 'default',
        onClick: canEditAbsences && cell.absence_id
            ? () => router.get(route('crm.absences.index'))
            : undefined,
        title: cell.absence_id ? `${row.manager.name}: открыть раздел «Отсутствия»` : undefined,
    });

    return (
        <>
            <Head title="CRM — Табель отдела продаж" />
            <PageHeader
                title="Табель отдела продаж"
                description="Явки и отсутствия менеджеров по дням месяца. Сдача табеля — за 1–2 дня до конца месяца"
            />

            <VStack align="stretch" gap={4}>
                <HStack justify="space-between" flexWrap="wrap" gap={3}>
                    <HStack gap={2}>
                        <Button size="sm" variant="outline" onClick={() => shiftMonth(-1)} aria-label="Предыдущий месяц">
                            <LuChevronLeft />
                        </Button>
                        <Text fontWeight="semibold" minW="140px" textAlign="center">{monthLabel}</Text>
                        <Button size="sm" variant="outline" onClick={() => shiftMonth(1)} aria-label="Следующий месяц">
                            <LuChevronRight />
                        </Button>
                    </HStack>

                    <Button size="sm" variant="outline" asChild>
                        <a href={`${route('crm.timesheet.export')}?month=${month}`}>
                            <LuDownload /> Скачать CSV
                        </a>
                    </Button>
                </HStack>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader
                                    position="sticky"
                                    left={0}
                                    bg="bg.panel"
                                    zIndex={1}
                                    minW="180px"
                                >
                                    Менеджер
                                </Table.ColumnHeader>
                                {days.map((day) => (
                                    <Table.ColumnHeader
                                        key={day.date}
                                        textAlign="center"
                                        px={1}
                                        bg={day.is_weekend ? 'bg.muted' : undefined}
                                    >
                                        <VStack gap={0}>
                                            <Text fontSize="xs" fontWeight="600">{day.day}</Text>
                                            <Text fontSize="2xs" color="fg.muted">{day.dow_label}</Text>
                                        </VStack>
                                    </Table.ColumnHeader>
                                ))}
                                <Table.ColumnHeader textAlign="center" minW="72px">Явки</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="center" minW="72px">Отпуск</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="center" minW="72px">Отгул</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="center" minW="88px">Больничный</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="center" minW="80px">Прогулы</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((row) => (
                                <Table.Row key={row.manager.id}>
                                    <Table.Cell position="sticky" left={0} bg="bg.panel" zIndex={1} fontWeight="semibold">
                                        {row.manager.name}
                                    </Table.Cell>
                                    {row.cells.map((cell, index) => (
                                        <Table.Cell
                                            key={cell.date}
                                            textAlign="center"
                                            px={1}
                                            bg={days[index]?.is_weekend ? 'bg.muted' : undefined}
                                            {...cellProps(cell, row)}
                                        >
                                            <Text fontSize="xs" fontWeight="600" {...(codeColors[cell.code] || {})}>
                                                {cell.code}
                                            </Text>
                                        </Table.Cell>
                                    ))}
                                    <Table.Cell textAlign="center">{row.totals.work}</Table.Cell>
                                    <Table.Cell textAlign="center">{row.totals.vacation}</Table.Cell>
                                    <Table.Cell textAlign="center">{row.totals.day_off}</Table.Cell>
                                    <Table.Cell textAlign="center">{row.totals.sick_leave}</Table.Cell>
                                    <Table.Cell textAlign="center" color={row.totals.truancy > 0 ? 'red.600' : undefined}>
                                        {row.totals.truancy}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>

                <HStack gap={4} flexWrap="wrap">
                    {legend.map((item) => (
                        <HStack key={item.code} gap={1.5}>
                            <Text fontSize="xs" fontWeight="700" {...(codeColors[item.code] || {})}>{item.code}</Text>
                            <Text fontSize="xs" color="fg.muted">— {item.label}</Text>
                        </HStack>
                    ))}
                </HStack>
            </VStack>
        </>
    );
}

Timesheet.layout = (page) => <CrmLayout>{page}</CrmLayout>;
