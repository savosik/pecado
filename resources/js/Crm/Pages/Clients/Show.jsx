import { Head, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Box, Card, SimpleGrid, Text, VStack, Badge } from '@chakra-ui/react';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { client } = usePage().props;

    return (
        <>
            <Head title={`CRM — ${client.name}`} />
            <PageHeader
                title={client.name}
                description="Карточка клиента"
            />

            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={client.id?.toString()} />
                            <InfoRow label="Email" value={client.email} />
                            <InfoRow label="Телефон" value={client.phone} />
                            <InfoRow label="Город" value={client.city} />
                            <InfoRow label="Страна" value={client.country} />
                            <InfoRow label="Статус" value={client.status_label} />
                            <InfoRow label="Персональный менеджер" value={client.manager?.name} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Статус клиента</Text>
                                {client.client_status
                                    ? <Badge colorPalette="gray" variant="subtle">{client.client_status.name}</Badge>
                                    : <Text fontSize="sm" fontWeight="500">—</Text>}
                            </Box>
                            <InfoRow label="Зарегистрирован" value={client.created_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" color="fg.muted">
                            Раздел в разработке: здесь появятся заказы клиента, история общения и задачи.
                        </Text>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
