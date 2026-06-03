import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Text, VStack, HStack, Badge } from '@chakra-ui/react';
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
    const { region } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Регион: ${region.name}`} />
            <PageHeader
                title={region.name}
                backUrl={route('admin.regions.index')}
                backLabel="К списку регионов"
                actions={
                    can('regions.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.regions.edit', region.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={region.id?.toString()} />
                            <InfoRow label="Название" value={region.name} />
                            <InfoRow label="Валюта" value={region.currency ? `${region.currency.code} — ${region.currency.name} (${region.currency.symbol})` : null} />
                            <InfoRow label="Создан" value={region.created_at} />
                            <InfoRow label="Обновлён" value={region.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {region.primary_warehouses?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Основные склады</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {region.primary_warehouses.map((w) => (
                                    <Badge key={w.id} variant="outline">{w.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {region.preorder_warehouses?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Склады предзаказа</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {region.preorder_warehouses.map((w) => (
                                    <Badge key={w.id} variant="outline">{w.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
