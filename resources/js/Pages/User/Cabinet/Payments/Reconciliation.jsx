import { useState } from 'react';
import { Badge, Box, Card, Flex, HStack, Input, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import { LuCalendar, LuList, LuScale, LuTriangleAlert } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

const money = (value) => `${Number(value || 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})} ₽`;

// Карточки — как в остальных разделах кабинета («Мои заказы»): белый фон,
// скруглённый xl, тонкая рамка. Без bg блок сливается с песочной подложкой.
const cardProps = {
    bg: 'bg',
    borderRadius: 'xl',
    border: '1px solid',
    borderColor: 'border.muted',
};

/**
 * Акт сверки для клиента.
 *
 * Тот же документ, что видит менеджер, но по своим контрагентам и только за себя.
 * Клиент сам видит, из чего сложился долг: спорную строку находит по номеру
 * документа, не звоня менеджеру.
 *
 * Выбора клиента здесь нет — скоуп задаёт сессия, а не параметр адреса.
 */
export default function CabinetReconciliation({ act = null, organizations = [], companies = [], form = {} }) {
    const [state, setState] = useState({
        organization_id: form.organization_id ?? '',
        company_id: form.company_id ?? '',
        date_from: form.date_from ?? '',
        date_to: form.date_to ?? '',
    });

    const set = (patch) => setState((prev) => ({ ...prev, ...patch }));

    const apply = () => router.get('/cabinet/payments/reconciliation', {
        ...(state.organization_id ? { organization_id: state.organization_id } : {}),
        ...(state.company_id ? { company_id: state.company_id } : {}),
        date_from: state.date_from,
        date_to: state.date_to,
    }, { preserveState: false });

    return (
        <CabinetLayout title="Акт сверки">
            <Head title="Акт сверки — Pecado" />

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
                <Text color="fg.muted" fontSize="sm">
                    Расчёты за период: сальдо на начало, движения, сальдо на конец
                </Text>

                <Card.Root {...cardProps}>
                    <Card.Body p="4">
                        <Flex gap={4} wrap="wrap" align="flex-end">
                            {/* flex-basis, а не width: Field растягивается на всю строку,
                                и без basis каждое поле уезжало на отдельную строку. */}
                            {companies.length > 1 && (
                                <Field label="Контрагент" flex="1 1 220px">
                                    {/*
                                        Нативный select, как и «Продавец» ниже: контрол
                                        формы акта, который обязан работать без сюрпризов
                                        портала/коллекции Chakra-селекта.
                                    */}
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={state.company_id}
                                            onChange={(e) => set({ company_id: e.target.value })}
                                        >
                                            <option value="">Все</option>
                                            {companies.map((c) => (
                                                <option key={c.id} value={c.id}>{c.name}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>
                            )}

                            {organizations.length > 1 && (
                                <Field label="Продавец" flex="1 1 220px">
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={state.organization_id}
                                            onChange={(e) => set({ organization_id: e.target.value })}
                                        >
                                            <option value="">Все</option>
                                            {organizations.map((o) => (
                                                <option key={o.id} value={o.id}>{o.name}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>
                            )}

                            <Field label="С даты" flex="0 1 170px">
                                <Input
                                    type="date"
                                    size="sm"
                                    value={state.date_from}
                                    onChange={(e) => set({ date_from: e.target.value })}
                                />
                            </Field>

                            <Field label="По дату" flex="0 1 170px">
                                <Input
                                    type="date"
                                    size="sm"
                                    value={state.date_to}
                                    onChange={(e) => set({ date_to: e.target.value })}
                                />
                            </Field>

                            <Button size="sm" colorPalette="pecado" onClick={apply}>Показать</Button>
                        </Flex>
                    </Card.Body>
                </Card.Root>

                {act?.discrepancy && (
                    <Card.Root {...cardProps} borderColor="orange.300" _dark={{ borderColor: 'orange.700' }}>
                        <Card.Body p="4">
                            <HStack gap={3} align="flex-start">
                                <Flex
                                    align="center" justify="center"
                                    w="9" h="9" borderRadius="full" flexShrink="0"
                                    bg="orange.subtle" color="orange.fg"
                                >
                                    <LuTriangleAlert size={18} />
                                </Flex>
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" color="fg">Данные уточняются</Text>
                                    {/*
                                        Клиенту не сообщаем внутреннюю кухню про регистр
                                        и учётную систему: ему важно знать, что цифру
                                        рано считать окончательной, а не почему так вышло.
                                    */}
                                    <Text fontSize="sm" color="fg.muted" mt={1}>
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
                    <Card.Root {...cardProps}>
                        <Card.Body p="4">
                            <Text fontSize="sm">
                                За выбранный период данных нет: расчёты в личном кабинете
                                ведутся с {act.ledger_starts_at}.
                            </Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {act && !act.before_ledger && (
                    <>
                        <Card.Root {...cardProps}>
                            <Card.Body p="5">
                                <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                                    <Box>
                                        <Text fontSize="xs" color="fg.muted" mb="0.5">Сальдо на начало</Text>
                                        <Text fontWeight="600" fontFamily="mono" color="fg">{money(act.opening_balance)}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="xs" color="fg.muted" mb="0.5">Начислено</Text>
                                        <Text fontWeight="600" fontFamily="mono" color="fg">{money(act.turnover_debit)}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="xs" color="fg.muted" mb="0.5">Оплачено</Text>
                                        <Text fontWeight="600" fontFamily="mono" color="fg">{money(act.turnover_credit)}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="xs" color="fg.muted" mb="0.5">
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
                                            fontFamily="mono"
                                            color={act.closing_balance < 0 ? 'red.fg' : 'green.fg'}
                                        >
                                            {money(Math.abs(act.closing_balance))}
                                        </Text>
                                    </Box>
                                </SimpleGrid>
                            </Card.Body>
                        </Card.Root>

                        {act.rows.length === 0 ? (
                            <Card.Root {...cardProps}>
                                <Card.Body p="10" textAlign="center">
                                    <VStack gap={3}>
                                        <Flex
                                            align="center" justify="center"
                                            w="16" h="16" borderRadius="full"
                                            bg="bg.muted" mx="auto"
                                        >
                                            <LuScale size={28} color="var(--chakra-colors-gray-400)" />
                                        </Flex>
                                        <Text fontWeight="600" fontSize="lg">Движений не было</Text>
                                        <Text color="gray.500" fontSize="sm">За выбранный период начислений и оплат нет</Text>
                                    </VStack>
                                </Card.Body>
                            </Card.Root>
                        ) : (
                            <Box {...cardProps} overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row bg="bg.subtle">
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
                                                <Table.Cell fontWeight="500">{row.document}</Table.Cell>
                                                <Table.Cell>
                                                    <Badge size="sm" variant="subtle">{row.type_label}</Badge>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end" fontFamily="mono">{row.debit ? money(row.debit) : '—'}</Table.Cell>
                                                <Table.Cell textAlign="end" fontFamily="mono">{row.credit ? money(row.credit) : '—'}</Table.Cell>
                                                <Table.Cell textAlign="end" fontWeight="600" fontFamily="mono">
                                                    {money(Math.abs(row.balance))}
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}

                        <Card.Root {...cardProps}>
                            <Card.Body p="4">
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
            </VStack>
        </CabinetLayout>
    );
}
