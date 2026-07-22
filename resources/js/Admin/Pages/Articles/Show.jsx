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
    const { article } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Статья: ${article.title}`} />
            <PageHeader
                title={article.title}
                backUrl={route('admin.articles.index')}
                backLabel="К списку статей"
                actions={
                    can('articles.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.articles.edit', article.id))}>
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
                            <InfoRow label="ID" value={article.id?.toString()} />
                            <InfoRow label="Заголовок" value={article.title} />
                            <InfoRow label="Slug" value={article.slug} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Активность</Text>
                                <Badge colorPalette={article.is_published ? 'green' : 'gray'} variant="subtle">
                                    {article.is_published ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Дата публикации" value={article.published_at} />
                            <InfoRow label="Meta Title" value={article.meta_title} />
                            <InfoRow label="Создана" value={article.created_at} />
                            <InfoRow label="Обновлена" value={article.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {article.tags?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Теги</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {article.tags.map((t, i) => (
                                    <Badge key={i} variant="outline">{t}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {article.regions?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Регионы</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {article.regions.map((r) => (
                                    <Badge key={r.id} variant="outline">{r.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {(article.list_image || article.detail_desktop_image || article.detail_mobile_image) && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображения</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={4} flexWrap="wrap">
                                {article.list_image && (
                                    <VStack gap={1}>
                                        <Image src={article.list_image} alt="Список" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Список</Text>
                                    </VStack>
                                )}
                                {article.detail_desktop_image && (
                                    <VStack gap={1}>
                                        <Image src={article.detail_desktop_image} alt="Десктоп" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Десктоп</Text>
                                    </VStack>
                                )}
                                {article.detail_mobile_image && (
                                    <VStack gap={1}>
                                        <Image src={article.detail_mobile_image} alt="Мобильное" w="120px" h="80px" objectFit="cover" borderRadius="md" />
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
