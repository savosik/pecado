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
    const { brandStory } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`История бренда: ${brandStory.title}`} />
            <PageHeader
                title={brandStory.title}
                backUrl={route('admin.brand-stories.index')}
                backLabel="К списку историй брендов"
                actions={
                    can('brand-stories.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.brand-stories.edit', brandStory.id))}>
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
                            <InfoRow label="ID" value={brandStory.id?.toString()} />
                            <InfoRow label="Заголовок" value={brandStory.title} />
                            <InfoRow label="Slug" value={brandStory.slug} />
                            <InfoRow label="Бренд" value={brandStory.brand?.name} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Опубликована</Text>
                                <Badge colorPalette={brandStory.is_published ? 'green' : 'gray'} variant="subtle">
                                    {brandStory.is_published ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Дата публикации" value={brandStory.published_at} />
                            <InfoRow label="Создана" value={brandStory.created_at} />
                            <InfoRow label="Обновлена" value={brandStory.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {brandStory.tags?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Теги</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {brandStory.tags.map((t, i) => (
                                    <Badge key={i} variant="outline">{t}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {brandStory.regions?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Регионы</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {brandStory.regions.map((r) => (
                                    <Badge key={r.id} variant="outline">{r.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {(brandStory.list_image || brandStory.detail_desktop_image || brandStory.detail_mobile_image) && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображения</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={4} flexWrap="wrap">
                                {brandStory.list_image && (
                                    <VStack gap={1}>
                                        <Image src={brandStory.list_image} alt="Список" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Список</Text>
                                    </VStack>
                                )}
                                {brandStory.detail_desktop_image && (
                                    <VStack gap={1}>
                                        <Image src={brandStory.detail_desktop_image} alt="Десктоп" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Десктоп</Text>
                                    </VStack>
                                )}
                                {brandStory.detail_mobile_image && (
                                    <VStack gap={1}>
                                        <Image src={brandStory.detail_mobile_image} alt="Мобильное" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Мобильное</Text>
                                    </VStack>
                                )}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
