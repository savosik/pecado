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
    const { productModel } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Модель: ${productModel.name}`} />
            <PageHeader
                title={productModel.name}
                backUrl={route('admin.product-models.index')}
                backLabel="К списку моделей"
                actions={
                    can('product-models.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.product-models.edit', productModel.id))}>
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
                            <InfoRow label="ID" value={productModel.id?.toString()} />
                            <InfoRow label="Название" value={productModel.name} />
                            <InfoRow label="Код" value={productModel.code} />
                            <InfoRow label="Внешний ID" value={productModel.external_id} />
                            <InfoRow label="Кол-во товаров" value={productModel.products_count?.toString()} />
                            <InfoRow label="Создан" value={productModel.created_at} />
                            <InfoRow label="Обновлён" value={productModel.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
