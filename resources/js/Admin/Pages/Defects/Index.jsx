import { useEffect, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    Image,
    Input,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuImageOff, LuPackageX, LuTrash2 } from 'react-icons/lu';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Pagination } from '@/Admin/Components/Pagination';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { Tooltip } from '@/components/ui/tooltip';
import { usePermission } from '@/Admin/hooks/usePermission';
import { useFlashToast } from '@/hooks/useFlashToast';

const FILTERS = [
    { value: 'open', label: 'Открытые' },
    { value: 'unpriced', label: 'Без цены' },
    { value: 'unpublished', label: 'С ценой, не в продаже' },
    { value: 'published', label: 'В продаже' },
    { value: 'closed', label: 'Закрытые' },
];

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value)} ₽`;

/** Цена со скидкой от справочной — считаем так же, как бэкенд. */
const withDiscount = (price, discount) => Math.round(price * (1 - discount / 100) * 100) / 100;

function StatCard({ label, value }) {
    return (
        <Card.Root>
            <Card.Body>
                <Text fontSize="xs" color="fg.muted">{label}</Text>
                <Text fontSize="2xl" fontWeight="bold">{value}</Text>
            </Card.Body>
        </Card.Root>
    );
}

function DefectPhoto({ defect }) {
    const photo = defect.photos?.[0];

    if (!photo) {
        return (
            <Box
                w="44px"
                h="44px"
                borderRadius="md"
                borderWidth="1px"
                borderColor="border"
                display="flex"
                alignItems="center"
                justifyContent="center"
                color="fg.muted"
                flexShrink={0}
            >
                <LuImageOff size={16} />
            </Box>
        );
    }

    return (
        <Image
            src={photo.thumb_url}
            alt={defect.defect_description}
            w="44px"
            h="44px"
            objectFit="cover"
            borderRadius="md"
            flexShrink={0}
        />
    );
}

/**
 * Справочная цена товара по статусу клиента: показываем старший статус, по
 * которому цена нашлась (Diamond → VIP → Gold → …), а всю лестницу — в
 * подсказке. Это ориентир для уценки, а не цена партии.
 */
function ReferencePrice({ defect, previewDiscount }) {
    const reference = defect.reference_price;

    if (!reference || reference.price === null) {
        return (
            <Text fontSize="xs" color="fg.muted">
                нет цен клиентов
            </Text>
        );
    }

    const preview = previewDiscount > 0 ? withDiscount(reference.price, previewDiscount) : null;

    const hint = (
        <VStack align="stretch" gap={1} minW="200px">
            <Text fontSize="xs" fontWeight="medium">Цены клиентов по статусам</Text>
            {reference.ladder.map((row) => (
                <HStack key={row.status_id} justify="space-between" gap={3}>
                    <Text fontSize="xs">{row.name}</Text>
                    <Text fontSize="xs">
                        {formatPrice(row.price)}
                        <Text as="span" opacity={0.7}> · {row.clients} кл.</Text>
                    </Text>
                </HStack>
            ))}
            <Text fontSize="xs" opacity={0.7}>
                Берём самую распространённую цену внутри статуса.
            </Text>
        </VStack>
    );

    return (
        <Tooltip content={hint} showArrow openDelay={150} contentProps={{ css: { maxW: '320px' } }}>
            <VStack align="start" gap={0} cursor="help">
                <Text fontSize="sm" fontWeight="medium">{formatPrice(reference.price)}</Text>
                <Badge
                    size="sm"
                    variant="outline"
                    colorPalette="gray"
                    style={reference.status.color ? { color: reference.status.color } : undefined}
                >
                    {reference.status.name}
                </Badge>
                {preview !== null && (
                    <Text fontSize="xs" color="green.fg">
                        −{previewDiscount}% → {formatPrice(preview)}
                    </Text>
                )}
            </VStack>
        </Tooltip>
    );
}

/**
 * Инлайн-цена: пустое поле + кнопка «Сохранить». Пишем только по явному
 * действию — цена уходит клиенту, случайного автосохранения быть не должно.
 */
function PriceEditor({ defect, canPrice }) {
    const [value, setValue] = useState(defect.price !== null ? String(defect.price) : '');
    const [saving, setSaving] = useState(false);

    if (!canPrice) {
        return <Text fontSize="sm">{formatPrice(defect.price)}</Text>;
    }

    const dirty = value !== '' && Number(value) !== defect.price;

    const save = () => {
        setSaving(true);
        router.put(`/admin/defects/${defect.id}/price`, { price: value }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    return (
        <HStack gap={1}>
            <Input
                type="number"
                size="xs"
                min={0}
                step="0.01"
                value={value}
                onChange={(event) => setValue(event.target.value)}
                width="110px"
                placeholder="цена ₽"
            />
            <Button
                size="xs"
                variant="outline"
                onClick={save}
                loading={saving}
                disabled={!dirty}
            >
                ОК
            </Button>
        </HStack>
    );
}

/**
 * Тумблер публикации. Публикацию без цены блокирует и бэкенд — здесь просто
 * не даём включить, чтобы не гонять заведомо отклоняемый запрос.
 */
function PublishToggle({ defect, canPublish }) {
    const disabled = !canPublish || defect.price === null;

    const toggle = (checked) => {
        router.put(`/admin/defects/${defect.id}/publish`, { is_published: checked }, {
            preserveScroll: true,
        });
    };

    return (
        <Switch
            checked={defect.is_published}
            disabled={disabled}
            onCheckedChange={(event) => toggle(event.checked)}
        />
    );
}

export default function DefectsIndex() {
    const { defects, filters, stats } = usePage().props;
    const { can } = usePermission();
    const [confirmTarget, setConfirmTarget] = useState(null);
    const [selectedIds, setSelectedIds] = useState([]);
    const [discount, setDiscount] = useState('');
    const [bulkSaving, setBulkSaving] = useState(false);

    useFlashToast();

    const canPrice = can('defects.price');
    const canPublish = can('defects.publish');
    const canDelete = can('defects.delete');

    // Партия годится для массовой установки, только если она открыта и по её
    // товару вообще есть справочная цена.
    const selectableIds = useMemo(
        () => defects.data
            .filter((defect) => !defect.closed_at && defect.reference_price?.price != null)
            .map((defect) => defect.id),
        [defects.data]
    );

    // Выделение живёт в рамках текущей страницы списка.
    useEffect(() => {
        setSelectedIds((current) => current.filter((id) => selectableIds.includes(id)));
    }, [selectableIds]);

    const discountValue = discount === '' ? 0 : Number(discount);
    const discountValid = Number.isFinite(discountValue) && discountValue >= 0 && discountValue <= 99;
    const previewDiscount = discountValid ? discountValue : 0;

    const applyFilters = (next) => {
        router.get('/admin/defects', { ...filters, ...next }, {
            preserveState: true,
            replace: true,
        });
    };

    const toggleRow = (id, checked) => {
        setSelectedIds((current) => (checked
            ? [...new Set([...current, id])]
            : current.filter((item) => item !== id)));
    };

    const toggleAll = (checked) => {
        setSelectedIds(checked ? selectableIds : []);
    };

    const applyBulkPrices = () => {
        setBulkSaving(true);
        router.post('/admin/defects/prices/bulk', {
            ids: selectedIds,
            discount_percent: previewDiscount,
        }, {
            preserveScroll: true,
            onSuccess: () => setSelectedIds([]),
            onFinish: () => setBulkSaving(false),
        });
    };

    const handleDelete = () => {
        if (!confirmTarget) return;

        router.delete(`/admin/defects/${confirmTarget.id}`, {
            preserveScroll: true,
            onFinish: () => setConfirmTarget(null),
        });
    };

    const allSelected = selectableIds.length > 0 && selectedIds.length === selectableIds.length;
    const headerChecked = allSelected ? true : (selectedIds.length > 0 ? 'indeterminate' : false);

    return (
        <>
            <Head title="Уценка" />
            <PageHeader
                title="Уценка"
                description="Партии некондиции от склада. Назначьте цену и включите видимость на сайте."
            />

            <VStack gap={4} align="stretch">
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <StatCard label="Открытых партий" value={stats.total} />
                    <StatCard label="Без цены" value={stats.unpriced} />
                    <StatCard label="С ценой, не в продаже" value={stats.unpublished} />
                    <StatCard label="В продаже" value={stats.published} />
                </SimpleGrid>

                <Card.Root>
                    <Card.Body>
                        <VStack gap={3} align="stretch">
                            <HStack gap={2} flexWrap="wrap">
                                <Box flex="1" minW="240px">
                                    <SearchInput
                                        value={filters.search || ''}
                                        onChange={(value) => applyFilters({ search: value, page: 1 })}
                                        placeholder="Поиск по товару, артикулу или описанию дефекта..."
                                    />
                                </Box>
                                <HStack gap={1} flexWrap="wrap">
                                    {FILTERS.map((item) => (
                                        <Button
                                            key={item.value}
                                            size="xs"
                                            variant={filters.filter === item.value ? 'solid' : 'outline'}
                                            onClick={() => applyFilters({ filter: item.value, page: 1 })}
                                        >
                                            {item.label}
                                        </Button>
                                    ))}
                                </HStack>
                            </HStack>

                            {canPrice && selectedIds.length > 0 && (
                                <HStack
                                    gap={3}
                                    flexWrap="wrap"
                                    p={3}
                                    borderWidth="1px"
                                    borderColor="border"
                                    borderRadius="md"
                                    bg="bg.subtle"
                                >
                                    <Text fontSize="sm" fontWeight="medium">
                                        Выбрано партий: {selectedIds.length}
                                    </Text>
                                    <HStack gap={2}>
                                        <Text fontSize="sm" color="fg.muted">Скидка от справочной цены</Text>
                                        <Input
                                            type="number"
                                            size="xs"
                                            min={0}
                                            max={99}
                                            step="0.5"
                                            width="90px"
                                            value={discount}
                                            onChange={(event) => setDiscount(event.target.value)}
                                            placeholder="0"
                                        />
                                        <Text fontSize="sm" color="fg.muted">%</Text>
                                    </HStack>
                                    <Button
                                        size="xs"
                                        onClick={applyBulkPrices}
                                        loading={bulkSaving}
                                        disabled={!discountValid}
                                    >
                                        Установить цены
                                    </Button>
                                    <Button size="xs" variant="ghost" onClick={() => setSelectedIds([])}>
                                        Снять выделение
                                    </Button>
                                    <Text fontSize="xs" color="fg.muted">
                                        Текущие цены выбранных партий будут перезаписаны.
                                    </Text>
                                </HStack>
                            )}

                            {defects.data.length === 0 ? (
                                <HStack gap={2} color="fg.muted" py={6} justify="center">
                                    <LuPackageX size={18} />
                                    <Text fontSize="sm">
                                        Партий нет. Их заводит склад в разделе «Некондиция».
                                    </Text>
                                </HStack>
                            ) : (
                                <Box overflowX="auto">
                                    <Table.Root size="sm" variant="line">
                                        <Table.Header>
                                            <Table.Row>
                                                {canPrice && (
                                                    <Table.ColumnHeader width="40px">
                                                        <Checkbox
                                                            size="sm"
                                                            checked={headerChecked}
                                                            disabled={selectableIds.length === 0}
                                                            onCheckedChange={(event) => toggleAll(!!event.checked)}
                                                            aria-label="Выбрать все партии"
                                                        />
                                                    </Table.ColumnHeader>
                                                )}
                                                <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                                <Table.ColumnHeader>Дефект</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Свободно</Table.ColumnHeader>
                                                <Table.ColumnHeader>
                                                    <Tooltip
                                                        content="Цена товара для клиентов старшего статуса, по которому она нашлась. Справочно — партия продаётся по цене уценки."
                                                        showArrow
                                                        contentProps={{ css: { maxW: '320px' } }}
                                                    >
                                                        <Text as="span" cursor="help">Цена клиента</Text>
                                                    </Tooltip>
                                                </Table.ColumnHeader>
                                                <Table.ColumnHeader>Цена уценки</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="center">На сайте</Table.ColumnHeader>
                                                {canDelete && <Table.ColumnHeader />}
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {defects.data.map((defect) => {
                                                const selected = selectedIds.includes(defect.id);
                                                const selectable = selectableIds.includes(defect.id);

                                                return (
                                                    <Table.Row key={defect.id}>
                                                        {canPrice && (
                                                            <Table.Cell>
                                                                <Checkbox
                                                                    size="sm"
                                                                    checked={selected}
                                                                    disabled={!selectable}
                                                                    onCheckedChange={(event) => toggleRow(defect.id, !!event.checked)}
                                                                    aria-label={`Выбрать партию #${defect.id}`}
                                                                    title={
                                                                        selectable
                                                                            ? undefined
                                                                            : 'Нет справочной цены или партия закрыта'
                                                                    }
                                                                />
                                                            </Table.Cell>
                                                        )}
                                                        <Table.Cell>
                                                            <HStack gap={3}>
                                                                <DefectPhoto defect={defect} />
                                                                <VStack align="start" gap={0}>
                                                                    <Text fontSize="sm" lineClamp={2}>
                                                                        {defect.product.name}
                                                                    </Text>
                                                                    <Text fontSize="xs" color="fg.muted">
                                                                        Партия #{defect.id} · {defect.product.sku || '—'} · заведено{' '}
                                                                        {defect.created_by_name || '—'}
                                                                    </Text>
                                                                </VStack>
                                                            </HStack>
                                                        </Table.Cell>
                                                        <Table.Cell maxW="260px">
                                                            <Text fontSize="sm" lineClamp={2}>
                                                                {defect.defect_description}
                                                            </Text>
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="end">
                                                            {defect.available_quantity}
                                                            {defect.reserved_quantity > 0 && (
                                                                <Text as="span" fontSize="xs" color="fg.muted">
                                                                    {' '}/ {defect.quantity}
                                                                </Text>
                                                            )}
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <ReferencePrice
                                                                defect={defect}
                                                                previewDiscount={selected ? previewDiscount : 0}
                                                            />
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <PriceEditor
                                                                key={`${defect.id}-${defect.price}`}
                                                                defect={defect}
                                                                canPrice={canPrice}
                                                            />
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="center">
                                                            <PublishToggle defect={defect} canPublish={canPublish} />
                                                        </Table.Cell>
                                                        {canDelete && (
                                                            <Table.Cell textAlign="end">
                                                                <Button
                                                                    size="xs"
                                                                    variant="ghost"
                                                                    colorPalette="red"
                                                                    disabled={defect.reserved_quantity > 0}
                                                                    title={
                                                                        defect.reserved_quantity > 0
                                                                            ? 'По партии есть заказы — удалить нельзя'
                                                                            : 'Удалить партию'
                                                                    }
                                                                    onClick={() => setConfirmTarget(defect)}
                                                                >
                                                                    <LuTrash2 />
                                                                </Button>
                                                            </Table.Cell>
                                                        )}
                                                    </Table.Row>
                                                );
                                            })}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            )}

                            <Pagination
                                pagination={defects}
                                onPageChange={(page) => applyFilters({ page })}
                            />
                        </VStack>
                    </Card.Body>
                </Card.Root>
            </VStack>

            <ConfirmDialog
                open={confirmTarget !== null}
                onClose={() => setConfirmTarget(null)}
                onConfirm={handleDelete}
                title="Удалить партию?"
                description={
                    confirmTarget
                        ? `Партия «${confirmTarget.defect_description}» (${confirmTarget.product.name}) будет удалена вместе с фотографиями. Действие необратимо.`
                        : ''
                }
                confirmLabel="Удалить"
            />
        </>
    );
}

DefectsIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;
