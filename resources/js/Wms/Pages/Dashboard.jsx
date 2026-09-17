import { Head, Link, usePage } from '@inertiajs/react';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Box, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuWarehouse, LuPackage, LuLayers, LuPackageX, LuTruck, LuPackageCheck } from 'react-icons/lu';

const formatNumber = (value) => new Intl.NumberFormat('ru-RU').format(value ?? 0);

function StatCard({ label, value, icon: Icon }) {
    return (
        <Card.Root>
            <Card.Body>
                <VStack align="start" gap={2}>
                    <Box color="fg.muted">
                        <Icon size={20} />
                    </Box>
                    <Text fontSize="xs" color="fg.muted">{label}</Text>
                    <Text fontSize="2xl" fontWeight="bold">{formatNumber(value)}</Text>
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}

function WarehouseCard({ warehouse }) {
    return (
        <Card.Root>
            <Card.Header pb={2}>
                <HStack gap={2}>
                    <Box color="fg.muted"><LuWarehouse size={18} /></Box>
                    <Text fontWeight="semibold">{warehouse.name}</Text>
                </HStack>
            </Card.Header>
            <Card.Body pt={0}>
                <SimpleGrid columns={{ base: 2, sm: 3 }} gap={3}>
                    <Box>
                        <Text fontSize="xs" color="fg.muted">Позиций в наличии</Text>
                        <Text fontSize="lg" fontWeight="bold">{formatNumber(warehouse.positions_in_stock)}</Text>
                    </Box>
                    <Box>
                        <Text fontSize="xs" color="fg.muted">Всего единиц</Text>
                        <Text fontSize="lg" fontWeight="bold">{formatNumber(warehouse.units_total)}</Text>
                    </Box>
                    <Box>
                        <Text fontSize="xs" color="fg.muted">Позиций с нулём</Text>
                        <Text fontSize="lg" fontWeight="bold" color="fg.muted">
                            {formatNumber(warehouse.positions_zero)}
                        </Text>
                    </Box>
                </SimpleGrid>
            </Card.Body>
        </Card.Root>
    );
}

export default function Dashboard() {
    const { warehouses, totals, isWarehouseHead, goodsIssues, pickups, auth } = usePage().props;

    return (
        <>
            <Head title="Склад — Рабочий стол" />
            <PageHeader
                title={`Здравствуйте, ${auth?.user?.name || 'коллега'}`}
                description={isWarehouseHead ? 'Обзор по складскому хозяйству' : 'Обзор по складам'}
            />

            <VStack gap={4} align="stretch">
                <SimpleGrid columns={{ base: 2, md: 3 }} gap={{ base: 3, md: 4 }}>
                    <StatCard label="Складов" value={totals.warehouses} icon={LuWarehouse} />
                    <StatCard label="Позиций в наличии" value={totals.positions_in_stock} icon={LuPackage} />
                    <StatCard label="Всего единиц товара" value={totals.units_total} icon={LuLayers} />
                </SimpleGrid>

                {pickups && (
                    <Link href="/wms/pickups">
                        <Card.Root _hover={{ borderColor: 'colorPalette.solid' }} borderColor={pickups.awaiting > 0 ? 'green.muted' : undefined}>
                            <Card.Body>
                                <HStack justify="space-between" flexWrap="wrap" gap={3}>
                                    <HStack gap={2}>
                                        <Box color="green.fg"><LuPackageCheck size={20} /></Box>
                                        <Text fontWeight="600">Выдача заказов</Text>
                                    </HStack>
                                    <HStack gap={5}>
                                        <VStack gap={0} align="flex-end">
                                            <Text fontSize="xl" fontWeight="bold">{formatNumber(pickups.awaiting)}</Text>
                                            <Text fontSize="xs" color="fg.muted">ждут курьера</Text>
                                        </VStack>
                                        <VStack gap={0} align="flex-end">
                                            <Text fontSize="xl" fontWeight="bold" color={pickups.picking_overdue > 0 ? 'red.500' : undefined}>{formatNumber(pickups.picking_overdue)}</Text>
                                            <Text fontSize="xs" color="fg.muted">сборок опаздывает</Text>
                                        </VStack>
                                        <VStack gap={0} align="flex-end">
                                            <Text fontSize="xl" fontWeight="bold" color={pickups.stale + pickups.review > 0 ? 'orange.500' : undefined}>{formatNumber(pickups.stale + pickups.review)}</Text>
                                            <Text fontSize="xs" color="fg.muted">зависло и на разборе</Text>
                                        </VStack>
                                    </HStack>
                                </HStack>
                            </Card.Body>
                        </Card.Root>
                    </Link>
                )}

                {goodsIssues && (
                    <Link href="/wms/goods-issues">
                        <Card.Root _hover={{ borderColor: 'colorPalette.solid' }}>
                            <Card.Body>
                                <HStack justify="space-between" flexWrap="wrap" gap={3}>
                                    <HStack gap={2}>
                                        <Box color="fg.muted"><LuTruck size={20} /></Box>
                                        <Text fontSize="sm" fontWeight="semibold">Расходные ордера</Text>
                                    </HStack>
                                    <HStack gap={6}>
                                        <VStack align="end" gap={0}>
                                            <Text fontSize="xs" color="fg.muted">В работе</Text>
                                            <Text fontSize="xl" fontWeight="bold">{formatNumber(goodsIssues.active)}</Text>
                                        </VStack>
                                        <VStack align="end" gap={0}>
                                            <Text fontSize="xs" color="fg.muted">Зависших</Text>
                                            <Text
                                                fontSize="xl"
                                                fontWeight="bold"
                                                color={goodsIssues.stale > 0 ? 'orange.500' : undefined}
                                            >
                                                {formatNumber(goodsIssues.stale)}
                                            </Text>
                                        </VStack>
                                    </HStack>
                                </HStack>
                            </Card.Body>
                        </Card.Root>
                    </Link>
                )}

                <Box>
                    <Text fontSize="sm" fontWeight="semibold" mb={3}>Остатки по складам</Text>
                    {warehouses.length === 0 ? (
                        <Card.Root>
                            <Card.Body>
                                <HStack gap={2} color="fg.muted">
                                    <LuPackageX size={18} />
                                    <Text fontSize="sm">Склады не заведены. Их создаёт администратор в разделе «Склады».</Text>
                                </HStack>
                            </Card.Body>
                        </Card.Root>
                    ) : (
                        <SimpleGrid columns={{ base: 1, lg: 2 }} gap={4}>
                            {warehouses.map((warehouse) => (
                                <WarehouseCard key={warehouse.id} warehouse={warehouse} />
                            ))}
                        </SimpleGrid>
                    )}
                </Box>

                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" color="fg.muted">
                            Раздел в разработке: здесь появятся приёмка, отбор заказов и инвентаризация.
                            Сейчас доступна сводка остатков по складам.
                        </Text>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Dashboard.layout = (page) => <WmsLayout>{page}</WmsLayout>;
