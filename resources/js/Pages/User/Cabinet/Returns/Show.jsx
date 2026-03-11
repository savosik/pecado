import {
    Box, Flex, Text, Button, Table, Badge, Stack,
    Card, HStack, VStack, SimpleGrid, Image,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    LuArrowLeft, LuRotateCcw, LuPackage, LuMessageSquare,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';

const STATUS_COLORS = {
    pending: 'yellow',
    approved: 'green',
    rejected: 'red',
    completed: 'blue',
};

const REASON_LABELS = {
    defective: 'Бракованный товар',
    wrong_item: 'Неправильный товар',
    changed_mind: 'Передумал',
    damaged_in_transit: 'Повреждён при доставке',
    wrong_size: 'Неправильный размер',
    other: 'Другое',
};

export default function ReturnShow() {
    const { return: returnData } = usePage().props;

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <CabinetLayout
            title={`Возврат #${returnData.id}`}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href="/cabinet/returns">
                        <LuArrowLeft size={16} /> К списку
                    </Link>
                </Button>
            }
        >
            <Head title={`Возврат #${returnData.id} — Pecado`} />

            <Stack gap="5">
                {/* Статус */}
                <Flex align="center" gap="3" flexWrap="wrap">
                    <Badge
                        colorPalette={STATUS_COLORS[returnData.status] ?? 'gray'}
                        variant="subtle"
                        fontSize="sm"
                        px="3"
                        py="1"
                        borderRadius="full"
                    >
                        {returnData.status_label}
                    </Badge>
                    <Text fontSize="sm" color="fg.muted">
                        UUID: {returnData.uuid?.substring(0, 8)}...
                    </Text>
                </Flex>

                {/* Информация о возврате */}
                <SimpleGrid columns={{ base: 1, lg: 2 }} gap="4">
                    <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                        <Card.Header p="4" pb="2">
                            <Text fontWeight="700" fontSize="md">Информация о возврате</Text>
                        </Card.Header>
                        <Card.Body p="4" pt="0">
                            <VStack align="stretch" gap="3">
                                <InfoRow label="Дата создания" value={returnData.created_at} />
                                <InfoRow label="Обновлён" value={returnData.updated_at} />
                                <InfoRow label="Сумма" value={`${fmt(returnData.total_amount)} ₽`} bold />
                                <InfoRow label="Позиций" value={returnData.items?.length || 0} />
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    {returnData.comment && (
                        <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                            <Card.Header p="4" pb="2">
                                <HStack gap="2">
                                    <LuMessageSquare size={18} />
                                    <Text fontWeight="700" fontSize="md">Ваш комментарий</Text>
                                </HStack>
                            </Card.Header>
                            <Card.Body p="4" pt="0">
                                <Text fontSize="sm">{returnData.comment}</Text>
                            </Card.Body>
                        </Card.Root>
                    )}
                </SimpleGrid>

                {/* Позиции возврата */}
                {returnData.items?.length > 0 && (
                    <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                        <Card.Header p="4" pb="2">
                            <HStack gap="2">
                                <LuPackage size={20} />
                                <Text fontWeight="700" fontSize="md">
                                    Позиции возврата ({returnData.items.length})
                                </Text>
                            </HStack>
                        </Card.Header>
                        <Card.Body p="0">
                            {/* Desktop table */}
                            <Box overflowX="auto" display={{ base: 'none', md: 'block' }}>
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
                                            <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                            <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                            <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right" w="110px">Цена</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="center" w="80px">Кол-во</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right" w="120px">Сумма</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {returnData.items.map((item) => (
                                            <Table.Row key={item.id}>
                                                <Table.Cell>
                                                    <HStack gap="3">
                                                        {item.product?.image_url && (
                                                            <Image
                                                                src={item.product.image_url}
                                                                alt={item.product?.name}
                                                                w="10"
                                                                h="10"
                                                                objectFit="contain"
                                                                borderRadius="md"
                                                                flexShrink="0"
                                                                bg="gray.50"
                                                            />
                                                        )}
                                                        <Box>
                                                            {item.product?.slug ? (
                                                                <Link href={`/products/${item.product.slug}`}>
                                                                    <Text
                                                                        fontWeight="500"
                                                                        fontSize="sm"
                                                                        _hover={{ color: 'pecado.500' }}
                                                                        transition="color 0.15s"
                                                                    >
                                                                        {item.product?.name || 'Товар удалён'}
                                                                    </Text>
                                                                </Link>
                                                            ) : (
                                                                <Text fontWeight="500" fontSize="sm">
                                                                    {item.product?.name || 'Товар удалён'}
                                                                </Text>
                                                            )}
                                                            {item.product?.sku && (
                                                                <Text fontSize="xs" color="fg.muted">
                                                                    SKU: {item.product.sku}
                                                                </Text>
                                                            )}
                                                        </Box>
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {item.order ? (
                                                        <Badge colorPalette="purple" variant="subtle" fontSize="xs">
                                                            #{item.order.id}
                                                        </Badge>
                                                    ) : (
                                                        <Text color="fg.muted">—</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <VStack align="start" gap="0">
                                                        <Badge colorPalette="orange" variant="subtle" fontSize="xs">
                                                            {item.reason_label || REASON_LABELS[item.reason] || item.reason}
                                                        </Badge>
                                                        {item.reason_comment && (
                                                            <Text fontSize="xs" color="fg.muted" mt="1" lineClamp={2}>
                                                                {item.reason_comment}
                                                            </Text>
                                                        )}
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontWeight="500">{fmt(item.price)} ₽</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="center">
                                                    {item.quantity}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontWeight="600">{fmt(item.subtotal)} ₽</Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>

                            {/* Mobile cards */}
                            <VStack gap="0" display={{ base: 'flex', md: 'none' }}>
                                {returnData.items.map((item, idx) => (
                                    <Box
                                        key={item.id}
                                        p="4"
                                        borderTopWidth={idx > 0 ? '1px' : '0'}
                                        borderColor="gray.100"
                                        _dark={{ borderColor: 'gray.700' }}
                                        w="full"
                                    >
                                        <VStack align="stretch" gap="2">
                                            <HStack gap="3">
                                                {item.product?.image_url && (
                                                    <Image
                                                        src={item.product.image_url}
                                                        alt={item.product?.name}
                                                        w="12"
                                                        h="12"
                                                        objectFit="contain"
                                                        borderRadius="md"
                                                        flexShrink="0"
                                                        bg="gray.50"
                                                    />
                                                )}
                                                <Box flex="1">
                                                    <Text fontWeight="600" fontSize="sm" lineClamp={2}>
                                                        {item.product?.name || 'Товар удалён'}
                                                    </Text>
                                                    {item.product?.sku && (
                                                        <Text fontSize="xs" color="fg.muted">SKU: {item.product.sku}</Text>
                                                    )}
                                                </Box>
                                            </HStack>
                                            <Flex justify="space-between" align="center">
                                                <Badge colorPalette="orange" variant="subtle" fontSize="xs">
                                                    {item.reason_label || REASON_LABELS[item.reason] || item.reason}
                                                </Badge>
                                                <Text fontWeight="700" fontSize="sm">
                                                    {item.quantity} × {fmt(item.price)} = {fmt(item.subtotal)} ₽
                                                </Text>
                                            </Flex>
                                            {item.reason_comment && (
                                                <Text fontSize="xs" color="fg.muted">{item.reason_comment}</Text>
                                            )}
                                        </VStack>
                                    </Box>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Итого */}
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                    <Card.Body p="4">
                        <Flex justify="space-between" align="center">
                            <Flex align="center" gap="2">
                                <LuRotateCcw size={20} />
                                <Text fontWeight="700" fontSize="lg">Итого к возврату</Text>
                            </Flex>
                            <Text fontSize="xl" fontWeight="800">
                                {fmt(returnData.total_amount)} ₽
                            </Text>
                        </Flex>
                    </Card.Body>
                </Card.Root>
            </Stack>
        </CabinetLayout>
    );
}

function InfoRow({ label, value, bold }) {
    return (
        <Flex gap="2" direction={{ base: 'column', sm: 'row' }}>
            <Text fontWeight="600" minW="130px" color="fg.muted" fontSize="sm">
                {label}:
            </Text>
            <Text fontSize="sm" fontWeight={bold ? '700' : '400'}>{value}</Text>
        </Flex>
    );
}
