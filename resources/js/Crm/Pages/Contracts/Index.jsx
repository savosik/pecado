import { useRef, useState } from 'react';
import axios from 'axios';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { usePermission } from '@/shared/Panel/usePermission';
import { toastError, toastSuccess } from '@/utils/toast';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import ContractForm from '@/Crm/Components/ContractForm';
import ContractsTabs from '@/Crm/Pages/Contracts/components/ContractsTabs';
import CategoryManager from '@/Crm/Pages/Contracts/components/CategoryManager';
import QuickSelect from '@/Crm/Pages/Contracts/components/QuickSelect';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { LuFilePlus, LuFolderCog, LuListChecks, LuPaperclip, LuX } from 'react-icons/lu';

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
    const { can: hasPermission } = usePermission();
    const canAttach = hasPermission('crm-attachments.create');
    const quick = !!can.edit;

    // Скрепка в строке: один скрытый input на таблицу, цель — договор,
    // по которому кликнули. Файлы уходят по одному, как в AttachmentPanel.
    const fileInput = useRef(null);
    const [attachTarget, setAttachTarget] = useState(null);
    const pickFiles = (row) => { setAttachTarget(row); fileInput.current?.click(); };
    const uploadFiles = async (event) => {
        const files = Array.from(event.target.files || []);
        event.target.value = '';
        if (!files.length || !attachTarget) return;
        let uploaded = 0;
        for (const file of files) {
            const form = new FormData();
            form.append('entity_type', 'contract');
            form.append('entity_id', attachTarget.id);
            form.append('file', file);
            try {
                await axios.post(route('crm.attachments.store'), form, { headers: { 'Content-Type': 'multipart/form-data' } });
                uploaded += 1;
            } catch (e) {
                toastError(`Файл «${file.name}» не загружен`, e?.response?.data?.errors?.file?.[0] || e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        }
        if (uploaded) {
            toastSuccess(uploaded === 1 ? 'Скан прикреплён' : `Прикреплено файлов: ${uploaded}`);
            router.reload({ only: ['contracts'], preserveScroll: true });
        }
    };

    const categoryOptions = categories.filter((item) => item.is_active !== false).map((item) => ({ value: item.id, label: item.name }));
    const managerOptions = managers.map((item) => ({ value: item.id, label: item.name }));
    const activeFilters = ['status', 'payment_terms', 'form', 'manager_id', 'expiring'].filter((key) => filters[key]);

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
                <VStack align="start" gap={1}>
                    <Text fontSize="sm" fontWeight="600">{row.number}</Text>
                    <Text fontSize="xs" color="fg.muted">{row.date ? `от ${row.date}` : 'без даты'}</Text>
                    {quick
                        ? <QuickSelect contractId={row.id} field="category_id" value={row.category?.id} options={categoryOptions} width="160px" />
                        : (row.category && <Text fontSize="xs" color="fg.muted">{row.category.name}</Text>)}
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
                    {quick
                        ? <QuickSelect contractId={row.id} field="status" value={row.status} options={statuses} width="140px" />
                        : <Badge size="sm" colorPalette={row.status_color}>{row.status_label}</Badge>}
                    {row.signed_at && <Text fontSize="xs" color="fg.muted">подписан {row.signed_at}</Text>}
                </VStack>
            ),
        },
        {
            key: 'terms',
            label: 'Оплата / форма',
            render: (_, row) => (quick ? (
                <VStack align="start" gap={1}>
                    <QuickSelect contractId={row.id} field="payment_terms" value={row.payment_terms} options={paymentTerms} placeholder="Оплата —" width="140px" />
                    <QuickSelect contractId={row.id} field="form" value={row.form} options={forms} placeholder="Форма —" width="140px" />
                </VStack>
            ) : (
                <HStack gap={1} flexWrap="wrap">
                    {row.payment_terms_label && <Badge size="sm" variant="subtle" colorPalette={row.payment_terms_color}>{row.payment_terms_label}</Badge>}
                    {row.form_label && <Badge size="sm" variant="outline" colorPalette={row.form_color}>{row.form_label}</Badge>}
                    {!row.payment_terms_label && !row.form_label && <Text fontSize="xs" color="fg.muted">—</Text>}
                </HStack>
            )),
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
            render: (_, row) => (quick
                ? <QuickSelect contractId={row.id} field="responsible_manager_id" value={row.manager?.id} options={managerOptions} placeholder="Не назначен" width="150px" />
                : <Text fontSize="sm">{row.manager?.name || '—'}</Text>),
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
                    extra={[{ icon: LuPaperclip, label: 'Прикрепить скан', onClick: () => pickFiles(row), allowed: canAttach }]}
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
                    <ContractForm
                        open
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
                )}

                <Box bg="bg.subtle" borderWidth="1px" borderRadius="lg" px={3} py={2}>
                    <HStack gap={2} flexWrap="wrap" align="center">
                        <Box flex="1" minW="220px">
                            <SearchInput
                                value={filters.search || ''}
                                onChange={(value) => apply({ search: value || undefined })}
                                placeholder="Номер, контрагент, ИНН, партнёр…"
                            />
                        </Box>
                        <FilterSelect value={filters.status} onChange={(v) => apply({ status: v })} placeholder="Статус" options={statuses} />
                        <FilterSelect value={filters.payment_terms} onChange={(v) => apply({ payment_terms: v })} placeholder="Оплата" options={paymentTerms} />
                        <FilterSelect value={filters.form} onChange={(v) => apply({ form: v })} placeholder="Форма" options={forms} />
                        {canFilterByManager && (
                            <FilterSelect value={filters.manager_id} onChange={(v) => apply({ manager_id: v })} placeholder="Ответственный" options={managerOptions} width="170px" />
                        )}
                        <Button
                            size="sm"
                            variant={filters.expiring ? 'solid' : 'outline'}
                            colorPalette={filters.expiring ? 'orange' : 'gray'}
                            onClick={() => apply({ expiring: filters.expiring ? undefined : 1 })}
                            title={`Истекают в ближайшие ${expiringDays} дн. или уже истекли`}
                        >
                            Истекают
                        </Button>
                        <ScopeToggle section="contracts" scope={filters.scope} available={canSeeDepartment} />
                        {activeFilters.length > 0 && (
                            <Button size="sm" variant="ghost" onClick={() => apply({ status: undefined, payment_terms: undefined, form: undefined, manager_id: undefined, expiring: undefined })}>
                                <LuX /> Сбросить
                            </Button>
                        )}
                    </HStack>
                </Box>

                <DataTable
                    data={contracts.data}
                    columns={columns}
                    pagination={contracts}
                    emptyMessage="Договоров пока нет — заведите первый или импортируйте таблицу"
                />
            </VStack>

            <input ref={fileInput} type="file" multiple hidden onChange={uploadFiles} accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" />
            <ConfirmDialog {...del.dialogProps} />
        </>
    );
}

/**
 * Фильтр-выпадашка панели: пустое значение = «любой», подпись — сам
 * placeholder, чтобы ряд фильтров читался как набор коротких чипов.
 */
function FilterSelect({ value, onChange, placeholder, options, width = '140px' }) {
    return (
        <NativeSelectRoot size="sm" width={width}>
            <NativeSelectField value={value || ''} onChange={(e) => onChange(e.target.value || undefined)}>
                <option value="">{placeholder}</option>
                {options.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
            </NativeSelectField>
        </NativeSelectRoot>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
