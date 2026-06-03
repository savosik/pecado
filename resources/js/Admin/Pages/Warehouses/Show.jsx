import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { warehouse } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Склад: ${warehouse.name}`} />
            <PageHeader
                title={warehouse.name}
                backUrl={route('admin.warehouses.index')}
                backLabel="К списку складов"
                actions={
                    can('warehouses.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.warehouses.edit', warehouse.id))}>
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
                            <InfoRow label="ID" value={warehouse.id?.toString()} />
                            <InfoRow label="Название" value={warehouse.name} />
                            <InfoRow label="Внешний ID" value={warehouse.external_id} />
                            <InfoRow label="Создан" value={warehouse.created_at} />
                            <InfoRow label="Обновлён" value={warehouse.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
