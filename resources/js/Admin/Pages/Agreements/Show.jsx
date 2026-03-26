import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable } from '@/Admin/Components';
import { Box, Text, Badge, Card, SimpleGrid, Stack } from '@chakra-ui/react';
import { LuArrowLeft } from 'react-icons/lu';

export default function Show({ agreement }) {
    const columns = [
        {
            key: 'id',
            label: 'ID',
            width: '80px',
        },
        {
            key: 'name',
            label: 'Название скидки (правила)',
        },
        {
            key: 'percentage',
            label: 'Процент',
            render: (percentage) => (
                <Badge colorPalette="green">{percentage}%</Badge>
            ),
        },
        {
            key: 'product_segment',
            label: 'Сегмент товаров',
            render: (_, row) => (
                <Text fontSize="sm">
                    {row.product_segment ? row.product_segment.name : 'На весь ассортимент'}
                </Text>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title={`Соглашение: ${agreement.name}`}
                description="Просмотр индивидуального соглашения из 1С"
                backUrl={route('admin.agreements.index')}
            />

            <SimpleGrid columns={{ base: 1, md: 2 }} gap={6} mb={8}>
                <Card.Root>
                    <Card.Header>
                        <Card.Title>Основная информация</Card.Title>
                    </Card.Header>
                    <Card.Body>
                        <Stack gap={3}>
                            <Box>
                                <Text fontSize="sm" color="fg.muted">Название</Text>
                                <Text fontWeight="medium">{agreement.name}</Text>
                            </Box>
                            <Box>
                                <Text fontSize="sm" color="fg.muted">UUID</Text>
                                <Text fontFamily="mono" fontSize="sm">{agreement.uuid}</Text>
                            </Box>
                            <Box>
                                <Text fontSize="sm" color="fg.muted">Статус</Text>
                                <Badge colorPalette={agreement.is_active ? 'green' : 'gray'}>
                                    {agreement.is_active ? 'Активно' : 'Неактивно'}
                                </Badge>
                            </Box>
                            <Box>
                                <Text fontSize="sm" color="fg.muted">Срок действия</Text>
                                <Text>
                                    {agreement.starts_at || '...'} — {agreement.ends_at || '...'}
                                </Text>
                            </Box>
                        </Stack>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Card.Title>Партнёр</Card.Title>
                    </Card.Header>
                    <Card.Body>
                        <Stack gap={3}>
                            {agreement.user ? (
                                <>
                                    <Box>
                                        <Text fontSize="sm" color="fg.muted">ФИО</Text>
                                        <Text fontWeight="medium">{agreement.user.name}</Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="sm" color="fg.muted">Email</Text>
                                        <Text>{agreement.user.email}</Text>
                                    </Box>
                                </>
                            ) : (
                                <Text color="fg.muted">Пользователь не найден</Text>
                            )}
                        </Stack>
                    </Card.Body>
                </Card.Root>
            </SimpleGrid>

            <Card.Root>
                <Card.Header>
                    <Card.Title>Вложенные скидки ({agreement.discounts.length})</Card.Title>
                </Card.Header>
                <Box overflowX="auto">
                    <DataTable
                        data={agreement.discounts}
                        columns={columns}
                        pagination={false}
                    />
                </Box>
            </Card.Root>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
