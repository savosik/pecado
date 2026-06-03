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
    const { tag } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Тег: ${tag.display_name}`} />
            <PageHeader
                title={tag.display_name}
                backUrl={route('admin.tags.index')}
                backLabel="К списку тегов"
                actions={
                    can('tags.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.tags.edit', tag.id))}>
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
                            <InfoRow label="ID" value={tag.id?.toString()} />
                            <InfoRow label="Название" value={tag.display_name} />
                            <InfoRow label="Тип" value={tag.type} />
                            <InfoRow label="Порядок" value={tag.order_column?.toString()} />
                            <InfoRow label="Создан" value={tag.created_at} />
                            <InfoRow label="Обновлён" value={tag.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
