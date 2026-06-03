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
    const { individualPrice, labels } = usePage().props;
    const { can } = usePermission();

    const editUrl = route('admin.individual-prices.edit', {
        partner_id: individualPrice.partner_id,
        product_id: individualPrice.product_id,
        warehouse_id: individualPrice.warehouse_id,
    });

    return (
        <>
            <Head title="Индивидуальная цена" />
            <PageHeader
                title="Индивидуальная цена"
                backUrl={route('admin.individual-prices.index', { partner_id: individualPrice.partner_id })}
                backLabel="К списку цен"
                actions={
                    can('individual-prices.edit') && (
                        <Button size="sm" onClick={() => router.visit(editUrl)}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Индивидуальная цена</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="Партнёр" value={labels?.partner} />
                            <InfoRow label="Email партнёра" value={labels?.partner_email} />
                            <InfoRow label="Товар" value={labels?.product} />
                            <InfoRow label="Склад" value={labels?.warehouse} />
                            <InfoRow label="Цена" value={individualPrice.price ? `${individualPrice.price} ₽` : null} />
                            <InfoRow label="Обновлена" value={individualPrice.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
