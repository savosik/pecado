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
import { LuDownload, LuImageOff, LuPackageX } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Pagination } from '@/Admin/Components/Pagination';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { Tooltip } from '@/components/ui/tooltip';
import ImageLightbox from '@/components/common/ImageLightbox';
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

/** Миниатюра партии: по клику открывает лайтбокс со всеми фото — как в кабинете склада. */
function DefectPhoto({ defect, onOpen }) {
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
        <Box
            as="button"
            type="button"
            position="relative"
            flexShrink={0}
            cursor="zoom-in"
            borderRadius="md"
            overflow="hidden"
            title="Открыть фотографии"
            aria-label={`Фотографии партии #${defect.id}`}
            onClick={onOpen}
        >
            <Image
                src={photo.thumb_url}
                alt={defect.defect_description}
                w="44px"
                h="44px"
                objectFit="cover"
                display="block"
            />
            {defect.photos.length > 1 && (
                <Box
                    position="absolute"
                    bottom="0"
                    right="0"
                    bg="blackAlpha.700"
                    color="white"
                    fontSize="10px"
                    lineHeight="1"
                    px="1"
                    py="0.5"
                    borderTopLeftRadius="sm"
                >
                    +{defect.photos.length - 1}
                </Box>
            )}
        </Box>
    );
}

/**
 * Остаток товара на складе некондиции по данным 1С.
 *
 * Это остаток всей позиции, а не партии: на один остаток кладовщик может
 * завести несколько партий, поэтому рядом с числом показываем, сколько уже
 * разобрано. Партий больше, чем числится в 1С, — расхождение, подсвечиваем.
 */
function ErpStock({ defect }) {
    const stock = defect.erp_stock_quantity ?? 0;
    const covered = defect.covered_quantity ?? 0;
    const uncovered = defect.uncovered_quantity ?? 0;
    const over = uncovered < 0;

    const hint = (
        <VStack align="stretch" gap={2} minW="240px">
            <Text fontSize="xs">
                По этому артикулу на складе «{defect.warehouse.name}» в 1С лежит {stock} шт. брака.
                Это остаток на весь артикул, а не на эту партию.
            </Text>
            <Text fontSize="xs">
                Кладовщик разбирает этот остаток на партии: одну и ту же 1С-строку он может
                расписать на несколько партий с разными дефектами — поэтому у всех партий
                одного артикула здесь одно и то же число.
            </Text>
            <VStack align="stretch" gap={1}>
                <HStack justify="space-between" gap={3}>
                    <Text fontSize="xs">Лежит в 1С</Text>
                    <Text fontSize="xs">{stock} шт.</Text>
                </HStack>
                <HStack justify="space-between" gap={3}>
                    <Text fontSize="xs">Уже разложено по партиям</Text>
                    <Text fontSize="xs">{covered} шт.</Text>
                </HStack>
                <HStack justify="space-between" gap={3}>
                    <Text fontSize="xs">{over ? 'Партий больше, чем в 1С, на' : 'Ещё не разложено'}</Text>
                    <Text fontSize="xs">{Math.abs(uncovered)} шт.</Text>
                </HStack>
            </VStack>
            <Text fontSize="xs" opacity={0.8}>
                {over
                    ? 'Партий заведено больше, чем брака числится в 1С. Это расхождение: '
                      + 'либо остаток уже списали в 1С, либо партию завели с лишним количеством.'
                    : uncovered > 0
                        ? 'Остаток есть, а партии на него нет — этот брак нигде не продаётся, '
                          + 'пока кладовщик не заведёт на него партию.'
                        : 'Весь остаток разложен по партиям — расхождений нет.'}
            </Text>
        </VStack>
    );

    return (
        <Tooltip content={hint} showArrow openDelay={150} contentProps={{ css: { maxW: '320px' } }}>
            <VStack align="end" gap={0} cursor="help">
                <Text fontSize="sm" fontWeight="medium" color={over ? 'red.fg' : undefined}>
                    {stock}
                </Text>
                {covered > 0 && (
                    <Text fontSize="xs" color="fg.muted">
                        в партиях {covered}
                    </Text>
                )}
            </VStack>
        </Tooltip>
    );
}

/**
 * Что кладовщик оформил в этой партии. Резерв (позиции в заказах уценки)
 * показываем отдельной строкой — из этого числа видно, сколько ещё продаётся.
 */
