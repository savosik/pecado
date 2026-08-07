import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box, Card, HStack, VStack, Text, Badge, SimpleGrid, Image, Table,
} from '@chakra-ui/react';
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

const fmt = (v) => v ? parseFloat(v).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : null;
const fmtNum = (v) => v != null ? parseFloat(v).toLocaleString('ru-RU') : null;

export default function Show() {
    const { product, can_view_cost: canViewCost = false } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Товар: ${product.name}`} />

            <PageHeader
                title={product.name}
                backUrl={route('admin.products.index')}
                backLabel="К списку товаров"
                actions={
                    can('products.edit') && (
                        <Button
                            size="sm"
                            onClick={() => router.visit(route('admin.products.edit', product.id))}
                        >
                            <LuPencil />
                            Редактировать
                        </Button>
                    )
                }
            />

            <VStack gap={4} align="stretch">
                {/* Основная информация */}
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                            <InfoRow label="SKU" value={product.sku} />
                            <InfoRow label="Артикул" value={product.code} />
                            <InfoRow label="Внешний ID (ERP)" value={product.external_id} />
                            <InfoRow label="Slug" value={product.slug} />
                            <InfoRow label="Бренд" value={product.brand?.name} />
                            <InfoRow label="Категория" value={product.category?.name} />
                            <InfoRow label="Модель" value={product.model?.name} />
                            <InfoRow label="Базовая цена" value={fmt(product.base_price) ? `${fmt(product.base_price)} ₽` : null} />
                            {canViewCost && (
                                <InfoRow
                                    label="Себестоимость"
                                    value={fmt(product.cost_price) ? `${fmt(product.cost_price)} ₽` : null}
                                />
                            )}
                            {product.variant_name && (
                                <InfoRow label="Вариант" value={product.variant_name} />
                            )}
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {/* Статусы */}
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Статусы</Text>
                    </Card.Header>
                    <Card.Body>
                        <HStack gap={2} flexWrap="wrap">
                            <Badge colorPalette={product.hidden ? 'red' : 'green'} variant="subtle">
                                {product.hidden ? 'Скрытый' : 'Опубликован'}
                            </Badge>
                            {product.is_new && <Badge colorPalette="blue" variant="subtle">Новинка</Badge>}
                            {product.is_bestseller && <Badge colorPalette="orange" variant="subtle">Хит продаж</Badge>}
                            {product.is_marked && <Badge colorPalette="purple" variant="subtle">Маркированный</Badge>}
                            {product.is_liquidation && <Badge colorPalette="red" variant="subtle">Ликвидация</Badge>}
                            {product.for_marketplaces && <Badge colorPalette="teal" variant="subtle">Маркетплейсы</Badge>}
                        </HStack>
                    </Card.Body>
                </Card.Root>

                {/* Медиа */}
                {(product.main_image || product.additional_media?.length > 0) && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Медиа</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={3} flexWrap="wrap" align="flex-start">
                                {product.main_image && (
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb={1}>Главное фото</Text>
                                        <Image
                                            src={product.main_image}
                                            alt={product.name}
                                            w="120px"
                                            h="120px"
                                            objectFit="contain"
                                            borderRadius="md"
                                            borderWidth="1px"
                                            borderColor="gray.200"
                                        />
                                    </Box>
                                )}
                                {product.additional_media?.length > 0 && (
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb={1}>Дополнительные фото</Text>
                                        <HStack gap={2} flexWrap="wrap">
                                            {product.additional_media.map((m) => (
                                                <Image
                                                    key={m.id}
                                                    src={m.url}
                                                    alt=""
                                                    w="80px"
                                                    h="80px"
                                                    objectFit="contain"
                                                    borderRadius="md"
                                                    borderWidth="1px"
                                                    borderColor="gray.200"
                                                />
                                            ))}
                                        </HStack>
                                    </Box>
                                )}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Атрибуты */}
                {product.attributes?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Атрибуты</Text>
                        </Card.Header>
                        <Card.Body p={0}>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Атрибут</Table.ColumnHeader>
                                        <Table.ColumnHeader>Значение</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {product.attributes.map((attr, i) => (
                                        <Table.Row key={i}>
                                            <Table.Cell>{attr.attribute_name}</Table.Cell>
                                            <Table.Cell>{attr.value}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Склады */}
                {product.warehouses?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Остатки на складах</Text>
                        </Card.Header>
                        <Card.Body p={0}>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Склад</Table.ColumnHeader>
                                        <Table.ColumnHeader>Количество</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {product.warehouses.map((w, i) => (
                                        <Table.Row key={i}>
                                            <Table.Cell>{w.name}</Table.Cell>
                                            <Table.Cell>{w.quantity}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Характеристики */}
                {(product.weight_gross || product.weight_net || product.width || product.height || product.depth) && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Характеристики</Text>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={{ base: 2, md: 5 }} gap={4}>
                                <InfoRow label="Вес брутто (кг)" value={fmtNum(product.weight_gross)} />
                                <InfoRow label="Вес нетто (кг)" value={fmtNum(product.weight_net)} />
                                <InfoRow label="Ширина (мм)" value={fmtNum(product.width)} />
                                <InfoRow label="Высота (мм)" value={fmtNum(product.height)} />
                                <InfoRow label="Глубина (мм)" value={fmtNum(product.depth)} />
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Теги */}
                {product.tags?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Теги</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {product.tags.map((tag, i) => (
                                    <Badge key={i} variant="outline">{tag}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* ERP */}
                {(product.erp_created_at || product.erp_updated_at) && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">ERP</Text>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <InfoRow label="Создан в ERP" value={product.erp_created_at} />
                                <InfoRow label="Обновлён в ERP" value={product.erp_updated_at} />
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
