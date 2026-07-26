import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Box,
    Card,
    HStack,
    Image,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuImageOff, LuPackageX, LuPencil, LuTrash2 } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Pagination } from '@/Admin/Components/Pagination';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';
import { useFlashToast } from '@/hooks/useFlashToast';
import { DefectStatusBadge } from '@/Wms/Components/DefectStatusBadge';

const FILTERS = [
    { value: '', label: 'Все' },
    { value: 'open', label: 'Открытые' },
    { value: 'unpriced', label: 'Без цены' },
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
                w="48px"
                h="48px"
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
            w="48px"
            h="48px"
            objectFit="cover"
            borderRadius="md"
            flexShrink={0}
        />
    );
}

/**
 * Партия одной карточкой — мобильный вариант строки таблицы.
 *
 * Действия крупные (44px): попадать пальцем в иконку размера xs на складе
 * невозможно.
 */
function DefectMobileCard({ defect, can, onDelete }) {
    const reserved = defect.reserved_quantity > 0;

    return (
        <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3}>
            <VStack align="stretch" gap={3}>
                <HStack gap={3} align="start">
                    <DefectPhoto defect={defect} />
                    <Box flex="1" minW={0}>
                        <Text fontSize="sm" fontWeight="medium" lineClamp={2}>
                            {defect.product.name}
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            Партия #{defect.id} · {defect.product.sku || '—'}
                        </Text>
                    </Box>
                    <DefectStatusBadge defect={defect} />
                </HStack>

                <Text fontSize="sm" color="fg.muted" lineClamp={3}>
                    {defect.defect_description}
                </Text>

                <HStack gap={4} flexWrap="wrap" fontSize="sm">
                    <Text>
                        <Text as="span" color="fg.muted">Кол-во: </Text>
                        {defect.quantity}
                    </Text>
                    <Text>
                        <Text as="span" color="fg.muted">Свободно: </Text>
                        {defect.available_quantity}
                    </Text>
                    <Text>
                        <Text as="span" color="fg.muted">Цена: </Text>
                        {formatPrice(defect.price)}
                    </Text>
                </HStack>

                {(can('wms-defects.edit') || can('wms-defects.delete')) && (
                    <HStack gap={2}>
                        {can('wms-defects.edit') && (
                            <Button asChild size="sm" variant="outline" flex="1" h="44px">
                                <Link href={`/wms/defects/${defect.id}/edit`}>
                                    <LuPencil /> Изменить
                                </Link>
                            </Button>
                        )}
                        {can('wms-defects.delete') && (
                            <Button
                                size="sm"
                                variant="outline"
                                colorPalette="red"
                                h="44px"
                                minW="44px"
                                disabled={reserved}
                                title={reserved ? 'По партии есть заказы — удалить нельзя' : 'Удалить партию'}
                                onClick={onDelete}
                            >
                                <LuTrash2 />
                            </Button>
                        )}
                    </HStack>
                )}
            </VStack>
        </Box>
    );
}

