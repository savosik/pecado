import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { export: exportData } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Выгрузка: ${exportData.name}`} />
            <PageHeader
                title={exportData.name}
                backUrl={route('admin.product-exports.index')}
                backLabel="К списку выгрузок"
                actions={
                    can('product-exports.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.product-exports.edit', exportData.id))}>
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
                            <InfoRow label="ID" value={exportData.id?.toString()} />
                            <InfoRow label="Название" value={exportData.name} />
                            <InfoRow label="Формат" value={exportData.format?.toUpperCase()} />
                            <InfoRow label="Клиент" value={exportData.client_user ? `${exportData.client_user.name} (${exportData.client_user.email})` : null} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Активна</Text>
                                <Badge colorPalette={exportData.is_active ? 'green' : 'gray'} variant="subtle">
                                    {exportData.is_active ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Создана" value={exportData.created_at} />
                            <InfoRow label="Обновлена" value={exportData.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {exportData.fields?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Поля ({exportData.fields.length})</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {exportData.fields.map((f, i) => (
                                    <Badge key={i} variant="subtle">{f}</Badge>
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
