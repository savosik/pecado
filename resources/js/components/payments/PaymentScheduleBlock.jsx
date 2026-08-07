import { Box, Flex, HStack, VStack, Text, Badge, Table, Stack } from '@chakra-ui/react';
import { LuCalendarClock, LuTriangleAlert } from 'react-icons/lu';
import { formatMoney } from './PaymentCalendarGrid';

const STATUS_COLORS = { paid: 'green', partial: 'orange', pending: 'gray' };

/**
 * График оплаты реализации — «Правила оплаты» из 1С.
 *
 * Read-only во всех интерфейсах: мастер — 1С, на сайте график не редактируется.
 * Один компонент на кабинет, CRM и админку, чтобы клиент и менеджер обсуждали
 * одну и ту же таблицу, а не две разные её версии.
 */
export default function PaymentScheduleBlock({ schedule, currencySymbol = '₽' }) {
    if (!schedule || !schedule.lines?.length) {
        return null;
    }

    return (
        <Box
            p="4"
            borderRadius="xl"
            border="1px solid"
            borderColor={schedule.is_overdue ? 'red.muted' : 'border.muted'}
            bg="bg"
        >
            <Flex justify="space-between" align="center" mb="3" gap="2" wrap="wrap">
                <HStack gap="2">
                    <LuCalendarClock size={16} />
                    <Text fontWeight="semibold">График оплаты</Text>
                </HStack>

                {schedule.next_due_date_label && (
                    <Badge colorPalette={schedule.is_overdue ? 'red' : 'blue'}>
                        {schedule.is_overdue ? 'Просрочено с ' : 'Ближайший платёж '}
                        {schedule.next_due_date_label}
                    </Badge>
                )}
            </Flex>

            <Stack gap="3">
                {schedule.mismatches_document && (
                    <HStack gap="2" p="2" borderRadius="md" bg="orange.subtle">
                        <LuTriangleAlert size={14} />
                        <Text fontSize="xs">
                            Сумма графика ({formatMoney(schedule.total_amount)} {currencySymbol}) не совпадает
                            с суммой документа ({formatMoney(schedule.document_total)} {currencySymbol}).
                            График ведётся в учётной системе.
                        </Text>
                    </HStack>
                )}

                <Box overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Дата платежа</Table.ColumnHeader>
                                <Table.ColumnHeader>Условие</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Сумма</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Оплачено</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Остаток</Table.ColumnHeader>
                                <Table.ColumnHeader>Статус</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {schedule.lines.map((line) => (
                                <Table.Row key={line.id}>
                                    <Table.Cell>
                                        <Text fontWeight={line.is_overdue ? 'semibold' : 'normal'} color={line.is_overdue ? 'red.fg' : 'fg'}>
                                            {line.due_date_label}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <VStack align="flex-start" gap="0">
                                            <Text fontSize="xs">{line.stage_name || '—'}</Text>
                                            {(line.term_days !== null || line.basis_name) && (
                                                <Text fontSize="2xs" color="fg.muted">
                                                    {line.term_days !== null && `${line.term_days} дн.`}
                                                    {line.term_days !== null && line.basis_name && ' '}
                                                    {line.basis_name}
                                                </Text>
                                            )}
                                        </VStack>
                                    </Table.Cell>
                                    <Table.Cell textAlign="end">
                                        {formatMoney(line.amount)} {currencySymbol}
                                        {line.percent !== null && (
                                            <Text as="span" fontSize="2xs" color="fg.muted"> ({line.percent}%)</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell textAlign="end">{formatMoney(line.paid_amount)} {currencySymbol}</Table.Cell>
                                    <Table.Cell textAlign="end">{formatMoney(line.unpaid_amount)} {currencySymbol}</Table.Cell>
                                    <Table.Cell>
                                        <Badge size="sm" colorPalette={line.is_overdue ? 'red' : (STATUS_COLORS[line.status] || 'gray')}>
                                            {line.status_label}
                                        </Badge>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>

                <Flex justify="flex-end" gap="6" wrap="wrap">
                    <Text fontSize="sm" color="fg.muted">
                        По графику: <Text as="span" fontWeight="semibold" color="fg">{formatMoney(schedule.total_amount)} {currencySymbol}</Text>
                    </Text>
                    <Text fontSize="sm" color="fg.muted">
                        Оплачено: <Text as="span" fontWeight="semibold" color="fg">{formatMoney(schedule.paid_amount)} {currencySymbol}</Text>
                    </Text>
                    <Text fontSize="sm" color="fg.muted">
                        Остаток: <Text as="span" fontWeight="semibold" color="fg">{formatMoney(schedule.unpaid_amount)} {currencySymbol}</Text>
                    </Text>
                </Flex>
            </Stack>
        </Box>
    );
}
