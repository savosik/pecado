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
    const { personalManager } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Менеджер: ${personalManager.name}`} />
            <PageHeader
                title={personalManager.name}
                backUrl={route('admin.personal-managers.index')}
                backLabel="К списку менеджеров"
                actions={
                    can('personal-managers.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.personal-managers.edit', personalManager.id))}>
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
                        <HStack gap={6} align="start">
                            {personalManager.photo_url && (
                                <Image
                                    src={personalManager.photo_url}
                                    alt={personalManager.name}
                                    w="100px"
                                    h="100px"
                                    objectFit="cover"
                                    borderRadius="full"
                                    borderWidth="1px"
                                    borderColor="gray.200"
                                    flexShrink={0}
                                />
                            )}
                            <SimpleGrid columns={{ base: 2, md: 3 }} gap={4} flex={1}>
                                <InfoRow label="ID" value={personalManager.id?.toString()} />
                                <InfoRow label="Имя" value={personalManager.name} />
                                <InfoRow label="Телефон" value={personalManager.phone} />
                                <InfoRow label="Email" value={personalManager.email} />
                                <InfoRow label="Кол-во клиентов" value={personalManager.users_count?.toString()} />
                                <InfoRow label="Создан" value={personalManager.created_at} />
                                <InfoRow label="Обновлён" value={personalManager.updated_at} />
                            </SimpleGrid>
                        </HStack>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
