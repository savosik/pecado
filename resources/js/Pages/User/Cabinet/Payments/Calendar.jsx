import { useMemo, useState } from 'react';
import axios from 'axios';
import { Box, Card, Flex, HStack, VStack, SimpleGrid, Text, Badge, Stack } from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import { LuList, LuCalendar, LuScale, LuTriangleAlert, LuBuilding2, LuArrowRight, LuReceipt } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Button } from '@/components/ui/button';
import PaymentCalendarGrid, { formatMoney } from '@/components/payments/PaymentCalendarGrid';
import PaymentOrderDialog from '@/shared/PaymentOrderDialog';

const CURRENCY_SYMBOLS = { RUB: '₽', KZT: '₸', BYN: 'Br' };

const toQuery = (params) => new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
).toString();

/**
 * Календарь оплат клиента — план по графику из 1С («Правила оплаты» реализации).
 *
 * Это именно план: факт оплаты клиент смотрит списком в этом же разделе.
 * У каждой строки — пара «контрагент → наше юрлицо» и кнопка «Платёжка»,
 * которая открывает готовое платёжное поручение именно по этому документу.
 */
export default function PaymentsCalendar({
    month,
    monthLabel,
    prevMonth,
    nextMonth,
    today,
    entries = [],
    overdueEntries = [],
    summary = {},
    companies = [],
    companyId = null,
    currencyCode = 'RUB',
    paymentOrdersEnabled = false,
}) {
    const [selectedDate, setSelectedDate] = useState(null);
    // Строка графика, по которой открыт диалог платёжки; null — диалог закрыт.
    const [paymentEntry, setPaymentEntry] = useState(null);
    const symbol = CURRENCY_SYMBOLS[currencyCode] || currencyCode;

    // Разрез по контрагенту применяется сразу: календарь — не форма с кнопкой
    // «Показать», лишний клик тут только мешает.
    const changeCompany = (value) => {
        setSelectedDate(null);
        router.get('/cabinet/payments/calendar', {
            month,
            ...(value ? { company_id: value } : {}),
        }, { preserveState: true, replace: true });
    };

    // Группировка по дням делается один раз на выдачу, а не в каждой из 42 ячеек.
    const byDate = useMemo(() => {
        const map = new Map();

        entries.forEach((entry) => {
            const bucket = map.get(entry.due_date) || { unpaid: 0, overdue: false, items: [] };
            bucket.unpaid += entry.unpaid_amount;
            bucket.overdue = bucket.overdue || entry.is_overdue;
            bucket.items.push(entry);
            map.set(entry.due_date, bucket);
        });

        return map;
    }, [entries]);

    const changeMonth = (next) => {
        setSelectedDate(null);
        router.get('/cabinet/payments/calendar', {
            month: next,
            // Разрез по контрагенту переживает листание месяцев.
            ...(companyId ? { company_id: companyId } : {}),
        }, { preserveState: true, replace: true });
    };

    const selected = selectedDate ? byDate.get(selectedDate) : null;
    const selectedCompany = companies.find((c) => String(c.id) === String(companyId)) || null;

    // Платёжка есть только у строки с полной парой: без нашего юрлица неизвестно,
    // чьи реквизиты подставлять, а по оплаченной строке платить нечего.
    const canPay = (entry) => paymentOrdersEnabled
        && entry.company_id && entry.organization_id && !entry.is_paid;

    const onSendPaymentOrder = (payload) => new Promise((resolve, reject) => {
        router.post('/cabinet/payment-orders/send', payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => resolve(),
            onError: (errors) => reject(new Error(Object.values(errors || {})[0] || 'Не удалось отправить')),
        });
    });

    return (
        <CabinetLayout title="Оплаты">
            <Head title="Календарь оплат — Pecado" />

            <Flex gap="2" mb="4" align="center" wrap="wrap">
                <Button size="sm" variant="outline" asChild>
                    <Link href="/cabinet/payments">
                        <LuList size={16} /> Список
                    </Link>
                </Button>
                <Button size="sm" variant="solid" colorPalette="pecado">
                    <LuCalendar size={16} /> Календарь
                </Button>
                <Button size="sm" variant="outline" asChild>
                    <Link href="/cabinet/payments/reconciliation">
                        <LuScale size={16} /> Акт сверки
                    </Link>
                </Button>
            </Flex>

            {companies.length > 1 && (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor={companyId ? 'pecado.200' : 'border.muted'} mb="4">
                    <Card.Body p="3">
                        <Flex align="center" gap="3" wrap="wrap">
                            <HStack gap="2" fontSize="sm" fontWeight="600" color="fg" flexShrink="0">
                                <LuBuilding2 size={16} />
                                <Text>Контрагент</Text>
                            </HStack>
                            <HStack gap="2" wrap="wrap">
                                <Button
                                    size="sm"
                                    borderRadius="full"
                                    variant={companyId ? 'outline' : 'solid'}
                                    colorPalette={companyId ? 'gray' : 'pecado'}
                                    onClick={() => changeCompany('')}
                                >
                                    Все компании
                                </Button>
                                {companies.map((c) => {
                                    const active = String(c.id) === String(companyId);

                                    return (
                                        <Button
                                            key={c.id}
                                            size="sm"
                                            borderRadius="full"
                                            variant={active ? 'solid' : 'outline'}
                                            colorPalette={active ? 'pecado' : 'gray'}
                                            onClick={() => changeCompany(active ? '' : String(c.id))}
                                        >
                                            {c.name}
                                        </Button>
                                    );
                                })}
                            </HStack>
                            <Text fontSize="xs" color="fg.muted" ml={{ md: 'auto' }}>
                                {selectedCompany
                                    ? 'Суммы и график — только по выбранному контрагенту'
                                    : 'Суммы и график — по всем вашим компаниям'}
                            </Text>
                        </Flex>
                    </Card.Body>
                </Card.Root>
            )}

            <SimpleGrid columns={{ base: 1, md: 3 }} gap="3" mb="4">
                <SummaryTile
                    label="Просрочено"
                    value={`${formatMoney(summary.overdue_amount)} ${symbol}`}
                    hint={summary.overdue_count ? `Документов: ${summary.overdue_count}` : 'Просроченных платежей нет'}
                    tone={summary.overdue_amount > 0 ? 'red' : 'green'}
                />
                <SummaryTile
                    label="К оплате за 7 дней"
                    value={`${formatMoney(summary.week_amount)} ${symbol}`}
                    hint="Считая сегодняшний день"
                />
                <SummaryTile
                    label={`К оплате: ${monthLabel.toLowerCase()}`}
                    value={`${formatMoney(summary.month_amount)} ${symbol}`}
                    hint="Остаток по графику за месяц"
                />
            </SimpleGrid>

            <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" mb="4">
                <Card.Body p="4">
                    <PaymentCalendarGrid
                        month={month}
                        monthLabel={monthLabel}
                        prevMonth={prevMonth}
                        nextMonth={nextMonth}
                        today={today}
                        selectedDate={selectedDate}
                        onSelectDate={setSelectedDate}
                        onChangeMonth={changeMonth}
                        renderCell={(date) => {
                            const bucket = byDate.get(date);

                            if (!bucket || bucket.unpaid <= 0) {
                                return null;
                            }

                            return (
                                <Text
                                    fontSize="xs"
                                    mt="1"
                                    fontWeight="semibold"
                                    color={bucket.overdue ? 'red.fg' : 'fg'}
                                >
                                    {formatMoney(bucket.unpaid)} {symbol}
                                </Text>
                            );
                        }}
                    />
                </Card.Body>
            </Card.Root>

            {selected && (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" mb="4">
                    <Card.Body p="4">
                        <Text fontWeight="semibold" mb="3">
                            Платежи {selectedDate.split('-').reverse().join('.')}
                        </Text>
                        <Stack gap="2">
                            {selected.items.map((entry) => (
                                <EntryRow
                                    key={entry.id}
                                    entry={entry}
                                    symbol={symbol}
                                    onPaymentOrder={canPay(entry) ? setPaymentEntry : null}
                                />
                            ))}
                        </Stack>
                    </Card.Body>
                </Card.Root>
            )}

            {overdueEntries.length > 0 && (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="red.muted">
                    <Card.Body p="4">
                        <HStack mb="3" gap="2">
                            <LuTriangleAlert size={16} />
                            <Text fontWeight="semibold">Просроченные платежи</Text>
                        </HStack>
                        <Stack gap="2">
                            {overdueEntries.map((entry) => (
                                <EntryRow
                                    key={entry.id}
                                    entry={entry}
                                    symbol={symbol}
                                    showDate
                                    onPaymentOrder={canPay(entry) ? setPaymentEntry : null}
                                />
                            ))}
                        </Stack>
                    </Card.Body>
                </Card.Root>
            )}

            {entries.length === 0 && overdueEntries.length === 0 && (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="6">
                        <Text color="fg.muted" textAlign="center">
                            За этот месяц плановых платежей нет. График оплаты приходит из учётной
                            системы вместе с накладной — если его ещё нет, обратитесь к менеджеру.
                        </Text>
                    </Card.Body>
                </Card.Root>
            )}

            <PaymentOrderDialog
                open={paymentEntry !== null}
                onClose={() => setPaymentEntry(null)}
                title="Платёжное поручение"
                description={paymentEntry
                    ? [
                        `${paymentEntry.shipment.kind_label || 'Реализация'} ${paymentEntry.shipment.number}`,
                        [paymentEntry.company, paymentEntry.organization].filter(Boolean).join(' → '),
                    ].filter(Boolean).join(' · ')
                    : null}
                loadOptions={() => axios.get('/cabinet/payment-orders/options').then(({ data }) => data)}
                previewUrl={(params) => `/cabinet/payment-orders/preview?${toQuery(params)}`}
                downloadUrl={(params, format) => `/cabinet/payment-orders/download?${toQuery({ ...params, format })}`}
                onSend={onSendPaymentOrder}
                preset={paymentEntry
                    ? {
                        pairKey: `${paymentEntry.company_id}:${paymentEntry.organization_id}`,
                        scenario: 'document',
                        entryId: paymentEntry.id,
                    }
                    : null}
            />
        </CabinetLayout>
    );
}

