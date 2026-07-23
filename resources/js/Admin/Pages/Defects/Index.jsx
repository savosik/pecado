import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
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
import { Switch } from '@/components/ui/switch';
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
    value === null ? '—' : `${new Intl.NumberFormat('ru-RU').format(value)} ₽`;

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

    useFlashToast();

    const canPrice = can('defects.price');
    const canPublish = can('defects.publish');
    const canDelete = can('defects.delete');

    const applyFilters = (next) => {
        router.get('/admin/defects', { ...filters, ...next }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = () => {
        if (!confirmTarget) return;

        router.delete(`/admin/defects/${confirmTarget.id}`, {
            preserveScroll: true,
            onFinish: () => setConfirmTarget(null),
        });
    };

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
                                                <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                                <Table.ColumnHeader>Дефект</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Свободно</Table.ColumnHeader>
                                                <Table.ColumnHeader>Цена уценки</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="center">На сайте</Table.ColumnHeader>
                                                {canDelete && <Table.ColumnHeader />}
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {defects.data.map((defect) => (
                                                <Table.Row key={defect.id}>
                                                    <Table.Cell>
                                                        <HStack gap={3}>
                                                            <DefectPhoto defect={defect} />
                                                            <VStack align="start" gap={0}>
                                                                <Text fontSize="sm" lineClamp={2}>
                                                                    {defect.product.name}
                                                                </Text>
                                                                <Text fontSize="xs" color="fg.muted">
                                                                    {defect.product.sku || '—'} · заведено{' '}
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
                                                        <PriceEditor defect={defect} canPrice={canPrice} />
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
                                            ))}
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
