import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, InputGroup,
    Card, Stack, IconButton, createListCollection,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuChevronLeft, LuChevronRight, LuSearch, LuFileText, LuFileDown,
    LuCalendar, LuBuilding2, LuShoppingBag, LuTruck, LuFileSpreadsheet,
    LuReceipt, LuReceiptText, LuFileDiff, LuPackageCheck, LuFileCheck,
    LuScale, LuHandshake, LuScrollText, LuTags, LuFile,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import { MenuRoot, MenuTrigger, MenuContent, MenuItem } from '@/components/ui/menu';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import StatusQuickFilters from '@/components/cabinet/StatusQuickFilters';
import ExportMenu from '@/components/cabinet/ExportMenu';
import SavedSearches from '@/components/cabinet/SavedSearches';
import { useSearchHistory } from '@/hooks/useSearchHistory';

const SORT_OPTIONS = [
    { value: 'date', order: 'desc', label: 'Сначала новые' },
    { value: 'date', order: 'asc', label: 'Сначала старые' },
    { value: 'number', order: 'asc', label: 'По номеру' },
    { value: 'type', order: 'asc', label: 'По виду документа' },
];

// Иконка по виду документа (коды — App\Enums\PrintedDocumentType). Цвет круга
// берётся из type_color того же перечисления, чтобы чипы фильтра и строки
// списка были одной палитры.
const TYPE_ICONS = {
    contract: LuHandshake,
    agreement: LuHandshake,
    specification: LuScrollText,
    invoice: LuReceipt,
    tax_invoice: LuReceiptText,
    correction_invoice: LuFileDiff,
    upd: LuPackageCheck,
    ukd: LuFileDiff,
    waybill: LuTruck,
    consignment_note: LuTruck,
    act: LuFileCheck,
    reconciliation_act: LuScale,
    price_list: LuTags,
    other: LuFile,
};

/**
 * Строка списка — карточка в стиле «Мои заказы»: иконка вида слева, номер
 * основным цветом, контрагент второй строкой, мелкие пилюли с продавцом и
 * основанием, справа — «Скачать».
 *
 * Строка не ссылка: у документа нет своей страницы, единственное действие —
 * скачать файл, и оно на кнопке. Поэтому hover мягче, чем у заказов.
 */
