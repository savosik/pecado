import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Card, Image, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { clientStatus } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Статус клиента: ${clientStatus.name}`} />
            <PageHeader
                title={clientStatus.name}
                backUrl={route('admin.client-statuses.index')}
                backLabel="К списку статусов клиентов"
                actions={
                    can('client-statuses.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.client-statuses.edit', clientStatus.id))}>
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
                            <InfoRow label="ID" value={clientStatus.id?.toString()} />
                            <InfoRow label="Название" value={clientStatus.name} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Цвет</Text>
                                <Box display="flex" alignItems="center" gap={2}>
                                    {clientStatus.color && (
                                        <Box w="20px" h="20px" borderRadius="sm" bg={clientStatus.color} borderWidth="1px" borderColor="gray.300" />
                                    )}
                                    <Text fontSize="sm" fontWeight="500">{clientStatus.color || '—'}</Text>
                                </Box>
                            </Box>
                            <InfoRow label="Сумма от (₽)" value={clientStatus.amount_from?.toString()} />
                            <InfoRow label="Внешний ID" value={clientStatus.external_id} />
                            <InfoRow label="Создан" value={clientStatus.created_at} />
                            <InfoRow label="Обновлён" value={clientStatus.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {clientStatus.description && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Описание</Text></Card.Header>
                        <Card.Body>
                            <Text fontSize="sm">{clientStatus.description}</Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {clientStatus.image_url && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображение</Text></Card.Header>
                        <Card.Body>
                            <Image src={clientStatus.image_url} alt={clientStatus.name} w="120px" h="120px" objectFit="contain" borderRadius="md" borderWidth="1px" borderColor="gray.200" />
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
