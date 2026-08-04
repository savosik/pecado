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
import { LuArrowLeft, LuExternalLink, LuFileText, LuTruck } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import EntityCrmPanel from '@/Crm/Components/EntityCrmPanel';

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
    const palette = isOrder ? 'blue' : 'green';
    const Icon = isOrder ? LuFileText : LuTruck;

    return (
        <>
            <Head title={`CRM — ${document.title}`} />

            <PageHeader
                title={document.title}
                description={isOrder ? 'Заказ клиента' : 'Реализация клиента'}
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

                <Card.Root>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                            <InfoRow label="Номер в 1С" value={document.erp_number} />
                            <InfoRow label="Номер на сайте" value={document.number} />
                            <InfoRow label="Организация" value={document.organization} />
                            <InfoRow label="Склад" value={document.warehouse} />
                            <InfoRow label="Юрлицо клиента" value={document.company} />
                            <InfoRow label="Создан на сайте" value={document.created_at_label} />
                            <InfoRow label="Адрес доставки" value={document.delivery_address} />
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

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold">Позиции · {document.items.length}</Text>
                    </Card.Header>
                    <Card.Body>
                        {document.items.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">Позиций нет.</Text>
                        ) : (
                            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflowX="auto">
                                <Table.Root size="sm" variant="line">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                            <Table.ColumnHeader>Артикул</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">Кол-во</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">Цена</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">Сумма</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {document.items.map((item) => (
                                            <Table.Row key={item.id} _hover={{ bg: 'bg.muted' }}>
                                                <Table.Cell>
                                                    <Text fontSize="sm">{item.name}</Text>
                                                    {item.brand && (
                                                        <Text fontSize="xs" color="fg.muted">{item.brand}</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text fontSize="xs" fontFamily="mono" color="fg.muted">
                                                        {item.sku || '—'}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">
                                                    <Text fontSize="sm">{item.quantity}</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">
                                                    <Text fontSize="sm" whiteSpace="nowrap">{item.price_label}</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">
                                                    <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">
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
                                {isOrder ? 'Реализации по этому заказу' : 'Заказы, по которым сделана отгрузка'}
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
