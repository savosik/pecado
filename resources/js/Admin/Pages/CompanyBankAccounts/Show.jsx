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
    const { bankAccount } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Банковский счёт: ${bankAccount.bank_name}`} />
            <PageHeader
                title={`${bankAccount.bank_name} — ${bankAccount.account_number}`}
                backUrl={route('admin.company-bank-accounts.index')}
                backLabel="К списку банковских счетов"
                actions={
                    can('company-bank-accounts.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.company-bank-accounts.edit', bankAccount.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Реквизиты банка</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={bankAccount.id?.toString()} />
                            <InfoRow label="Банк" value={bankAccount.bank_name} />
                            <InfoRow label="БИК" value={bankAccount.bank_bik} />
                            <InfoRow label="Корр. счёт" value={bankAccount.correspondent_account} />
                            <InfoRow label="Расч. счёт" value={bankAccount.account_number} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Основной счёт</Text>
                                <Badge colorPalette={bankAccount.is_primary ? 'green' : 'gray'} variant="subtle">
                                    {bankAccount.is_primary ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Создан" value={bankAccount.created_at} />
                            <InfoRow label="Обновлён" value={bankAccount.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {bankAccount.company && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Компания</Text></Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={2} gap={4}>
                                <InfoRow label="Название" value={bankAccount.company.name} />
                                <InfoRow label="Владелец" value={bankAccount.company.user?.name} />
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
