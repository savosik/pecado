import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuArrowLeft, LuExternalLink, LuFileText, LuReceipt, LuTruck } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import EntityCrmPanel from '@/Crm/Components/EntityCrmPanel';
import PaymentScheduleBlock from '@/components/payments/PaymentScheduleBlock';

const CURRENCY_SYMBOLS = { RUB: '₽', KZT: '₸', BYN: 'Br' };

function InfoRow({ label, value }) {
    if (!value) return null;

    return (
        <Box>
            <Text fontSize="xs" color="fg.muted" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value}</Text>
        </Box>
    );
}

/**
 * Карточка заказа или реализации внутри CRM.
 *
 * Одна страница на оба типа: различаются они цветом, иконкой и составом
 * связанных документов, а не вёрсткой. Читаем только — документы принадлежат 1С,
 * редактирование живёт в админке и там же остаётся; кнопка «Открыть в админке»
 * появляется только у тех, кому туда можно.
 */
export default function Show() {
    const { document, client } = usePage().props;

    const isOrder = document.type === 'order';
    const isPayment = document.type === 'payment';
    const palette = isPayment ? 'purple' : (isOrder ? 'blue' : 'green');
    const Icon = isPayment ? LuReceipt : (isOrder ? LuFileText : LuTruck);
    const typeLabel = isPayment
        ? 'Платёж клиента'
        : (isOrder ? 'Заказ клиента' : 'Реализация клиента');

    return (
        <>
            <Head title={`CRM — ${document.title}`} />

            <PageHeader
                title={document.title}
                description={typeLabel}
                actions={(
                    <HStack gap={2}>
                        {client && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.visit(client.url)}
                            >
                                <LuArrowLeft /> К клиенту
                            </Button>
                        )}
                        {document.admin_url && (
                            <Button size="sm" variant="ghost" asChild>
                                <a href={document.admin_url}><LuExternalLink /> В админке</a>
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack gap={3} align="stretch">
                <Box borderWidth="1px" borderColor="border" borderLeftWidth="3px"
                    borderLeftColor={`${palette}.solid`} borderRadius="lg" bg="bg.panel" px={4} py={3}
                >
                    <HStack gap={4} flexWrap="wrap" align="center">
                        <HStack gap={2} color={`${palette}.fg`}>
                            <Icon size={16} />
                            <Text fontSize="lg" fontWeight="700">{document.total_label}</Text>
                        </HStack>

                        <Badge colorPalette={document.status_color || 'gray'} variant="subtle">
                            {document.status_label}
                        </Badge>

                        {/* v15.16.0: предоплата по заказу из расшифровки платежей 1С.
                            Реализации по такому заказу может ещё не быть */}
                        {document.prepaid_label && (
                            <Badge colorPalette="blue" variant="subtle">
                                Предоплата: {document.prepaid_label}
                            </Badge>
                        )}

                        <Text fontSize="sm" color="fg.muted">{document.date_label}</Text>

                        {client && (
                            <Text fontSize="sm">
                                Клиент:{' '}
                                <Box as="a" href={client.url} color="blue.fg" textDecoration="underline">
                                    {client.name}
                                </Box>
                            </Text>
                        )}
                    </HStack>
                </Box>

                {/* Плитки итогов есть только у платежа: у заказа и реализации
                    одна сумма, и отдельный блок под неё был бы шумом. */}
                {document.summary?.length > 0 && (
                    <SimpleGrid columns={{ base: 1, md: document.summary.length }} gap={3}>
                        {document.summary.map((tile) => (
                            <Card.Root key={tile.label}>
                                <Card.Body>
                                    <Text fontSize="xs" color="fg.muted" mb={1}>{tile.label}</Text>
                                    <Text
                                        fontSize="lg"
                                        fontWeight="700"
                                        color={{ positive: 'green.fg', warning: 'orange.fg' }[tile.tone]}
                                    >
                                        {tile.value}
                                    </Text>
                                </Card.Body>
                            </Card.Root>
                        ))}
                    </SimpleGrid>
                )}

                <Card.Root>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                            <InfoRow label="Номер в 1С" value={document.erp_number} />
                            <InfoRow label="Номер на сайте" value={document.number} />
                            {document.organization && (
                                <Box>
                                    <Text fontSize="xs" color="fg.muted" mb="0.5">Организация</Text>
                                    <HStack gap={2}>
                                        <Text fontSize="sm" fontWeight="500">{document.organization.name}</Text>
                                        {/* У незаведённого юрлица вместо названия лежит UUID из 1С —
                                            бейдж объясняет менеджеру, почему строка выглядит так. */}
                                        {document.organization.is_stub && (
                                            <Badge colorPalette="orange" variant="subtle" size="sm">
                                                не заведена
                                            </Badge>
                                        )}
                                    </HStack>
                                </Box>
                            )}
                            <InfoRow label="Склад отгрузки" value={document.warehouse} />
                            <InfoRow label="Юрлицо клиента" value={document.company} />
                            <InfoRow label="Создан на сайте" value={document.created_at_label} />
                            <InfoRow label="Адрес доставки" value={document.delivery_address} />
                            {/* v15.16.0: счёт-фактура из 1С, приходит не по всем реализациям */}
                            <InfoRow label="Счёт-фактура" value={document.invoice_label} />
                        </SimpleGrid>

                        {(document.comment || document.manager_comment) && (
                            <VStack align="stretch" gap={2} mt={4} pt={3} borderTopWidth="1px">
                                {document.comment && (
                                    <Box>
                                        <Text fontSize="xs" color="fg.muted">Комментарий клиента</Text>
                                        <Text fontSize="sm" whiteSpace="pre-wrap">{document.comment}</Text>
                                    </Box>
                                )}
                                {document.manager_comment && (
                                    <Box>
                                        <Text fontSize="xs" color="fg.muted">Комментарий менеджера</Text>
                                        <Text fontSize="sm" whiteSpace="pre-wrap">{document.manager_comment}</Text>
                                    </Box>
                                )}
                            </VStack>
                        )}
                    </Card.Body>
                </Card.Root>

                {/* Реквизиты платёжного поручения. Пропа нет у заказов
                    и реализаций — блок для них просто не рисуется. */}
                {document.details?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold">Реквизиты документа</Text>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                                {document.details.map((detail) => (
                                    <InfoRow key={detail.label} label={detail.label} value={detail.value} />
                                ))}
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Оплата реализации: чем закрыта и сколько осталось. */}
                {document.payment_summary && (
                    <Card.Root>
                        <Card.Header>
                            <HStack justify="space-between" flexWrap="wrap" gap={2}>
                                <Text fontWeight="semibold">Оплата</Text>
                                <Badge
                                    colorPalette={{
                                        paid: 'green', partial: 'orange', overpaid: 'purple', unpaid: 'gray',
                                    }[document.payment_summary.status] || 'gray'}
                                    variant="subtle"
                                >
                                    {document.payment_summary.status_label}
                                </Badge>
                            </HStack>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4} mb={document.payments?.length ? 4 : 0}>
                                <InfoRow label="Сумма реализации" value={document.payment_summary.total_label} />
                                <InfoRow label="Оплачено" value={document.payment_summary.paid_label} />
                                <InfoRow label="Остаток к оплате" value={document.payment_summary.unpaid_label} />
                            </SimpleGrid>

                            {document.payments?.length > 0 ? (
                                <VStack align="stretch" gap={2}>
                                    {document.payments.map((payment) => (
                                        <HStack
                                            key={payment.id}
                                            justify="space-between"
                                            borderWidth="1px"
                                            borderColor="border.muted"
                                            borderRadius="md"
                                            px={3}
                                            py={2}
                                            _hover={{ bg: 'bg.muted' }}
                                        >
                                            <HStack gap={3} flexWrap="wrap">
                                                <Text fontSize="sm" fontWeight="600">
                                                    Платёж №{payment.number || payment.id}
                                                </Text>
                                                <Text fontSize="xs" color="fg.muted">{payment.date_label}</Text>
                                                <Badge
                                                    colorPalette={payment.direction === 'out' ? 'red' : 'green'}
                                                    variant="subtle"
                                                >
                                                    {payment.direction_label}
                                                </Badge>
                                                <Text fontSize="sm">{payment.amount_label}</Text>
                                            </HStack>
                                            <Button size="xs" variant="ghost" onClick={() => router.visit(payment.url)}>
                                                Открыть
                                            </Button>
                                        </HStack>
                                    ))}
                                </VStack>
                            ) : (
                                <Text fontSize="sm" color="fg.muted">
                                    Платежей по этой реализации пока нет.
                                </Text>
                            )}
                        </Card.Body>
                    </Card.Root>
                )}

                {/* График оплаты из 1С. Приходит не по всем реализациям —
                    блок сам себя скрывает, когда графика нет. */}
                {document.payment_schedule && (
                    <PaymentScheduleBlock
                        schedule={document.payment_schedule}
                        currencySymbol={CURRENCY_SYMBOLS[document.currency_code] || document.currency_code || '₽'}
                    />
                )}

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold">
                            {document.items_title || 'Позиции'} · {document.items.length}
                        </Text>
                    </Card.Header>
                    <Card.Body>
                        {document.items.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">
                                {isPayment
                                    ? 'Платёж не разнесён по реализациям — вся сумма числится авансом.'
                                    : 'Позиций нет.'}
                            </Text>
                        ) : (
                            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflowX="auto">
                                <Table.Root size="sm" variant="line">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader>{isPayment ? 'Документ' : 'Товар'}</Table.ColumnHeader>
                                            <Table.ColumnHeader>{isPayment ? 'UUID в 1С' : 'Артикул'}</Table.ColumnHeader>
                                            {!isPayment && <Table.ColumnHeader textAlign="end">Кол-во</Table.ColumnHeader>}
                                            <Table.ColumnHeader textAlign="end">{isPayment ? 'Сумма документа' : 'Цена'}</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">{isPayment ? 'Разнесено' : 'Сумма'}</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {document.items.map((item) => (
                                            <Table.Row
                                                key={item.id}
                                                _hover={{ bg: 'bg.muted' }}
                                                opacity={item.cancelled ? 0.6 : 1}
                                            >
                                                <Table.Cell>
                                                    <Text fontSize="sm">{item.name}</Text>
                                                    {item.brand && (
                                                        <Text fontSize="xs" color="fg.muted">{item.brand}</Text>
                                                    )}
                                                    {/* Недобор: строка отменена в 1С, в сумму документа не входит */}
                                                    {item.cancelled && (
                                                        <Text fontSize="xs" color="fg.muted" fontWeight="500">
                                                            Отменена в 1С — нет в наличии
                                                        </Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text fontSize="xs" fontFamily="mono" color="fg.muted">
                                                        {item.sku || '—'}
                                                    </Text>
                                                </Table.Cell>
                                                {!isPayment && (
                                                    <Table.Cell textAlign="end">
                                                        <Text fontSize="sm">{item.quantity}</Text>
                                                    </Table.Cell>
                                                )}
                                                <Table.Cell textAlign="end">
                                                    <Text fontSize="sm" whiteSpace="nowrap">{item.price_label}</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">
                                                    <Text
                                                        fontSize="sm"
                                                        fontWeight="600"
                                                        whiteSpace="nowrap"
                                                        textDecoration={item.cancelled ? 'line-through' : undefined}
                                                        color={item.cancelled ? 'fg.muted' : undefined}
                                                    >
                                                        {item.total_label}
                                                    </Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}
                    </Card.Body>
                </Card.Root>

                {document.related.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold">
                                {isPayment
                                    ? 'Реализации, на которые разнесён платёж'
                                    : (isOrder ? 'Реализации по этому заказу' : 'Заказы, по которым сделана отгрузка')}
                            </Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack align="stretch" gap={2}>
                                {document.related.map((related) => (
                                    <HStack
                                        key={`${related.type}-${related.id}`}
                                        justify="space-between"
                                        borderWidth="1px"
                                        borderColor="border.muted"
                                        borderRadius="md"
                                        px={3}
                                        py={2}
                                        _hover={{ bg: 'bg.muted' }}
                                    >
                                        <HStack gap={3} flexWrap="wrap">
                                            <Text fontSize="sm" fontWeight="600">{related.title}</Text>
                                            <Text fontSize="xs" color="fg.muted">{related.date_label}</Text>
                                            <Text fontSize="sm">{related.total_label}</Text>
                                        </HStack>
                                        <Button size="xs" variant="ghost" onClick={() => router.visit(related.url)}>
                                            Открыть
                                        </Button>
                                    </HStack>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                <EntityCrmPanel
                    entityType={document.type}
                    entityId={document.id}
                    title="Работа по документу"
                />
            </VStack>
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
