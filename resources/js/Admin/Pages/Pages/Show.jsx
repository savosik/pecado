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
    const { page } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Страница: ${page.title}`} />
            <PageHeader
                title={page.title}
                backUrl={route('admin.pages.index')}
                backLabel="К списку страниц"
                actions={
                    can('pages.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.pages.edit', page.id))}>
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
                            <InfoRow label="ID" value={page.id?.toString()} />
                            <InfoRow label="Заголовок" value={page.title} />
                            <InfoRow label="Slug" value={page.slug} />
                            <InfoRow label="Meta Title" value={page.meta_title} />
                            <InfoRow label="Создана" value={page.created_at} />
                            <InfoRow label="Обновлена" value={page.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {page.meta_description && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">SEO описание</Text></Card.Header>
                        <Card.Body>
                            <Text fontSize="sm">{page.meta_description}</Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {page.regions?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Регионы</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {page.regions.map((r) => (
                                    <Badge key={r.id} variant="outline">{r.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {(page.list_image || page.detail_desktop_image || page.detail_mobile_image) && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображения</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={4} flexWrap="wrap">
                                {page.list_image && (
                                    <VStack gap={1}>
                                        <Image src={page.list_image} alt="Список" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Список</Text>
                                    </VStack>
                                )}
                                {page.detail_desktop_image && (
                                    <VStack gap={1}>
                                        <Image src={page.detail_desktop_image} alt="Десктоп" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Десктоп</Text>
                                    </VStack>
                                )}
                                {page.detail_mobile_image && (
                                    <VStack gap={1}>
                                        <Image src={page.detail_mobile_image} alt="Мобильное" w="120px" h="80px" objectFit="cover" borderRadius="md" />
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
