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
    const { news } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Новость: ${news.title}`} />
            <PageHeader
                title={news.title}
                backUrl={route('admin.news.index')}
                backLabel="К списку новостей"
                actions={
                    can('news.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.news.edit', news.id))}>
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
                            <InfoRow label="ID" value={news.id?.toString()} />
                            <InfoRow label="Заголовок" value={news.title} />
                            <InfoRow label="Slug" value={news.slug} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Опубликована</Text>
                                <Badge colorPalette={news.is_published ? 'green' : 'gray'} variant="subtle">
                                    {news.is_published ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Дата публикации" value={news.published_at} />
                            <InfoRow label="Meta Title" value={news.meta_title} />
                            <InfoRow label="Создана" value={news.created_at} />
                            <InfoRow label="Обновлена" value={news.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {news.tags?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Теги</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {news.tags.map((t, i) => (
                                    <Badge key={i} variant="outline">{t}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {news.regions?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Регионы</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {news.regions.map((r) => (
                                    <Badge key={r.id} variant="outline">{r.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {(news.list_image || news.detail_desktop_image || news.detail_mobile_image) && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображения</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={4} flexWrap="wrap">
                                {news.list_image && (
                                    <VStack gap={1}>
                                        <Image src={news.list_image} alt="Список" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Список</Text>
                                    </VStack>
                                )}
                                {news.detail_desktop_image && (
                                    <VStack gap={1}>
                                        <Image src={news.detail_desktop_image} alt="Десктоп" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Десктоп</Text>
                                    </VStack>
                                )}
                                {news.detail_mobile_image && (
                                    <VStack gap={1}>
                                        <Image src={news.detail_mobile_image} alt="Мобильное" w="120px" h="80px" objectFit="cover" borderRadius="md" />
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