function WarehouseQuantity({ defect }) {
    return (
        <VStack align="end" gap={0}>
            <Text fontSize="sm" fontWeight="medium">{defect.quantity}</Text>
            {defect.reserved_quantity > 0 && (
                <Text fontSize="xs" color="fg.muted">
                    в резерве {defect.reserved_quantity} · свободно {defect.available_quantity}
                </Text>
            )}
        </VStack>
    );
}

/**
 * Справочная цена товара по статусу клиента: показываем самую выгодную цену
 * (это и есть старший статус — Diamond, при его отсутствии VIP, затем Gold),
 * а всю лестницу — в подсказке. Это ориентир для уценки, а не цена партии.
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
                Внутри статуса берём самую распространённую цену, показываем — самую выгодную из статусов.
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
    const [galleryDefect, setGalleryDefect] = useState(null);
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

    // Выгрузка идёт по тому же отбору, что и таблица, — иначе файл не сверить
    // с экраном.
    const exportHref = `/admin/defects/export?${new URLSearchParams({
        filter: filters.filter || 'open',
        ...(filters.search ? { search: filters.search } : {}),
    }).toString()}`;

    return (
        <>
            <Head title="Уценка" />
            <PageHeader
                title="Уценка"
                description="Партии некондиции от склада. Назначьте цену и включите видимость на сайте."
                actions={
                    <Button asChild size="sm" variant="outline">
                        <a href={exportHref}>
                            <LuDownload /> Скачать Excel
                        </a>
                    </Button>
                }
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
                                        placeholder="Поиск по товару, артикулу, коду 1С или описанию дефекта..."
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
                                                <Table.ColumnHeader textAlign="end">
                                                    <Tooltip
                                                        content="Сколько штук этого артикула лежит на складе брака по данным 1С. Число одно на весь артикул: один и тот же остаток кладовщик может расписать на несколько партий с разными дефектами — тогда у всех этих партий здесь будет одинаковая цифра."
                                                        showArrow
                                                        contentProps={{ css: { maxW: '340px' } }}
                                                    >
                                                        <Text as="span" cursor="help">Свободно 1С</Text>
                                                    </Tooltip>
                                                </Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">
                                                    <Tooltip
                                                        content="Сколько штук кладовщик положил именно в эту партию — её кусок от общего остатка 1С. Если по партии уже есть заказы, ниже показано, сколько в резерве и сколько ещё продаётся."
                                                        showArrow
                                                        contentProps={{ css: { maxW: '340px' } }}
                                                    >
                                                        <Text as="span" cursor="help">Заведено складом</Text>
                                                    </Tooltip>
                                                </Table.ColumnHeader>
                                                <Table.ColumnHeader>
                                                    <Tooltip
                                                        content="Самая низкая цена товара среди статусов клиентов и статус, к которому она относится. Справочно — партия продаётся по цене уценки."
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
                                                                <DefectPhoto
                                                                    defect={defect}
                                                                    onOpen={() => setGalleryDefect(defect)}
                                                                />
                                                                <VStack align="start" gap={0}>
                                                                    <Text fontSize="sm" lineClamp={2}>
                                                                        {defect.product.name}
                                                                    </Text>
                                                                    <Text fontSize="xs" color="fg.muted">
                                                                        Партия #{defect.id} · арт. {defect.product.sku || '—'}
                                                                        {defect.product.code ? ` · код ${defect.product.code}` : ''}
                                                                        {' · заведено '}
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
                                                            <ErpStock defect={defect} />
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="end">
                                                            <WarehouseQuantity defect={defect} />
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
                                                                <RowActions
                                                                    size="xs"
                                                                    delete={{
                                                                        onClick: () => setConfirmTarget(defect),
                                                                        disabled: defect.reserved_quantity > 0
                                                                            ? 'По партии есть заказы — удалить нельзя'
                                                                            : false,
                                                                    }}
                                                                />
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

            <ImageLightbox
                images={(galleryDefect?.photos ?? []).map((photo) => ({
                    url: photo.url,
                    alt: galleryDefect?.defect_description,
                }))}
                open={galleryDefect !== null}
                onClose={() => setGalleryDefect(null)}
                title={galleryDefect ? `#${galleryDefect.id} · ${galleryDefect.product.name}` : ''}
            />
        </>
    );
}

DefectsIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;