function SummaryTile({ label, value, hint, tone }) {
    return (
        <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor={tone === 'red' ? 'red.muted' : 'border.muted'}>
            <Card.Body p="4">
                <Text fontSize="xs" color="fg.muted" mb="1">{label}</Text>
                <Text fontSize="xl" fontWeight="bold" color={tone === 'red' ? 'red.fg' : 'fg'}>{value}</Text>
                <Text fontSize="xs" color="fg.muted" mt="1">{hint}</Text>
            </Card.Body>
        </Card.Root>
    );
}

function EntryRow({ entry, symbol, showDate = false, onPaymentOrder = null }) {
    return (
        <Flex
            justify="space-between"
            align={{ base: 'flex-start', sm: 'center' }}
            direction={{ base: 'column', sm: 'row' }}
            gap="3"
            p="3"
            borderRadius="lg"
            border="1px solid"
            borderColor="border.muted"
        >
            <VStack align="flex-start" gap="1" minW="0">
                <HStack gap="2" wrap="wrap">
                    {/* На регистре строка графика бывает не только от реализации:
                        предоплата по заказу приходит с тем же типом. Название вида
                        документа даёт лента, старый расчёт его не знает. */}
                    <Text fontWeight="medium">
                        {entry.shipment.kind_label || 'Реализация'} {entry.shipment.number}
                    </Text>
                    {entry.is_overdue && <Badge colorPalette="red" size="sm">Просрочено</Badge>}
                    {entry.is_paid && <Badge colorPalette="green" size="sm">Оплачено</Badge>}
                </HStack>

                {/* Кто кому платит: у клиента с несколькими компаниями и двумя нашими
                    юрлицами без этой строки платёж уходит не на те реквизиты. */}
                {(entry.company || entry.organization) && (
                    <HStack gap="1.5" fontSize="xs" wrap="wrap">
                        <Box color="fg.muted" flexShrink="0"><LuBuilding2 size={12} /></Box>
                        <Text fontWeight="500" color="fg">{entry.company || 'Контрагент не указан'}</Text>
                        {entry.organization && (
                            <>
                                <Box color="fg.muted" flexShrink="0"><LuArrowRight size={12} /></Box>
                                <Text fontWeight="500" color="fg">{entry.organization}</Text>
                            </>
                        )}
                    </HStack>
                )}

                <Text fontSize="xs" color="fg.muted">
                    {showDate && `Срок ${entry.due_date_label} · `}
                    {entry.shipment.date_label && `Отгрузка ${entry.shipment.date_label}`}
                    {entry.stage_name && ` · ${entry.stage_name}`}
                </Text>
            </VStack>

            <HStack gap="3" flexShrink="0" w={{ base: '100%', sm: 'auto' }} justify={{ base: 'space-between', sm: 'flex-end' }}>
                <Box textAlign="right">
                    <Text fontWeight="semibold">{formatMoney(entry.unpaid_amount)} {symbol}</Text>
                    <Text fontSize="xs" color="fg.muted">из {formatMoney(entry.amount)} {symbol}</Text>
                </Box>
                <HStack gap="1">
                    {onPaymentOrder && (
                        <Button
                            size="xs"
                            colorPalette="pecado"
                            variant={entry.is_overdue ? 'solid' : 'outline'}
                            onClick={() => onPaymentOrder(entry)}
                        >
                            <LuReceipt size={14} /> Платёжка
                        </Button>
                    )}
                    {/* Ссылки может не быть: у предоплаты по заказу карточки в кабинете
                        нет, и лента отдаёт url = null. Inertia Link на null роняет всю
                        страницу — TypeError на href.toString(). */}
                    {entry.shipment.url && (
                        <Button size="xs" variant="ghost" asChild>
                            <Link href={entry.shipment.url}>Открыть</Link>
                        </Button>
                    )}
                </HStack>
            </HStack>
        </Flex>
    );
}
