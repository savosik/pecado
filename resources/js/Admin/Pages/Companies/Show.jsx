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
    const { company } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Компания: ${company.name}`} />
            <PageHeader
                title={company.name}
                backUrl={route('admin.companies.index')}
                backLabel="К списку компаний"
                actions={
                    can('companies.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.companies.edit', company.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Реквизиты</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={company.id?.toString()} />
                            <InfoRow label="Название" value={company.name} />
                            <InfoRow label="Юридическое название" value={company.legal_name} />
                            <InfoRow label="Страна" value={company.country} />
                            <InfoRow label="ИНН" value={company.tax_id} />
                            <InfoRow label="ОГРН/Рег. номер" value={company.registration_number} />
                            <InfoRow label="КПП" value={company.tax_code} />
                            <InfoRow label="ОКПО" value={company.okpo_code} />
                            <InfoRow label="ERP ID" value={company.erp_id} />
                            <InfoRow label="Банк. счетов" value={company.bank_accounts_count?.toString()} />
                            <InfoRow label="Создана" value={company.created_at} />
                            <InfoRow label="Обновлена" value={company.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Контактные данные</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="Телефон" value={company.phone} />
                            <InfoRow label="Email" value={company.email} />
                            <InfoRow label="Юридический адрес" value={company.legal_address} />
                            <InfoRow label="Фактический адрес" value={company.actual_address} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {company.user && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Владелец</Text></Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={2} gap={4}>
                                <InfoRow label="Имя" value={company.user.name} />
                                <InfoRow label="Email" value={company.user.email} />
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
