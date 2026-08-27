import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import ContractForm from '@/Crm/Components/ContractForm';
import ContractsTabs from '@/Crm/Pages/Contracts/components/ContractsTabs';
import CategoryManager from '@/Crm/Pages/Contracts/components/CategoryManager';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { LuFilePlus, LuFolderCog, LuListChecks, LuPaperclip } from 'react-icons/lu';

const selectStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '160px',
};

/**
 * Реестр договоров.
 *
 * Строка — договор. Вкладки — категории (бывшие вкладки Google-таблицы)
 * и «Без договора».
 */
export default function Index({
    contracts,
    filters,
    categories = [],
    missingCount = 0,
    statuses = [],
    paymentTerms = [],
    forms = [],
    managers = [],
    organizations = [],
    preselect = {},
    can = {},
    canSeeDepartment = false,
    canFilterByManager = false,
    expiringDays = 30,
}) {
    const [creating, setCreating] = useState(!!preselect?.create);
    const [managingCategories, setManagingCategories] = useState(false);

    const del = useConfirmDelete({
        title: 'Удалить договор?',
        description: (row) => `Договор ${row?.number ?? ''} с «${row?.counterparty_name ?? ''}» будет удалён вместе со сканами.`,
        onConfirm: (row) => router.delete(route('crm.contracts.destroy', row.id), { preserveScroll: true }),
    });

    const apply = (patch) => {
        router.get(route('crm.contracts.index'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        {
            key: 'number',
            label: 'Договор',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" fontWeight="600">{row.number}</Text>
                    <Text fontSize="xs" color="fg.muted">
                        {row.date ? `от ${row.date}` : 'без даты'}
                        {row.category ? ` · ${row.category.name}` : ''}
                    </Text>
                </VStack>
            ),
        },
        {
            key: 'counterparty',
            label: 'Контрагент / партнёр',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{row.counterparty_name}</Text>
                    {row.company?.tax_id && <Text fontSize="xs" color="fg.muted">ИНН {row.company.tax_id}</Text>}
                    {row.partner && row.partner.name !== row.counterparty_name && (
                        <Text fontSize="xs" color="fg.muted">{row.partner.name}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'status',
            label: 'Статус',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Badge size="sm" colorPalette={row.status_color}>{row.status_label}</Badge>
                    {row.signed_at && <Text fontSize="xs" color="fg.muted">подписан {row.signed_at}</Text>}
                </VStack>
            ),
        },
        {
            key: 'terms',
            label: 'Оплата / форма',
            render: (_, row) => (
                <HStack gap={1} flexWrap="wrap">
                    {row.payment_terms_label && <Badge size="sm" variant="subtle" colorPalette={row.payment_terms_color}>{row.payment_terms_label}</Badge>}
                    {row.form_label && <Badge size="sm" variant="outline" colorPalette={row.form_color}>{row.form_label}</Badge>}
                    {!row.payment_terms_label && !row.form_label && <Text fontSize="xs" color="fg.muted">—</Text>}
                </HStack>
            ),
        },
        {
            key: 'valid_until',
            label: 'Срок действия',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" color={row.is_expired ? 'red.500' : (row.is_expiring ? 'orange.500' : undefined)}>
                        {row.valid_until ? `до ${row.valid_until}` : 'бессрочный'}
                    </Text>
                    {row.is_expired && <Text fontSize="xs" color="red.500">истёк</Text>}
                    {!row.is_expired && row.is_expiring && <Text fontSize="xs" color="orange.500">истекает</Text>}
                </VStack>
            ),
        },
        {
            key: 'manager',
            label: 'Ответственный',
            render: (_, row) => <Text fontSize="sm">{row.manager?.name || '—'}</Text>,
        },
        {
            key: 'extras',
            label: '',
            render: (_, row) => (
                <HStack gap={2}>
                    {row.files_count > 0 && (
                        <HStack gap={1} title="Файлы"><LuPaperclip size={12} /><Text fontSize="xs">{row.files_count}</Text></HStack>
                    )}
                    {row.open_tasks_count > 0 && (
                        <HStack gap={1} title="Открытые задачи"><LuListChecks size={12} /><Text fontSize="xs">{row.open_tasks_count}</Text></HStack>
                    )}
                    {row.comment && <Text fontSize="xs" color="fg.muted" title={row.comment} maxW="160px" truncate>{row.comment}</Text>}
                </HStack>
            ),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    view={{ href: route('crm.contracts.show', row.id) }}
                    edit={{ href: route('crm.contracts.show', { contract: row.id, edit: 1 }), allowed: !!can.edit }}
                    delete={{ onClick: () => del.request(row), allowed: !!can.delete }}
                />
            ),
        },
    ];

    const activeCategory = categories.find((item) => item.id === Number(filters.category_id));

    return (
        <>
            <Head title="CRM — Договоры" />
            <PageHeader
                title="Договоры"
                description="Реестр договоров с партнёрами: статус подписания, срок действия, сканы и задачи"
                actions={(
                    <HStack gap={2}>
                        {can.edit && (
                            <Button size="sm" variant="outline" onClick={() => setManagingCategories((v) => !v)}>
                                <LuFolderCog /> Категории
                            </Button>
                        )}
                        {can.create && (
                            <Button size="sm" onClick={() => setCreating((v) => !v)}><LuFilePlus /> Новый договор</Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                <ContractsTabs
                    categories={categories}
                    activeCategoryId={filters.category_id}
                    missingCount={missingCount}
                    scope={filters.scope}
                />

                {activeCategory?.description && (
                    <Text fontSize="xs" color="fg.muted">{activeCategory.description}</Text>
                )}

                {managingCategories && (
                    <CategoryManager
                        categories={categories}
                        organizations={organizations}
                        canDelete={!!can.delete}
                        onClose={() => setManagingCategories(false)}
                    />
                )}

                {creating && (
                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <ContractForm
                            categories={categories.filter((item) => item.is_active !== false)}
                            statuses={statuses}
                            paymentTerms={paymentTerms}
                            forms={forms}
                            managers={managers}
                            initialCategoryId={filters.category_id}
                            initialCompany={preselect?.company || null}
                            initialClient={preselect?.client || null}
                            onSaved={(saved) => { setCreating(false); router.visit(route('crm.contracts.show', saved.id)); }}
                            onCancel={() => setCreating(false)}
                        />
                    </Box>
                )}

                <HStack gap={3} flexWrap="wrap" align="center">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search || ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Номер, контрагент, ИНН, партнёр, комментарий…"
                        />
                    </Box>

                    <select value={filters.status || ''} onChange={(e) => apply({ status: e.target.value || undefined })} style={selectStyle}>
                        <option value="">Любой статус</option>
                        {statuses.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>

                    <select value={filters.payment_terms || ''} onChange={(e) => apply({ payment_terms: e.target.value || undefined })} style={selectStyle}>
                        <option value="">Любая оплата</option>
                        {paymentTerms.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>

                    <select value={filters.form || ''} onChange={(e) => apply({ form: e.target.value || undefined })} style={selectStyle}>
                        <option value="">Любая форма</option>
                        {forms.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>

                    {canFilterByManager && (
                        <select value={filters.manager_id || ''} onChange={(e) => apply({ manager_id: e.target.value || undefined })} style={selectStyle}>
                            <option value="">Любой ответственный</option>
                            {managers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                    )}

                    <ScopeToggle section="contracts" scope={filters.scope} available={canSeeDepartment} />
                </HStack>

                <HStack gap={4} flexWrap="wrap">
                    <Checkbox
                        checked={!!filters.expiring}
                        onCheckedChange={(e) => apply({ expiring: e.checked ? 1 : undefined })}
                    >
                        Истекают в ближайшие {expiringDays} дн. или уже истекли
                    </Checkbox>
                </HStack>

                <DataTable
                    data={contracts.data}
                    columns={columns}
                    pagination={contracts}
                    emptyMessage="Договоров пока нет — заведите первый или импортируйте таблицу"
                />
            </VStack>

            <ConfirmDialog {...del.dialogProps} />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
