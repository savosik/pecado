import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    Image,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuImageOff, LuTruck } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Pagination } from '@/Admin/Components/Pagination';
import { useFlashToast } from '@/hooks/useFlashToast';

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : '—');

function ItemPhotos({ photos, alt }) {
    const [active, setActive] = useState(null);

    if (!photos || photos.length === 0) {
        return (
            <Box
                w="52px"
                h="52px"
                borderRadius="md"
                borderWidth="1px"
                borderColor="border"
                display="flex"
                alignItems="center"
                justifyContent="center"
                color="fg.muted"
                flexShrink={0}
            >
                <LuImageOff size={16} />
            </Box>
        );
    }

    return (
        <>
            <HStack gap={1} flexWrap="wrap">
                {photos.map((photo) => (
                    <Image
                        key={photo.id}
                        src={photo.thumb_url}
                        alt={alt}
                        w="52px"
                        h="52px"
                        objectFit="cover"
                        borderRadius="md"
                        cursor="pointer"
                        borderWidth="1px"
                        borderColor="border"
                        onClick={() => setActive(photo.url)}
                    />
                ))}
            </HStack>

            {active && (
                <Box
                    position="fixed"
                    inset="0"
                    bg="blackAlpha.800"
                    zIndex={2000}
                    display="flex"
                    alignItems="center"
                    justifyContent="center"
                    p="4"
                    onClick={() => setActive(null)}
                >
                    <Image src={active} alt={alt} maxH="90vh" maxW="90vw" objectFit="contain" borderRadius="md" />
                </Box>
            )}
        </>
    );
}

function OrderCard({ order }) {
    return (
        <Card.Root>
            <Card.Header pb={2}>
                <HStack justify="space-between" flexWrap="wrap" gap={2}>
                    <HStack gap={2}>
                        <Text fontWeight="semibold">Заказ {order.number}</Text>
                        {order.erp_number && (
                            <Badge colorPalette="gray" variant="subtle">1С: {order.erp_number}</Badge>
                        )}
                    </HStack>
                    <Text fontSize="sm" color="fg.muted">
                        {order.client_name || 'Клиент'} · {formatDate(order.created_at)}
                    </Text>
                </HStack>
                {order.warehouse_comment && (
                    <Text fontSize="sm" color="purple.500" mt={1}>
                        Комментарий для склада: {order.warehouse_comment}
                    </Text>
                )}
            </Card.Header>
            <Card.Body pt={0}>
                {/* Телефон: позиция карточкой — комплектовщик смотрит с телефона
                    и должен видеть фото дефекта крупно, без горизонтального скролла. */}
                <VStack gap={3} align="stretch" display={{ base: 'flex', lg: 'none' }}>
                    {order.items.map((item) => (
                        <Box
                            key={item.id}
                            borderWidth="1px"
                            borderColor="border"
                            borderRadius="md"
                            p={3}
                            opacity={item.defect_deleted ? 0.55 : 1}
                            bg={item.defect_deleted ? 'bg.muted' : undefined}
                        >
                            <VStack align="stretch" gap={2}>
                                <HStack justify="space-between" gap={2} align="start">
                                    <Box minW={0}>
                                        <Text fontSize="sm" fontWeight="medium">{item.product_name}</Text>
                                        <Text fontSize="xs" color="fg.muted">{item.sku || '—'}</Text>
                                    </Box>
                                    <Badge colorPalette={item.defect_deleted ? 'gray' : 'blue'} variant="subtle" flexShrink={0}>
                                        {item.quantity} шт.
                                    </Badge>
                                </HStack>
                                {item.defect_deleted && (
                                    <Badge colorPalette="gray" variant="surface" alignSelf="start">
                                        Партия удалена
                                    </Badge>
                                )}
                                <Text fontSize="sm">{item.defect_description || '—'}</Text>
                                <ItemPhotos photos={item.photos} alt={item.defect_description} />
                            </VStack>
                        </Box>
                    ))}
                </VStack>

                <Box overflowX="auto" display={{ base: 'none', lg: 'block' }}>
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Фото</Table.ColumnHeader>
                                <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                <Table.ColumnHeader>Дефект</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Кол-во</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {order.items.map((item) => (
                                <Table.Row
                                    key={item.id}
                                    opacity={item.defect_deleted ? 0.55 : 1}
                                    bg={item.defect_deleted ? 'bg.muted' : undefined}
                                >
                                    <Table.Cell>
                                        <ItemPhotos photos={item.photos} alt={item.defect_description} />
                                    </Table.Cell>
                                    <Table.Cell>
                                        <VStack align="start" gap={0}>
                                            <Text fontSize="sm">{item.product_name}</Text>
                                            <Text fontSize="xs" color="fg.muted">{item.sku || '—'}</Text>
                                        </VStack>
                                    </Table.Cell>
                                    <Table.Cell maxW="320px">
                                        <VStack align="start" gap={1}>
                                            <Text fontSize="sm">{item.defect_description || '—'}</Text>
                                            {item.defect_deleted && (
                                                <Badge colorPalette="gray" variant="surface">
                                                    Партия удалена
                                                </Badge>
                                            )}
                                        </VStack>
                                    </Table.Cell>
                                    <Table.Cell textAlign="end" fontWeight="semibold">
                                        {item.quantity}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Card.Body>
        </Card.Root>
    );
}

export default function DefectsShipping() {
    const { orders } = usePage().props;

    useFlashToast();

    return (
        <>
            <Head title="К отгрузке — Склад" />
            <PageHeader
                title="Некондиция к отгрузке"
                description="Заказы уценки, готовые к отгрузке. По каждому — какой дефектный экземпляр отдать."
            />

            {orders.data.length === 0 ? (
                <Card.Root>
                    <Card.Body>
                        <HStack gap={2} color="fg.muted" py={6} justify="center">
                            <LuTruck size={18} />
                            <Text fontSize="sm">
                                Нет заказов уценки к отгрузке. Позиции появятся, когда заказ переведут
                                в статус «Готов к отгрузке».
                            </Text>
                        </HStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <VStack gap={4} align="stretch">
                    {orders.data.map((order) => (
                        <OrderCard key={order.id} order={order} />
                    ))}

                    <Pagination
                        pagination={orders}
                        onPageChange={(page) => router.get('/wms/defects/shipping', { page }, {
                            preserveState: true,
                            replace: true,
                        })}
                    />
                </VStack>
            )}
        </>
    );
}

DefectsShipping.layout = (page) => <WmsLayout>{page}</WmsLayout>;
