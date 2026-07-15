import { Head, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Badge, Box, HStack, Image, Text } from '@chakra-ui/react';

export default function Index() {
    const { managers } = usePage().props;

    const columns = [
        {
            key: 'name',
            label: 'Менеджер',
            render: (_, row) => (
                <HStack gap={3}>
                    {row.photo_url
                        ? <Image src={row.photo_url} alt={row.name} w="32px" h="32px" borderRadius="full" objectFit="cover" />
                        : <Box w="32px" h="32px" borderRadius="full" bg="bg.muted" />}
                    <Text fontWeight="semibold">{row.name}</Text>
                </HStack>
            ),
        },
        {
            key: 'clients_count',
            label: 'Клиентов',
            render: (_, row) => <Text fontSize="sm">{row.clients_count}</Text>,
        },
        {
            key: 'phone',
            label: 'Телефон',
            render: (_, row) => <Text fontSize="sm">{row.phone || '—'}</Text>,
        },
        {
            key: 'email',
            label: 'Email',
            render: (_, row) => <Text fontSize="sm">{row.email || '—'}</Text>,
        },
        {
            key: 'account',
            label: 'Аккаунт в CRM',
            render: (_, row) => (row.account
                ? <Text fontSize="sm">{row.account.email}</Text>
                : <Badge colorPalette="orange" variant="subtle">Нет доступа</Badge>),
        },
        {
            key: 'has_erp_uuid',
            label: 'Источник',
            render: (_, row) => (
                <Badge colorPalette={row.has_erp_uuid ? 'blue' : 'gray'} variant="subtle">
                    {row.has_erp_uuid ? 'Из 1С' : 'Создан на сайте'}
                </Badge>
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Команда" />
            <PageHeader
                title="Команда"
                description="Персональные менеджеры отдела продаж и их аккаунты"
            />

            <DataTable data={managers} columns={columns} />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
