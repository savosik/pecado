import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, Image, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { product_selection: selection } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Подборка: ${selection.name}`} />
            <PageHeader
                title={selection.name}
                backUrl={route('admin.product-selections.index')}
                backLabel="К списку подборок"
                actions={
                    can('product-selections.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.product-selections.edit', selection.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={selection.id?.toString()} />
                            <InfoRow label="Название" value={selection.name} />
                            <InfoRow label="Slug" value={selection.slug} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">На главной</Text>
                                <Badge colorPalette={selection.show_on_home ? 'green' : 'gray'} variant="subtle">
                                    {selection.show_on_home ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Создана" value={selection.created_at} />
                            <InfoRow label="Обновлена" value={selection.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {(selection.desktop_image_url || selection.mobile_image_url) && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображения</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={4} flexWrap="wrap">
                                {selection.desktop_image_url && (
                                    <VStack gap={1}>
                                        <Image src={selection.desktop_image_url} alt="Десктоп" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Десктоп</Text>
                                    </VStack>
                                )}
                                {selection.mobile_image_url && (
                                    <VStack gap={1}>
                                        <Image src={selection.mobile_image_url} alt="Мобильное" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Мобильное</Text>
                                    </VStack>
                                )}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {selection.products?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Товары ({selection.products.length})</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={2} align="stretch">
                                {selection.products.map((p) => (
                                    <HStack key={p.id} gap={3}>
                                        {p.image_url && (
                                            <Image src={p.image_url} alt={p.name} w="40px" h="40px" objectFit="contain" borderRadius="sm" />
                                        )}
                                        <Text fontSize="sm" flex={1}>{p.name}</Text>
                                        {p.featured && <Badge colorPalette="yellow" variant="subtle" size="sm">Главный</Badge>}
                                        <Text fontSize="xs" color="gray.500">{p.brand_name}</Text>
                                    </HStack>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
