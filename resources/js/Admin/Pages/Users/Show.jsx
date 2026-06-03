import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

const STATUS_COLORS = {
    active: 'green',
    inactive: 'gray',
    pending: 'yellow',
    banned: 'red',
};

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { user } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Пользователь: ${user.name || user.email}`} />
            <PageHeader
                title={user.name || user.email}
                backUrl={route('admin.users.index')}
                backLabel="К списку пользователей"
                actions={
                    can('users.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.users.edit', user.id))}>
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
                            <InfoRow label="ID" value={user.id?.toString()} />
                            <InfoRow label="Имя" value={user.name} />
                            <InfoRow label="Email" value={user.email} />
                            <InfoRow label="Телефон" value={user.phone} />
                            <InfoRow label="Страна" value={user.country} />
                            <InfoRow label="Город" value={user.city} />
                            <InfoRow label="Регион" value={user.region?.name} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Статус</Text>
                                <Badge colorPalette={STATUS_COLORS[user.status] || 'gray'} variant="subtle">
                                    {user.status || '—'}
                                </Badge>
                            </Box>
                            <InfoRow label="ERP ID" value={user.erp_id} />
                            <InfoRow label="Статус клиента" value={user.client_status?.name} />
                            <InfoRow label="Персональный менеджер" value={user.personal_manager?.name} />
                            <InfoRow label="Кол-во компаний" value={user.companies_count?.toString()} />
                            <InfoRow label="Кол-во адресов" value={user.delivery_addresses_count?.toString()} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Анкета заполнена</Text>
                                <Badge colorPalette={user.has_questionnaire ? 'green' : 'gray'} variant="subtle">
                                    {user.has_questionnaire ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Зарегистрирован" value={user.created_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {user.roles?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Роли</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {user.roles.map((r, i) => (
                                    <Badge key={i} colorPalette="blue" variant="subtle">{r}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {user.comment && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Комментарий</Text></Card.Header>
                        <Card.Body>
                            <Text fontSize="sm">{user.comment}</Text>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
