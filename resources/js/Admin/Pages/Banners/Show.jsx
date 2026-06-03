import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, Image, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

const LOCATION_LABELS = { header: 'Шапка', footer: 'Подвал', both: 'Шапка и подвал' };

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { banner } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Баннер: ${banner.title}`} />
            <PageHeader
                title={banner.title}
                backUrl={route('admin.banners.index')}
                backLabel="К списку баннеров"
                actions={
                    can('banners.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.banners.edit', banner.id))}>
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
                            <InfoRow label="ID" value={banner.id?.toString()} />
                            <InfoRow label="Заголовок" value={banner.title} />
                            <InfoRow label="Порядок" value={banner.sort_order?.toString()} />
                            <InfoRow label="Ссылка на" value={banner.linkable_name} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Активен</Text>
                                <Badge colorPalette={banner.is_active ? 'green' : 'gray'} variant="subtle">
                                    {banner.is_active ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Создан" value={banner.created_at} />
                            <InfoRow label="Обновлён" value={banner.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {banner.regions?.length > 0 && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Регионы</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {banner.regions.map((r) => (
                                    <Badge key={r.id} variant="outline">{r.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {(banner.desktop_image || banner.mobile_image) && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображения</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={4} flexWrap="wrap">
                                {banner.desktop_image && (
                                    <VStack gap={1}>
                                        <Image src={banner.desktop_image} alt="Десктоп" w="200px" h="100px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Десктоп</Text>
                                    </VStack>
                                )}
                                {banner.mobile_image && (
                                    <VStack gap={1}>
                                        <Image src={banner.mobile_image} alt="Мобильное" w="100px" h="100px" objectFit="cover" borderRadius="md" />
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