function DocumentRow({ document }) {
    const color = document.type_color || 'gray';
    const TypeIcon = TYPE_ICONS[document.type] || LuFile;
    const BaseIcon = document.base?.label?.startsWith('Отгрузка') ? LuTruck : LuShoppingBag;
    const isSpreadsheet = document.format && document.format !== 'pdf';
    // Формат и размер — одной подписью под кнопкой: «PDF · 0,1 МБ». Бейдж
    // формата на каждой строке был шумом, а размер сам по себе ни о чём.
    const fileMeta = [
        isSpreadsheet ? 'Excel' : 'PDF',
        document.size,
    ].filter(Boolean).join(' · ');

    return (
        <Box
            bg="bg"
            borderRadius="xl"
            border="1px solid"
            borderColor="border.muted"
            p="4"
            _hover={{ borderColor: 'pecado.200', shadow: 'sm', _dark: { borderColor: 'pecado.700' } }}
            transition="all 0.15s"
        >
            <Flex gap="4" align={{ base: 'start', md: 'center' }} direction={{ base: 'column', md: 'row' }}>
                <Flex gap="4" align="start" flex="1" minW="0" w="100%">
                    <Flex
                        align="center" justify="center"
                        w="11" h="11" borderRadius="full" flexShrink="0"
                        bg={`${color}.subtle`} color={`${color}.fg`}
                        display={{ base: 'none', sm: 'flex' }}
                    >
                        <TypeIcon size={20} />
                    </Flex>

                    <Box flex="1" minW="0">
                        {/* Мета-строка: вид документа + дата + период */}
                        <Flex gap="2" align="center" flexWrap="wrap" mb="1" fontSize="xs">
                            <Text fontWeight="600" color={`${color}.fg`} whiteSpace="nowrap">
                                {document.type_label}
                            </Text>
                            {document.date && (
                                <>
                                    <Text as="span" color="gray.300" _dark={{ color: 'gray.600' }}>•</Text>
                                    <HStack gap="1" color="gray.500" _dark={{ color: 'gray.400' }} fontWeight="500">
                                        <LuCalendar size={12} />
                                        <Text whiteSpace="nowrap">{document.date}</Text>
                                    </HStack>
                                </>
                            )}
                            {document.period && (
                                <>
                                    <Text as="span" color="gray.300" _dark={{ color: 'gray.600' }}>•</Text>
                                    <Text color="gray.500" _dark={{ color: 'gray.400' }}>
                                        Период {document.period}
                                    </Text>
                                </>
                            )}
                        </Flex>

                        {/* Заголовок: номер (или название, если номера нет) */}
                        <Flex gap="2.5" align="center" flexWrap="wrap" mb="1.5">
                            <Text
                                fontWeight="700"
                                fontSize="lg"
                                fontFamily={document.number ? 'mono' : undefined}
                                color="gray.800"
                                _dark={{ color: 'gray.100' }}
                                lineHeight="short"
                            >
                                {document.number ? `№ ${document.number}` : document.title}
                            </Text>
                            {isSpreadsheet && (
                                <Badge
                                    colorPalette="green" variant="subtle"
                                    fontSize="2xs" px="2" py="0.5" borderRadius="full" gap="1"
                                >
                                    <LuFileSpreadsheet size={11} />
                                    {document.format_label}
                                </Badge>
                            )}
                        </Flex>

                        {/* Контрагент — главное после номера, поэтому не серым */}
                        {document.company && (
                            <Text
                                fontSize="sm"
                                fontWeight="500"
                                color="gray.700"
                                _dark={{ color: 'gray.300' }}
                                mb={document.organization || document.base ? '2' : '0'}
                                truncate
                            >
                                {document.company}
                            </Text>
                        )}

                        {/* Нижняя строка: продавец + основание */}
                        {(document.organization || document.base) && (
                            <Flex gap="2" align="center" flexWrap="wrap">
                                {document.organization && (
                                    <Badge
                                        variant="outline" colorPalette="gray"
                                        fontSize="2xs" px="2" py="0.5" borderRadius="full" gap="1"
                                    >
                                        <LuBuilding2 size={11} />
                                        {document.organization}
                                    </Badge>
                                )}
                                {document.base && (
                                    <Link href={document.base.url}>
                                        <Badge
                                            variant="outline" colorPalette="gray"
                                            fontSize="2xs" px="2" py="0.5" borderRadius="full" gap="1"
                                            cursor="pointer"
                                            _hover={{ borderColor: 'pecado.400', color: 'pecado.fg' }}
                                            transition="all 0.15s"
                                        >
                                            <BaseIcon size={11} />
                                            {document.base.label}
                                        </Badge>
                                    </Link>
                                )}
                            </Flex>
                        )}
                    </Box>
                </Flex>

                {/* Обычная ссылка, а не Inertia: сервер отдаёт файл,
                    а Inertia ждёт JSON и такой ответ не поймёт. */}
                <VStack gap="1" align={{ base: 'stretch', md: 'end' }} flexShrink="0" w={{ base: '100%', md: 'auto' }}>
                    <Button
                        as="a"
                        href={document.download_url}
                        variant="outline"
                        colorPalette="pecado"
                        size="sm"
                        minW="32"
                    >
                        <LuFileDown size={16} />
                        Скачать
                    </Button>
                    <Text fontSize="xs" color="gray.400" _dark={{ color: 'gray.500' }} textAlign={{ md: 'end' }}>
                        {fileMeta}
                    </Text>
                </VStack>
            </Flex>
        </Box>
    );
}

/**
 * Раздел «Документы» в личном кабинете.
 *
 * Печатные формы, сформированные 1С: счета, счета-фактуры, УПД, акты сверки,
 * договоры. Сайт их только показывает и отдаёт файл — редактировать здесь нечего,
 * поэтому у раздела нет ни карточки документа, ни действий, кроме скачивания.
 *
 * Клиент видит документы всех своих контрагентов, в том числе те, у которых нет
 * заказа или отгрузки на сайте: договор и акт сверки основания не имеют вовсе.
 */
