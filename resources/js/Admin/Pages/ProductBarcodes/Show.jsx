import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Card, HStack, Image, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { productBarcode } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Штрихкод: ${productBarcode.barcode}`} />
            <PageHeader
                title={`Штрихкод: ${productBarcode.barcode}`}
                backUrl={route('admin.product-barcodes.index')}
                backLabel="К списку штрихкодов"
                actions={
                    can('product-barcodes.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.product-barcodes.edit', productBarcode.id))}>
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
                            <InfoRow label="ID" value={productBarcode.id?.toString()} />
                            <InfoRow label="Штрихкод" value={productBarcode.barcode} />
                            <InfoRow label="Создан" value={productBarcode.created_at} />
                            <InfoRow label="Обновлён" value={productBarcode.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {productBarcode.product && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Товар</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={4}>
                                {productBarcode.product.image_url && (
                                    <Image
                                        src={productBarcode.product.image_url}
                                        alt={productBarcode.product.name}
                                        w="80px"
                                        h="80px"
                                        objectFit="contain"
                                        borderRadius="md"
                                        borderWidth="1px"
                                        borderColor="gray.200"
                                    />
                                )}
                                <SimpleGrid columns={2} gap={3}>
                                    <InfoRow label="Название" value={productBarcode.product.name} />
                                    <InfoRow label="Артикул" value={productBarcode.product.sku} />
                                    <InfoRow label="Бренд" value={productBarcode.product.brand_name} />
                                </SimpleGrid>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
