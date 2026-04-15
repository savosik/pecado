import { useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Box, Flex, Text, Heading, Button, Table, Badge, Separator, Stack,
} from '@chakra-ui/react';
import { LuArrowLeft, LuPackage, LuWarehouse, LuShoppingBag } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import { toaster } from '@/components/ui/toaster';

const STATUS_LABELS = {
    pending: 'Ожидает',
    confirmed: 'Подтверждён',
    ready_to_ship: 'К отгрузке',
    closed: 'Закрыт',
};

const STATUS_COLORS = {
    pending: 'yellow',
    confirmed: 'blue',
    ready_to_ship: 'purple',
    closed: 'green',
};

const TYPE_LABELS = {
    standard: 'Стандартный',
    in_stock: 'Со склада',
    preorder: 'Предзаказ',
};

/**
 * Страница просмотра заказа.
 *
 * Props от OrderController@show:
 *   - order: { id, uuid, status, type, comment, total_amount, currency_code, created_at,
 *              company, delivery_address, children: [ { type, status, total_amount, items } ] }
 */
export default function OrderShow({ order }) {
    const { currency, flash } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Заказ №' + order.id },
    ];

    // Показать toast при ?congrats=1 или flash.success
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('congrats') === '1' || flash?.success) {
            toaster.create({
                title: 'Заказ оформлен!',
                description: flash?.success || 'Ваш заказ успешно оформлен и принят в обработку.',
                type: 'success',
                duration: 6000,
            });
            // Убрать ?congrats из URL без перезагрузки
            if (params.get('congrats')) {
                params.delete('congrats');
                const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                window.history.replaceState({}, '', newUrl);
            }
        }
    }, []);

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const instockChild = (order.children || []).find(c => c.type === 'in_stock');
    const preorderChild = (order.children || []).find(c => c.type === 'preorder');

    const createdAt = order.created_at
        ? new Date(order.created_at).toLocaleDateString('ru-RU', {
            year: 'numeric', month: 'long', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
        })
        : '—';

    return (
        <UserLayout>
            <Head title={`Заказ №${order.id}`} />
            <Breadcrumbs items={breadcrumbs} />

            <Box>
                <Flex align="center" gap="3" mb="6" flexWrap="wrap">
                    <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold">
                        Заказ №{order.id}
                    </Heading>
                    <Badge
                        colorPalette={STATUS_COLORS[order.status] ?? 'gray'}
                        variant="subtle"
                        fontSize="sm"
                        px="3"
                        py="1"
                    >
                        {STATUS_LABELS[order.status] ?? order.status}
                    </Badge>
                </Flex>

                <Stack gap="4">
                    {/* ═══ Информация о заказе ═══ */}
                    <Box
                        bg="bg"
                        borderWidth="1px"
                        borderColor="border"
                        rounded="lg"
                        p={{ base: '4', md: '5' }}
                    >
                        <Stack gap="2">
                            <InfoRow label="Дата" value={createdAt} />
                            {order.company && (
                                <InfoRow
                                    label="Компания"
                                    value={`${order.company.name}${order.company.legal_name ? ` (${order.company.legal_name})` : ''}`}
                                />
                            )}
                            {order.delivery_address && (
                                <InfoRow
                                    label="Адрес доставки"
                                    value={order.delivery_address}
                                />
                            )}
                            {order.comment && (
                                <InfoRow label="Комментарий" value={order.comment} />
                            )}
                        </Stack>
                    </Box>

                    {/* ═══ Товары со склада ═══ */}
                    {instockChild && instockChild.items?.length > 0 && (
                        <ChildOrderTable
                            title="Товары со склада"
                            icon={<LuWarehouse size={20} />}
                            childOrder={instockChild}
                            currencySymbol={currencySymbol}
                            colorPalette="green"
                            fmt={fmt}
                        />
                    )}

                    {/* ═══ Товары по предзаказу ═══ */}
                    {preorderChild && preorderChild.items?.length > 0 && (
                        <ChildOrderTable
                            title="Товары по предзаказу"
                            icon={<LuPackage size={20} />}
                            childOrder={preorderChild}
                            currencySymbol={currencySymbol}
                            colorPalette="orange"
                            fmt={fmt}
                        />
                    )}

                    {/* ═══ Итого ═══ */}
                    <Box
                        bg="bg"
                        borderWidth="1px"
                        borderColor="border"
                        rounded="lg"
                        p={{ base: '4', md: '5' }}
                    >
                        <Flex justify="space-between" align="center">
                            <Flex align="center" gap="2">
                                <LuShoppingBag size={20} />
                                <Text fontWeight="600" fontSize="lg">Итого</Text>
                            </Flex>
                            <Text fontSize="xl" fontWeight="bold">
                                {fmt(order.total_amount)} {currencySymbol}
                            </Text>
                        </Flex>
                    </Box>

                    {/* ═══ Навигация ═══ */}
                    <Flex justify="center">
                        <Button asChild variant="outline" size="md">
                            <Link href="/">
                                <LuArrowLeft size={16} />
                                На главную
                            </Link>
                        </Button>
                    </Flex>
                </Stack>
            </Box>
        </UserLayout>
    );
}