export default function DocumentsIndex({
    filters,
    types = [],
    typeCounts = {},
    typeTotal = 0,
    companies = [],
    organizations = [],
    organizationsEnabled = false,
    presetsEnabled = false,
    exportEnabled = false,
}) {
    const { documents } = usePage().props;

    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');

    const selectedTypes = Array.isArray(filters?.type)
        ? filters.type
        : (filters?.type ? [filters.type] : []);

    const [localFilters, setLocalFilters] = useState({
        // map(String): значения коллекции — строки, а бэкенд отдаёт id числами;
        // без приведения выбранные пункты не подсвечиваются после перезагрузки.
        company_id: Array.isArray(filters?.company_id) ? filters.company_id.map(String) : [],
        organization_id: Array.isArray(filters?.organization_id) ? filters.organization_id.map(String) : [],
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
    });

    const navigateWithParams = (params) => {
        router.get('/cabinet/documents', { ...filters, ...params }, {
            preserveState: true,
            replace: true,
        });
    };

    const { history: searchHistory, push: pushSearchHistory } = useSearchHistory('documents');

    // Debounce 400 мс: раздел листают номерами из писем, и запрос на каждый
    // символ означал бы десяток лишних обращений на один поиск.
    const lastSubmittedSearch = useRef(filters?.search || '');
    useEffect(() => {
        if (search === lastSubmittedSearch.current) return undefined;

        const handle = setTimeout(() => {
            lastSubmittedSearch.current = search;
            pushSearchHistory(search);
            navigateWithParams({ search, page: 1 });
        }, 400);

        return () => clearTimeout(handle);
    }, [search]);

    // Chakra UI v3 Select требует collection — без неё клики по опциям
    // не регистрируются.
    const companyCollection = useMemo(
        () => createListCollection({ items: companies }),
        [companies],
    );
    const organizationCollection = useMemo(
        () => createListCollection({ items: organizations }),
        [organizations],
    );

    const quickTypeItems = useMemo(
        () => types.map((type) => ({
            value: type.value,
            label: type.label,
            count: typeCounts[type.value] ?? 0,
            colorPalette: type.color,
        })),
        [types, typeCounts],
    );

    const handleToggleType = (value) => {
        const next = selectedTypes.includes(value)
            ? selectedTypes.filter((item) => item !== value)
            : [...selectedTypes, value];

        navigateWithParams({ type: next, page: 1 });
    };

    const handleApplyFilters = () => {
        navigateWithParams({ ...localFilters, page: 1 });
        setShowFilters(false);
    };

    const handleResetFilters = () => {
        setSearch('');
        lastSubmittedSearch.current = '';
        setLocalFilters({ company_id: [], organization_id: [], date_from: '', date_to: '' });
        router.get('/cabinet/documents', { per_page: filters?.per_page }, {
            preserveState: false,
            replace: true,
        });
    };

    const handleRemoveFilter = (key, value) => {
        if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
            navigateWithParams({ search: '', page: 1 });

            return;
        }

        const current = filters?.[key];

        if (Array.isArray(current)) {
            const next = current.filter((item) => String(item) !== String(value));
            setLocalFilters((prev) => ({ ...prev, [key]: next }));
            navigateWithParams({ [key]: next, page: 1 });

            return;
        }

        setLocalFilters((prev) => ({ ...prev, [key]: '' }));
        navigateWithParams({ [key]: undefined, page: 1 });
    };

    const handlePageChange = (page) => navigateWithParams({ page });

    const handleSort = (sortBy, sortOrder) => navigateWithParams({ sort_by: sortBy, sort_order: sortOrder, page: 1 });

    const activeFiltersCount = [
        localFilters.company_id.length > 0,
        localFilters.organization_id.length > 0,
        Boolean(localFilters.date_from),
        Boolean(localFilters.date_to),
    ].filter(Boolean).length;

    const filterFields = [
        { key: 'search', label: 'Поиск' },
        {
            key: 'company_id',
            label: 'Контрагент',
            formatter: (value) => companies.find((item) => item.value === String(value))?.label ?? value,
        },
        {
            key: 'organization_id',
            label: 'Продавец',
            formatter: (value) => organizations.find((item) => item.value === String(value))?.label ?? value,
        },
        { key: 'date_from', label: 'Дата с' },
        { key: 'date_to', label: 'Дата по' },
    ];

    const currentSort = SORT_OPTIONS.find(
        (option) => option.value === filters?.sort_by && option.order === filters?.sort_order,
    ) ?? SORT_OPTIONS[0];

    const SortIcon = filters?.sort_order === 'asc' ? LuArrowUp : LuArrowDown;

    return (
        <CabinetLayout title="Документы">
            <Head title="Документы" />

            <VStack align="stretch" gap="4" mb="4">
                <Flex gap="2" wrap="wrap" align="center">
                    <Box flex="1" minW="240px">
                        <InputGroup startElement={<LuSearch size={16} />}>
                            <Input
                                list="documents-search-history"
                                placeholder="Номер или название документа…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </InputGroup>
                        <datalist id="documents-search-history">
                            {searchHistory.map((item) => <option key={item} value={item} />)}
                        </datalist>
                    </Box>

                    <Button
                        variant="outline"
                        onClick={() => setShowFilters((prev) => !prev)}
                    >
                        <LuFilter size={16} />
                        Фильтры
                        {activeFiltersCount > 0 && (
                            <Badge colorPalette="pecado" ml="1">{activeFiltersCount}</Badge>
                        )}
                    </Button>

                    {presetsEnabled && (
                        <SavedSearches
                            section="documents"
                            current={{ ...filters, search }}
                            basePath="/cabinet/documents"
                        />
                    )}

                    {exportEnabled && (
                        <ExportMenu basePath="/cabinet/documents/export" filters={{ ...filters, search }} />
                    )}

                    <MenuRoot>
                        <MenuTrigger asChild>
                            <Button variant="outline">
                                <LuArrowUpDown size={16} />
                                {currentSort.label}
                                <SortIcon size={14} />
                            </Button>
                        </MenuTrigger>
                        <MenuContent>
                            {SORT_OPTIONS.map((option) => (
                                <MenuItem
                                    key={`${option.value}-${option.order}`}
                                    value={`${option.value}-${option.order}`}
                                    onClick={() => handleSort(option.value, option.order)}
                                >
                                    {option.label}
                                </MenuItem>
                            ))}
                        </MenuContent>
                    </MenuRoot>
                </Flex>

                <StatusQuickFilters
                    items={quickTypeItems}
                    selected={selectedTypes}
                    total={typeTotal}
                    onToggle={handleToggleType}
                    onReset={() => navigateWithParams({ type: [], page: 1 })}
                    allLabel="Все документы"
                />

                {showFilters && (
                    <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                        <Card.Body p="4">
                            <Stack gap="4">
                                <Flex gap="4" wrap="wrap">
                                    {companies.length > 0 && (
                                        <Field label="Контрагент" flex="1" minW="220px">
                                            {/*
                                                Select из обёртки — это объект {Root, Trigger, …},
                                                а не компонент: <Select …/> роняет страницу
                                                («Element type is invalid»). Только полная
                                                разметка Root/Trigger/Content/Item.
                                            */}
                                            <Select.Root
                                                multiple
                                                collection={companyCollection}
                                                value={localFilters.company_id}
                                                onValueChange={(e) => setLocalFilters((prev) => ({
                                                    ...prev,
                                                    company_id: e.value,
                                                }))}
                                            >
                                                <Select.Trigger>
                                                    <Select.ValueText placeholder="Все контрагенты">
                                                        {localFilters.company_id.length === 0
                                                            ? 'Все контрагенты'
                                                            : `Выбрано: ${localFilters.company_id.length}`}
                                                    </Select.ValueText>
                                                </Select.Trigger>
                                                <Select.Content>
                                                    {companyCollection.items.map((item) => (
                                                        <Select.Item key={item.value} item={item}>{item.label}</Select.Item>
                                                    ))}
                                                </Select.Content>
                                            </Select.Root>
                                        </Field>
                                    )}

                                    {organizationsEnabled && organizations.length > 0 && (
                                        <Field label="Продавец" flex="1" minW="220px">
                                            <Select.Root
                                                multiple
                                                collection={organizationCollection}
                                                value={localFilters.organization_id}
                                                onValueChange={(e) => setLocalFilters((prev) => ({
                                                    ...prev,
                                                    organization_id: e.value,
                                                }))}
                                            >
                                                <Select.Trigger>
                                                    <Select.ValueText placeholder="Все организации">
                                                        {localFilters.organization_id.length === 0
                                                            ? 'Все организации'
                                                            : `Выбрано: ${localFilters.organization_id.length}`}
                                                    </Select.ValueText>
                                                </Select.Trigger>
                                                <Select.Content>
                                                    {organizationCollection.items.map((item) => (
                                                        <Select.Item key={item.value} item={item}>{item.label}</Select.Item>
                                                    ))}
                                                </Select.Content>
                                            </Select.Root>
                                        </Field>
                                    )}

                                    <Field label="Дата с" minW="160px">
                                        <Input
                                            type="date"
                                            value={localFilters.date_from}
                                            onChange={(e) => setLocalFilters((prev) => ({
                                                ...prev,
                                                date_from: e.target.value,
                                            }))}
                                        />
                                    </Field>

                                    <Field label="Дата по" minW="160px">
                                        <Input
                                            type="date"
                                            value={localFilters.date_to}
                                            onChange={(e) => setLocalFilters((prev) => ({
                                                ...prev,
                                                date_to: e.target.value,
                                            }))}
                                        />
                                    </Field>
                                </Flex>

                                <HStack>
                                    <Button colorPalette="pecado" onClick={handleApplyFilters}>
                                        Применить
                                    </Button>
                                    <Button variant="ghost" onClick={handleResetFilters}>
                                        <LuX size={16} />
                                        Сбросить
                                    </Button>
                                </HStack>
                            </Stack>
                        </Card.Body>
                    </Card.Root>
                )}

                <SelectedFilters
                    filters={{ ...filters, search }}
                    fields={filterFields}
                    onRemove={handleRemoveFilter}
                    onResetAll={activeFiltersCount > 0 || search ? handleResetFilters : undefined}
                />
            </VStack>

            {documents.data.length === 0 ? (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="10" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center"
                                w="16" h="16" borderRadius="full"
                                bg="bg.muted" mx="auto"
                            >
                                <LuFileText size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Документов не найдено</Text>
                            <Text color="gray.500" fontSize="sm">
                                {activeFiltersCount > 0 || search || selectedTypes.length > 0
                                    ? 'Попробуйте изменить условия отбора'
                                    : 'Счета, УПД и акты сверки появляются здесь после того, как их оформит бухгалтерия'}
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    <VStack gap="2" align="stretch">
                        {documents.data.map((document) => (
                            <DocumentRow key={document.id} document={document} />
                        ))}
                    </VStack>

                    {documents.last_page > 1 && (
                        <Flex justify="center" align="center" gap="2" mt="6">
                            <IconButton
                                variant="outline" size="sm"
                                onClick={() => handlePageChange(documents.current_page - 1)}
                                disabled={documents.current_page <= 1}
                                aria-label="Предыдущая страница"
                            >
                                <LuChevronLeft size={16} />
                            </IconButton>

                            {Array.from({ length: documents.last_page }, (_, i) => i + 1)
                                .filter((page) => {
                                    const cur = documents.current_page;

                                    return page === 1 || page === documents.last_page
                                        || (page >= cur - 2 && page <= cur + 2);
                                })
                                .reduce((acc, page, idx, arr) => {
                                    if (idx > 0 && page - arr[idx - 1] > 1) acc.push('...' + page);
                                    acc.push(page);

                                    return acc;
                                }, [])
                                .map((page) => {
                                    if (typeof page === 'string') {
                                        return <Text key={page} px="1" color="fg.muted">…</Text>;
                                    }

                                    return (
                                        <Button
                                            key={page} size="sm" minW="9"
                                            variant={page === documents.current_page ? 'solid' : 'outline'}
                                            colorPalette={page === documents.current_page ? 'pecado' : 'gray'}
                                            onClick={() => handlePageChange(page)}
                                        >
                                            {page}
                                        </Button>
                                    );
                                })}

                            <IconButton
                                variant="outline" size="sm"
                                onClick={() => handlePageChange(documents.current_page + 1)}
                                disabled={documents.current_page >= documents.last_page}
                                aria-label="Следующая страница"
                            >
                                <LuChevronRight size={16} />
                            </IconButton>

                            <Text fontSize="xs" color="fg.muted" ml="2">
                                Стр. {documents.current_page} из {documents.last_page}
                            </Text>
                        </Flex>
                    )}
                </>
            )}
        </CabinetLayout>
    );
}
