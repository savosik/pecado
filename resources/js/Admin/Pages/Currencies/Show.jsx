import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { currency } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Валюта: ${currency.code}`} />
            <PageHeader
                title={`${currency.code} — ${currency.name}`}
                backUrl={route('admin.currencies.index')}
                backLabel="К списку валют"
                actions={
                    can('currencies.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.currencies.edit', currency.id))}>
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
                            <InfoRow label="ID" value={currency.id?.toString()} />
                            <InfoRow label="Код" value={currency.code} />
                            <InfoRow label="Название" value={currency.name} />
                            <InfoRow label="Символ" value={currency.symbol} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Базовая</Text>
                                <Badge colorPalette={currency.is_base ? 'green' : 'gray'} variant="subtle">
                                    {currency.is_base ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Официальный курс" value={currency.official_rate?.toString()} />
                            <InfoRow label="Коэффициент" value={currency.rate_coefficient?.toString()} />
                            <InfoRow label="Обменный курс" value={currency.exchange_rate?.toString()} />
                            <InfoRow label="Дата курса" value={currency.exchange_rate_date} />
                            <InfoRow label="Создана" value={currency.created_at} />
                            <InfoRow label="Обновлена" value={currency.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