function InfoRow({ label, value }) {
    return (
        <Flex gap="2" direction={{ base: 'column', sm: 'row' }}>
            <Text fontWeight="600" minW="150px" color="fg.muted" fontSize="sm">
                {label}:
            </Text>
            <Text fontSize="sm">{value}</Text>
        </Flex>
    );
}

function ChildOrderTable({ title, icon, childOrder, currencySymbol, colorPalette, fmt }) {
    const items = childOrder.items || [];
    const totalQty = items.reduce((s, it) => s + Number(it.quantity || 0), 0);

    return (
        <Box
            bg="bg"
            borderWidth="1px"
            borderColor="border"
            rounded="lg"
            p={{ base: '3', md: '5' }}
        >
            <Flex align="center" gap="2" mb="3">
                {icon}
                <Text fontWeight="600" fontSize="lg">{title}</Text>
                <Badge colorPalette={colorPalette} variant="subtle" ml="1">
                    {totalQty} шт.
                </Badge>
                <Badge
                    colorPalette={STATUS_COLORS[childOrder.status] ?? 'gray'}
                    variant="subtle"
                    ml="auto"
                >
                    {STATUS_LABELS[childOrder.status] ?? childOrder.status}
                </Badge>
            </Flex>

            <Box overflowX="auto">
                <Table.Root size="sm" variant="outline">
                    <Table.Header>
                        <Table.Row bg="bg.muted">
                            <Table.ColumnHeader>Название</Table.ColumnHeader>
                            <Table.ColumnHeader w="90px" textAlign="center">Кол-во</Table.ColumnHeader>
                            <Table.ColumnHeader w="130px" textAlign="right">Цена ({currencySymbol})</Table.ColumnHeader>
                            <Table.ColumnHeader w="130px" textAlign="right">Сумма ({currencySymbol})</Table.ColumnHeader>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {items.map((item) => (
                            <Table.Row key={item.id}>
                                <Table.Cell>
                                    <Text fontWeight="medium" lineClamp={1}>
                                        {item.product?.name || item.name || 'Товар'}
                                    </Text>
                                    <Flex gap="1" mt="0.5">
                                        {item.product?.brand?.name && (
                                            <Text fontSize="xs" color="fg.muted">
                                                {item.product.brand.name}
                                            </Text>
                                        )}
                                        {item.product?.sku && (
                                            <Text fontSize="xs" color="fg.muted">
                                                • {item.product.sku}
                                            </Text>
                                        )}
                                    </Flex>
                                </Table.Cell>
                                <Table.Cell textAlign="center">{item.quantity}</Table.Cell>
                                <Table.Cell textAlign="right">
                                    <Text fontWeight="medium">{fmt(item.price)}</Text>
                                </Table.Cell>
                                <Table.Cell textAlign="right">
                                    <Text fontWeight="medium">{fmt(item.subtotal)}</Text>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>

            <Separator my="3" />

            <Flex justify="flex-end">
                <Text fontSize="lg" fontWeight="bold">
                    {fmt(childOrder.total_amount)} {currencySymbol}
                </Text>
            </Flex>
        </Box>
    );
}
