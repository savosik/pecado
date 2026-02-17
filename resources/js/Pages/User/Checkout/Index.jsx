import { Head, Link, usePage } from '@inertiajs/react';
import {
    Box, Flex, Text, Heading, Button, Table, Badge, Separator,
} from '@chakra-ui/react';
import { LuArrowLeft, LuConstruction } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';

/**
 * Страница оформления заказа — заглушка.
 *
 * Props от CheckoutController@index:
 *   - cart: { id, name }
 *   - cartDetails: { items, total_quantity, total_amount_regular, total_amount_discounted, ... }
 */
export default function CheckoutIndex({ cart, cartDetails }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const items = cartDetails?.items ?? [];
    const totalQty = cartDetails?.total_quantity ?? 0;
    const totalRegular = Number(cartDetails?.total_amount_regular ?? 0);
    const totalDiscounted = Number(cartDetails?.total_amount_discounted ?? 0);
    const hasDiscount = totalRegular > 0 && totalRegular !== totalDiscounted;

    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Корзина', url: '/cart' },
        { label: 'Оформление заказа' },
    ];

    // Merge items by product_id for display
    const byProduct = new Map();
    for (const it of items) {
        const pid = Number(it.product?.id);
        if (!pid) continue;
        if (!byProduct.has(pid)) {
            byProduct.set(pid, {
                product: it.product,
                instockQty: 0,
                preorderQty: 0,
                totalAmount: 0,
            });
        }
        const row = byProduct.get(pid);
        const qty = Number(it.quantity || 0);
        if (it.item_type === 'instock') row.instockQty += qty;
        else row.preorderQty += qty;
        row.totalAmount += Number(it.total_amount_discounted ?? it.total_amount ?? 0);
    }
    const productRows = Array.from(byProduct.values());

    return (
        <UserLayout>
            <Head title="Оформление заказа" />
            <Breadcrumbs items={breadcrumbs} />

            <Box>
                <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold" mb="6">
                    Оформление заказа
                </Heading>

                {/* Товары */}
                <Box
                    bg="bg"
                    borderWidth="1px"
                    borderColor="border"
                    rounded="lg"
                    p={{ base: '3', md: '5' }}
                    mb="4"
                >
                    <Text fontWeight="600" fontSize="lg" mb="3">
                        Товары ({totalQty} шт.)
                    </Text>

                    <Box overflowX="auto">
                        <Table.Root size="sm" variant="outline">
                            <Table.Header>
                                <Table.Row bg="bg.muted">
                                    <Table.ColumnHeader>Название</Table.ColumnHeader>
                                    <Table.ColumnHeader w="100px" textAlign="center">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader w="120px" textAlign="right">Сумма ({currencySymbol})</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {productRows.map((row) => {
                                    const pid = Number(row.product?.id);
                                    const totalQtyRow = row.instockQty + row.preorderQty;

                                    return (
                                        <Table.Row key={pid}>
                                            <Table.Cell>
                                                <Text fontWeight="medium" lineClamp={1}>
                                                    {row.product?.name || 'Товар'}
                                                </Text>
                                                <Flex gap="1" mt="0.5">
                                                    {row.product?.brand?.name && (
                                                        <Text fontSize="xs" color="fg.muted">
                                                            {row.product.brand.name}
                                                        </Text>
                                                    )}
                                                    {row.product?.sku && (
                                                        <Text fontSize="xs" color="fg.muted">
                                                            • {row.product.sku}
                                                        </Text>
                                                    )}
                                                </Flex>
                                                {row.preorderQty > 0 && (
                                                    <Badge colorPalette="orange" variant="subtle" fontSize="2xs" mt="1">
                                                        Предзаказ: {row.preorderQty} шт
                                                    </Badge>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">{totalQtyRow}</Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontWeight="medium">
                                                    {row.totalAmount.toLocaleString('ru-RU')}
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    <Separator my="3" />

                    {/* Итого */}
                    <Flex justify="flex-end" gap="4" align="center">
                        {hasDiscount && (
                            <Text fontSize="sm" color="fg.muted" textDecoration="line-through">
                                {totalRegular.toLocaleString('ru-RU')} {currencySymbol}
                            </Text>
                        )}
                        <Text fontSize="xl" fontWeight="bold">
                            {totalDiscounted.toLocaleString('ru-RU')} {currencySymbol}
                        </Text>
                    </Flex>
                </Box>

                {/* Заглушка формы */}
                <Box
                    bg="bg"
                    borderWidth="1px"
                    borderColor="border"
                    rounded="lg"
                    p={{ base: '4', md: '6' }}
                    textAlign="center"
                >
                    <Flex direction="column" align="center" gap="3" py="6">
                        <LuConstruction size={48} color="var(--chakra-colors-fg-muted)" />
                        <Heading as="h2" size={{ base: 'md', md: 'lg' }} fontWeight="bold">
                            Раздел в разработке
                        </Heading>
                        <Text color="fg.muted" maxW="400px">
                            Функционал оформления заказа находится в разработке.
                            Вы сможете выбрать способ доставки и оплаты в ближайшее время.
                        </Text>
                    </Flex>
                </Box>

                {/* Кнопка «Назад» */}
                <Flex mt="4" justify="center">
                    <Button asChild variant="outline" size="md">
                        <Link href="/cart">
                            <LuArrowLeft size={16} />
                            Вернуться в корзину
                        </Link>
                    </Button>
                </Flex>
            </Box>
        </UserLayout>
    );
}
