import { useState } from 'react';
import { Badge, Box, Card, HStack, Input, Table, Text, VStack } from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import { LuCalendar, LuList, LuScale, LuTriangleAlert } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Button } from '@/components/ui/button';

const money = (value) => `${Number(value || 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})} ₽`;

/**
 * Акт сверки для клиента.
 *
 * Тот же документ, что видит менеджер, но по своим контрагентам и только за себя.
 * Клиент сам видит, из чего сложился долг: спорную строку находит по номеру
 * документа, не звоня менеджеру.
 *
 * Выбора клиента здесь нет — скоуп задаёт сессия, а не параметр адреса.
 */
export default function CabinetReconciliation({ act = null, organizations = [], form = {} }) {
    const [state, setState] = useState({
        organization_id: form.organization_id ?? '',
        date_from: form.date_from ?? '',
        date_to: form.date_to ?? '',
    });

    const set = (patch) => setState((prev) => ({ ...prev, ...patch }));

    const apply = () => router.get('/cabinet/payments/reconciliation', {
        ...(state.organization_id ? { organization_id: state.organization_id } : {}),
        date_from: state.date_from,
        date_to: state.date_to,
    }, { preserveState: false });

    return (
        <CabinetLayout>
            <Head title="Акт сверки" />

            <HStack gap="2" mb="4" wrap="wrap">
                <Button size="sm" variant="outline" asChild>
                    <Link href="/cabinet/payments">
                        <LuList size={16} /> Список
                    </Link>
                </Button>
                <Button size="sm" variant="outline" asChild>
                    <Link href="/cabinet/payments/calendar">
                        <LuCalendar size={16} /> Календарь
                    </Link>
                </Button>
                <Button size="sm" variant="solid" colorPalette="pecado">
                    <LuScale size={16} /> Акт сверки
                </Button>
            </HStack>

            <VStack align="stretch" gap={4}>
                <Box>
                    <Text fontSize="2xl" fontWeight="bold">Акт сверки</Text>
                    <Text color="fg.muted" fontSize="sm">
                        Расчёты за период: сальдо на начало, движения, сальдо на конец
                    </Text>
                </Box>

                <Card.Root>
                    <Card.Body>
                        <HStack gap={3} wrap="wrap" align="flex-end">
                            {organizations.length > 1 && (
                                <VStack align="stretch" gap={1}>
                                    <Text fontSize="xs" color="fg.muted">Продавец</Text>
                                    <select
                                        value={state.organization_id}
                                        onChange={(e) => set({ organization_id: e.target.value })}
                                        style={{ padding: '6px 8px', borderRadius: 6, borderWidth: 1 }}
                                    >
                                        <option value="">Все</option>
                                        {organizations.map((o) => (
                                            <option key={o.id} value={o.id}>{o.name}</option>
                                        ))}
                                    </select>
                                </VStack>
                            )}

                            <VStack align="stretch" gap={1}>
                                <Text fontSize="xs" color="fg.muted">С даты</Text>
                                <Input
                                    type="date"
                                    size="sm"
                                    value={state.date_from}
                                    onChange={(e) => set({ date_from: e.target.value })}
                                />
                            </VStack>

                            <VStack align="stretch" gap={1}>
                                <Text fontSize="xs" color="fg.muted">По дату</Text>
                                <Input
                                    type="date"
                                    size="sm"
                                    value={state.date_to}
                                    onChange={(e) => set({ date_to: e.target.value })}
                                />
                            </VStack>

                            <Button size="sm" onClick={apply}>Показать</Button>
                        </HStack>
                    </Card.Body>
                </Card.Root>

                {act?.discrepancy && (
                    <Card.Root borderColor="orange.400" borderWidth="1px">
                        <Card.Body>
                            <HStack gap={2} align="flex-start">
                                <Box color="orange.fg" mt={1}><LuTriangleAlert /></Box>
                                <Box>
                                    <Text fontSize="sm" fontWeight="600">Данные уточняются</Text>
                                    {/*
                                        Клиенту не сообщаем внутреннюю кухню про регистр
                                        и учётную систему: ему важно знать, что цифру
                                        рано считать окончательной, а не почему так вышло.
                                    */}
                                    <Text fontSize="sm" mt={1}>
                                        Расчёты за этот период ещё сверяются с учётной системой.
                                        Пожалуйста, уточните итог у вашего менеджера, прежде чем
                                        опираться на него.
                                    </Text>
                                </Box>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {act?.before_ledger && (
                    <Card.Root>
                        <Card.Body>
                            <Text fontSize="sm">
                                За выбранный период данных нет: расчёты в личном кабинете
                                ведутся с {act.ledger_starts_at}.
                            </Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {act && !act.before_ledger && (
                    <>
                        <Card.Root>
                            <Card.Body>
                                <HStack justify="space-between" wrap="wrap" gap={4}>
                                    <Box>
                                        <Text fontSize="sm" color="fg.muted">Сальдо на начало</Text>
                                        <Text fontWeight="600">{money(act.opening_balance)}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="sm" color="fg.muted">Начислено</Text>
                                        <Text fontWeight="600">{money(act.turnover_debit)}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="sm" color="fg.muted">Оплачено</Text>
                                        <Text fontWeight="600">{money(act.turnover_credit)}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="sm" color="fg.muted">
                                            {act.closing_balance < 0 ? 'К оплате' : 'Переплата'}
                                        </Text>
                                        {/*
                                            Знак не показываем: «−55 000» клиент читает
                                            как ошибку сайта. Показываем модуль и подпись,
                                            которая объясняет, в чью пользу.
                                        */}
                                        <Text
                                            fontWeight="700"
                                            fontSize="lg"
                                            color={act.closing_balance < 0 ? 'red.fg' : 'green.fg'}
                                        >
                                            {money(Math.abs(act.closing_balance))}
                                        </Text>
                                    </Box>
                                </HStack>
                            </Card.Body>
                        </Card.Root>

                        {act.rows.length === 0 ? (
                            <Card.Root>
                                <Card.Body>
                                    <Text color="fg.muted">За выбранный период движений не было.</Text>
                                </Card.Body>
                            </Card.Root>
                        ) : (
                            <Box borderWidth="1px" borderRadius="lg" overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                            <Table.ColumnHeader>Документ</Table.ColumnHeader>
                                            <Table.ColumnHeader>Операция</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">Начислено</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">Оплачено</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">Остаток</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {act.rows.map((row) => (
                                            <Table.Row key={row.id}>
                                                <Table.Cell whiteSpace="nowrap">{row.date_label}</Table.Cell>
                                                <Table.Cell>{row.document}</Table.Cell>
                                                <Table.Cell>
                                                    <Badge size="sm" variant="subtle">{row.type_label}</Badge>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">{row.debit ? money(row.debit) : '—'}</Table.Cell>
                                                <Table.Cell textAlign="end">{row.credit ? money(row.credit) : '—'}</Table.Cell>
                                                <Table.Cell textAlign="end" fontWeight="600">
                                                    {money(Math.abs(row.balance))}
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}

                        <Card.Root bg="bg.subtle">
                            <Card.Body>
                                <Text fontSize="sm">
                                    Остаток к оплате = сальдо на начало + начисления по реализациям
                                    − ваши оплаты − возвраты товара.
                                </Text>
                                <Text fontSize="xs" color="fg.muted" mt={1}>
                                    Оплаты появляются здесь после проведения в учёте — обычно
                                    в течение рабочего дня. Если платёж отправлен сегодня,
                                    он может ещё не отобразиться.
                                </Text>
                            </Card.Body>
                        </Card.Root>
                    </>
                )}

                <Box>
                    <Link href="/cabinet/payments">
                        <Text fontSize="sm" textDecoration="underline">← Все оплаты</Text>
                    </Link>
                </Box>
            </VStack>
        </CabinetLayout>
    );
}