export default function DefectsIndex() {
    const { defects, filters, stats } = usePage().props;
    const { can } = usePermission();
    const [confirmTarget, setConfirmTarget] = useState(null);

    useFlashToast();

    const applyFilters = (next) => {
        router.get('/wms/defects', { ...filters, ...next }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = () => {
        if (!confirmTarget) return;

        router.delete(`/wms/defects/${confirmTarget.id}`, {
            preserveScroll: true,
            onFinish: () => setConfirmTarget(null),
        });
    };

    return (
        <>
            <Head title="Некондиция — Склад" />
            <PageHeader
                title="Партии брака"
                description="Учёт некондиции: дефект, количество и фотографии. Цену и публикацию на сайте задаёт закупщик."
                createHref={can('wms-defects.create') ? '/wms/defects/create' : null}
                createLabel="Завести партию"
                createPermission="wms-defects.create"
            />

            <VStack gap={4} align="stretch">
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <StatCard label="Всего партий" value={stats.total} />
                    <StatCard label="Открытых" value={stats.open} />
                    <StatCard label="Без цены" value={stats.unpriced} />
                    <StatCard label="В продаже" value={stats.published} />
                </SimpleGrid>

                <Card.Root>
                    <Card.Body>
                        <VStack gap={3} align="stretch">
                            <HStack gap={2} flexWrap="wrap">
                                <Box flex="1" minW={{ base: '100%', md: '240px' }}>
                                    <SearchInput
                                        value={filters.search || ''}
                                        onChange={(value) => applyFilters({ search: value, page: 1 })}
                                        placeholder="Поиск по товару, артикулу или описанию дефекта..."
                                    />
                                </Box>
                                <HStack gap={1} flexWrap="wrap">
                                    {FILTERS.map((item) => (
                                        <Button
                                            key={item.value || 'all'}
                                            size={{ base: 'sm', md: 'xs' }}
                                            variant={(filters.filter || '') === item.value ? 'solid' : 'outline'}
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
                                        Партии не найдены. Заведите первую — кнопка «Завести партию» выше.
                                    </Text>
                                </HStack>
                            ) : (
                                <>
                                {/* Телефон: карточки. Таблица из семи колонок в 360px
                                    не помещается, а горизонтальный скролл на складе
                                    одной рукой не листают. */}
                                <VStack gap={2} align="stretch" display={{ base: 'flex', lg: 'none' }}>
                                    {defects.data.map((defect) => (
                                        <DefectMobileCard
                                            key={defect.id}
                                            defect={defect}
                                            can={can}
                                            onDelete={() => setConfirmTarget(defect)}
                                        />
                                    ))}
                                </VStack>

                                <Box overflowX="auto" display={{ base: 'none', lg: 'block' }}>
                                    <Table.Root size="sm" variant="line">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader w="72px">Партия</Table.ColumnHeader>
                                                <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                                <Table.ColumnHeader>Дефект</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Кол-во</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Свободно</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Цена</Table.ColumnHeader>
                                                <Table.ColumnHeader>Состояние</Table.ColumnHeader>
                                                <Table.ColumnHeader />
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {defects.data.map((defect) => (
                                                <Table.Row key={defect.id}>
                                                    <Table.Cell>
                                                        <Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">
                                                            #{defect.id}
                                                        </Text>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <HStack gap={3}>
                                                            <DefectPhoto defect={defect} />
                                                            <VStack align="start" gap={0}>
                                                                <Text fontSize="sm" lineClamp={2}>
                                                                    {defect.product.name}
                                                                </Text>
                                                                <Text fontSize="xs" color="fg.muted">
                                                                    {defect.product.sku || '—'}
                                                                </Text>
                                                            </VStack>
                                                        </HStack>
                                                    </Table.Cell>
                                                    <Table.Cell maxW="280px">
                                                        <Text fontSize="sm" lineClamp={2}>
                                                            {defect.defect_description}
                                                        </Text>
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end">{defect.quantity}</Table.Cell>
                                                    <Table.Cell textAlign="end">
                                                        <Text
                                                            fontSize="sm"
                                                            color={defect.available_quantity === 0 ? 'fg.muted' : undefined}
                                                        >
                                                            {defect.available_quantity}
                                                        </Text>
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end">{formatPrice(defect.price)}</Table.Cell>
                                                    <Table.Cell>
                                                        <DefectStatusBadge defect={defect} />
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <HStack gap={1} justify="end">
                                                            {can('wms-defects.edit') && (
                                                                <Button asChild size="xs" variant="ghost">
                                                                    <Link href={`/wms/defects/${defect.id}/edit`}>
                                                                        <LuPencil />
                                                                    </Link>
                                                                </Button>
                                                            )}
                                                            {can('wms-defects.delete') && (
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
                                                            )}
                                                        </HStack>
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                                </>
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
                        ? `Партия «${confirmTarget.defect_description}» будет удалена вместе с фотографиями. Действие необратимо.`
                        : ''
                }
                confirmLabel="Удалить"
            />
        </>
    );
}

DefectsIndex.layout = (page) => <WmsLayout>{page}</WmsLayout>;
