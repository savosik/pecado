import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Badge, Box, Card, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
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
    const { organization } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Организация: ${organization.name}`} />
            <PageHeader
                title={organization.name}
                backUrl={route('admin.organizations.index')}
                backLabel="К списку организаций"
                actions={
                    can('organizations.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.organizations.edit', organization.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />

            <VStack gap={4} align="stretch">
                {organization.is_stub && (
                    <Alert status="warning" title="Организация не заведена вручную">
                        Запись создана автоматически по UUID из сообщения 1С. Заполните название и реквизиты,
                        чтобы клиент видел продавца, а не UUID.
                    </Alert>
                )}

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={organization.id?.toString()} />
                            <InfoRow label="Краткое название" value={organization.name} />
                            <InfoRow label="Юридическое наименование" value={organization.legal_name} />
                            <InfoRow label="UUID в 1С" value={organization.external_id} />
                            <InfoRow label="ИНН" value={organization.tax_id} />
                            <InfoRow label="КПП" value={organization.tax_code} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Статус</Text>
                                <Badge colorPalette={organization.is_active ? 'green' : 'gray'} variant="subtle">
                                    {organization.is_active ? 'Активна' : 'Не активна'}
                                </Badge>
                            </Box>
                            <InfoRow label="Порядок отображения" value={organization.sort_order?.toString()} />
                            <InfoRow label="Создана" value={organization.created_at} />
                            <InfoRow label="Обновлена" value={organization.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Реквизиты для оплаты</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="Банк" value={organization.bank_name} />
                            <InfoRow label="БИК" value={organization.bank_bik} />
                            <InfoRow label="Расчётный счёт" value={organization.account_number} />
                            <InfoRow label="Корреспондентский счёт" value={organization.correspondent_account} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
