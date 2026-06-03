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
    const { deliveryAddress } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Адрес доставки: ${deliveryAddress.name}`} />
            <PageHeader
                title={deliveryAddress.name}
                backUrl={route('admin.delivery-addresses.index')}
                backLabel="К списку адресов доставки"
                actions={
                    can('delivery-addresses.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.delivery-addresses.edit', deliveryAddress.id))}>
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
                            <InfoRow label="ID" value={deliveryAddress.id?.toString()} />
                            <InfoRow label="Название" value={deliveryAddress.name} />
                            <InfoRow label="Пользователь" value={deliveryAddress.user ? `${deliveryAddress.user.name} (${deliveryAddress.user.email})` : null} />
                            <InfoRow label="Создан" value={deliveryAddress.created_at} />
                            <InfoRow label="Обновлён" value={deliveryAddress.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header><Text fontWeight="semibold" fontSize="lg">Адрес</Text></Card.Header>
                    <Card.Body>
                        <Text fontSize="sm">{deliveryAddress.address || '—'}</Text>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
