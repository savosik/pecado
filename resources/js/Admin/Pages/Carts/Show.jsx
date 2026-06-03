import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Card, HStack, Image, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { cart } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Корзина #${cart.id}`} />
            <PageHeader
                title={`Корзина #${cart.id}`}
                backUrl={route('admin.carts.index')}
                backLabel="К списку корзин"
                actions={
                    can('carts.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.carts.edit', cart.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Информация о корзине</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={cart.id?.toString()} />
                            <InfoRow label="Название" value={cart.name} />
                            <InfoRow label="Пользователь" value={cart.user ? `${cart.user.name} (${cart.user.email})` : null} />
                            <InfoRow label="Итого" value={cart.total_amount ? `${cart.total_amount} ₽` : null} />
                            <InfoRow label="Создана" value={cart.created_at} />
                            <InfoRow label="Обновлена" value={cart.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {cart.items?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Товары ({cart.items.length})</Text>
                        </Card.Header>
                        <Card.Body>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                        <Table.ColumnHeader>Артикул</Table.ColumnHeader>
                                        <Table.ColumnHeader>Кол-во</Table.ColumnHeader>
                                        <Table.ColumnHeader>Цена</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {cart.items.map((item) => (
                                        <Table.Row key={item.id}>
                                            <Table.Cell>
                                                <HStack gap={2}>
                                                    {item.product?.image_url && (
                                                        <Image src={item.product.image_url} alt={item.product?.name} w="32px" h="32px" objectFit="contain" borderRadius="sm" />
                                                    )}
                                                    <Text fontSize="sm">{item.product?.name || '—'}</Text>
                                                </HStack>
                                            </Table.Cell>
                                            <Table.Cell>{item.product?.sku || '—'}</Table.Cell>
                                            <Table.Cell>{item.quantity}</Table.Cell>
                                            <Table.Cell>{item.product?.base_price ? `${item.product.base_price} ₽` : '—'}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
