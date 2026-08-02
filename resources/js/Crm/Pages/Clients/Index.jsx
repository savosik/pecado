import { Head, router } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Alert } from '@/components/ui/alert';
import { Box, Badge, HStack, Text } from '@chakra-ui/react';
import { LuEye } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

export default function Index({ clients, managers, filters, canSeeAll, managerProfileLinked, lifecycleOptions = [] }) {
    // Раздел read-only: из хука берём только поиск и сортировку,
    // массовое удаление (оно завязано на admin.bulk-delete-*) не задействуем.
    const { searchQuery, handleSearch, handleSort } = useResourceIndex('crm.clients', filters, {
        entityLabel: 'Клиент',
    });

    const handleManagerFilter = (managerId) => {
        router.get(route('crm.clients.index'), { ...filters, manager_id: managerId || undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleLifecycleFilter = (lifecycle) => {
        router.get(route('crm.clients.index'), { ...filters, lifecycle: lifecycle || undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    // Клиент без профиля считается активным — так же, как задаёт дефолт колонки в БД.
    const lifecycleOf = (row) => lifecycleOptions.find(
        (option) => option.value === (row.crm_profile?.lifecycle_status || 'active'),
    );

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'name',
            label: 'Клиент',
            sortable: true,
            render: (_, row) => <Text fontWeight="semibold">{row.name}</Text>,
        },
        {
            key: 'email',
            label: 'Email',
            sortable: true,
            render: (_, row) => <Text fontSize="sm">{row.email}</Text>,
        },
        {
            key: 'phone',
            label: 'Телефон',
            render: (_, row) => <Text fontSize="sm">{row.phone || '—'}</Text>,
        },
        ...(lifecycleOptions.length ? [{
            key: 'lifecycle',
            label: 'Стадия',
            render: (_, row) => {
                const status = lifecycleOf(row);

                return (
                    <HStack gap={1}>
                        <Badge colorPalette={status?.color || 'gray'} variant="subtle">
                            {status?.label || '—'}
                        </Badge>
                        {row.crm_profile?.lifecycle_hint && (
                            <Badge colorPalette="orange" variant="outline" title="Система предлагает сменить стадию">
                                есть предложение
                            </Badge>
                        )}
                    </HStack>
                );
            },
        }] : []),
        {
            key: 'client_status',
            label: 'Статус клиента',
            render: (_, row) => (row.client_status
                ? <Badge colorPalette="gray" variant="subtle">{row.client_status.name}</Badge>
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        ...(canSeeAll ? [{
            key: 'personal_manager',
            label: 'Менеджер',
            render: (_, row) => <Text fontSize="sm">{row.personal_manager?.name || '—'}</Text>,
        }] : []),
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <Button
                    size="xs"
                    variant="ghost"
                    onClick={() => router.visit(route('crm.clients.show', row.id))}
                    aria-label="Открыть карточку клиента"
                >
                    <LuEye />
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Клиенты" />
            <PageHeader
                title={canSeeAll ? 'Клиенты отдела' : 'Мои клиенты'}
                description={canSeeAll
                    ? 'Все клиенты с закреплённым менеджером'
                    : 'Клиенты, закреплённые за вами в 1С'}
            />

            {!managerProfileLinked && (
                <Box mb={4}>
                    <Alert status="warning" title="Аккаунт не связан с карточкой менеджера">
                        Ваш аккаунт не связан с карточкой персонального менеджера, поэтому список пуст.
                        Обратитесь к администратору — привязка настраивается в разделе «Персональные менеджеры».
                    </Alert>
                </Box>
            )}

            <HStack mb={4} gap={3} align="center" wrap="wrap">
                <Box flex="1" minW="240px">
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по имени, email или телефону..."
                    />
                </Box>

                {lifecycleOptions.length > 0 && (
                    <select
                        value={filters.lifecycle || ''}
                        onChange={(e) => handleLifecycleFilter(e.target.value)}
                        style={{ padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)', minWidth: '180px' }}
                    >
                        <option value="">Все стадии</option>
                        {lifecycleOptions.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                    </select>
                )}

                {canSeeAll && (
                    <select
                        value={filters.manager_id || ''}
                        onChange={(e) => handleManagerFilter(e.target.value)}
                        style={{ padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)', minWidth: '220px' }}
                    >
                        <option value="">Все менеджеры</option>
                        {managers?.map((m) => (
                            <option key={m.id} value={m.id}>{m.name}</option>
                        ))}
                    </select>
                )}
            </HStack>

            <DataTable
                data={clients.data}
                columns={columns}
                pagination={clients}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
