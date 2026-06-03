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
    const { brand } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Бренд: ${brand.name}`} />

            <PageHeader
                title={brand.name}
                backUrl={route('admin.brands.index')}
                backLabel="К списку брендов"
                actions={
                    can('brands.edit') && (
                        <Button
                            size="sm"
                            onClick={() => router.visit(route('admin.brands.edit', brand.id))}
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
                            <InfoRow label="Название" value={brand.name} />
                            <InfoRow label="Slug" value={brand.slug} />
                            <InfoRow label="Внешний ID" value={brand.external_id} />
                            <InfoRow label="Родительский бренд" value={brand.parent?.name} />
                            <InfoRow label="Категория" value={brand.category} />
                            <InfoRow label="Количество товаров" value={brand.products_count?.toString()} />
                            {brand.is_featured && (
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="0.5">Выделенный</Text>
                                    <Badge colorPalette="yellow" variant="subtle">Да</Badge>
                                </Box>
                            )}
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {/* Логотип */}
                {brand.logo_url && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Логотип</Text>
                        </Card.Header>
                        <Card.Body>
                            <Image
                                src={brand.logo_url}
                                alt={brand.name}
                                w="160px"
                                h="80px"
                                objectFit="contain"
                                borderRadius="md"
                                borderWidth="1px"
                                borderColor="gray.200"
                            />
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Описание */}
                {(brand.short_description || brand.description) && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Описание</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={3} align="stretch">
                                {brand.short_description && (
                                    <InfoRow label="Краткое описание" value={brand.short_description} />
                                )}
                                {brand.description && (
                                    <InfoRow label="Описание" value={brand.description} />
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Теги */}
                {brand.tags?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Теги</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {brand.tags.map((tag, i) => (
                                    <Badge key={i} variant="outline">{tag}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* SEO */}
                {(brand.meta_title || brand.meta_description) && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">SEO</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={3} align="stretch">
                                <InfoRow label="Meta Title" value={brand.meta_title} />
                                <InfoRow label="Meta Description" value={brand.meta_description} />
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
