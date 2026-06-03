import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box, Card, HStack, VStack, Text, Badge, SimpleGrid, Image,
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

export default function Show() {
    const { category } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Категория: ${category.name}`} />

            <PageHeader
                title={category.name}
                backUrl={route('admin.categories.index')}
                backLabel="К списку категорий"
                actions={
                    can('categories.edit') && (
                        <Button
                            size="sm"
                            onClick={() => router.visit(route('admin.categories.edit', category.id))}
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
                            <InfoRow label="Название" value={category.name} />
                            <InfoRow label="Slug" value={category.slug} />
                            <InfoRow label="Внешний ID" value={category.external_id} />
                            <InfoRow label="Родительская категория" value={category.parent?.name} />
                            <InfoRow label="Количество товаров" value={category.products_count?.toString()} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Статус</Text>
                                <Badge colorPalette={category.is_active ? 'green' : 'gray'} variant="subtle">
                                    {category.is_active ? 'Активна' : 'Неактивна'}
                                </Badge>
                            </Box>
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {/* Иконка */}
                {category.icon_url && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Иконка</Text>
                        </Card.Header>
                        <Card.Body>
                            <Image
                                src={category.icon_url}
                                alt={category.name}
                                w="100px"
                                h="100px"
                                objectFit="contain"
                                borderRadius="md"
                                borderWidth="1px"
                                borderColor="gray.200"
                            />
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Описание */}
                {(category.short_description || category.description) && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Описание</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={3} align="stretch">
                                {category.short_description && (
                                    <InfoRow label="Краткое описание" value={category.short_description} />
                                )}
                                {category.description && (
                                    <InfoRow label="Описание" value={category.description} />
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Атрибуты категории */}
                {category.attributes?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Атрибуты категории</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {category.attributes.map((attr) => (
                                    <Badge key={attr.id} variant="outline">{attr.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Теги */}
                {category.tags?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Теги</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {category.tags.map((tag, i) => (
                                    <Badge key={i} variant="outline">{tag}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* SEO */}
                {(category.meta_title || category.meta_description) && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">SEO</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={3} align="stretch">
                                <InfoRow label="Meta Title" value={category.meta_title} />
                                <InfoRow label="Meta Description" value={category.meta_description} />
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
