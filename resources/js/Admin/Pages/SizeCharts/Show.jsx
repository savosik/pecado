import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
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
    const { sizeChart } = usePage().props;
    const { can } = usePermission();

    const columns = sizeChart.values?.length > 0
        ? Object.keys(sizeChart.values[0])
        : [];

    return (
        <>
            <Head title={`Размерная сетка: ${sizeChart.name}`} />
            <PageHeader
                title={sizeChart.name}
                backUrl={route('admin.size-charts.index')}
                backLabel="К списку размерных сеток"
                actions={
                    can('size-charts.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.size-charts.edit', sizeChart.id))}>
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
                            <InfoRow label="ID" value={sizeChart.id?.toString()} />
                            <InfoRow label="Название" value={sizeChart.name} />
                            <InfoRow label="UUID" value={sizeChart.uuid} />
                            <InfoRow label="Создан" value={sizeChart.created_at} />
                            <InfoRow label="Обновлён" value={sizeChart.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {sizeChart.brands?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Бренды</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {sizeChart.brands.map((b) => (
                                    <Badge key={b.id} variant="outline">{b.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {sizeChart.values?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Таблица размеров</Text>
                        </Card.Header>
                        <Card.Body overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        {columns.map((col) => (
                                            <Table.ColumnHeader key={col}>{col}</Table.ColumnHeader>
                                        ))}
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {sizeChart.values.map((row, i) => (
                                        <Table.Row key={i}>
                                            {columns.map((col) => (
                                                <Table.Cell key={col}>{row[col]}</Table.Cell>
                                            ))}
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
