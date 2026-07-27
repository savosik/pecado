import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, Image, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';
import PromotionRulesBlock from './Components/PromotionRulesBlock';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { promotion, rules = [] } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Акция: ${promotion.name}`} />
            <PageHeader
                title={promotion.name}
                backUrl={route('admin.promotions.index')}
                backLabel="К списку акций"
                actions={
                    can('promotions.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.promotions.edit', promotion.id))}>
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
                            <InfoRow label="ID" value={promotion.id?.toString()} />
                            <InfoRow label="Название" value={promotion.name} />
                            <InfoRow label="Meta Title" value={promotion.meta_title} />
                            <InfoRow label="Создана" value={promotion.created_at} />
                            <InfoRow label="Обновлена" value={promotion.updated_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {promotion.description && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Описание</Text></Card.Header>
                        <Card.Body>
                            <Text fontSize="sm">{promotion.description}</Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {(promotion.list_image || promotion.detail_desktop_image || promotion.detail_mobile_image) && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Изображения</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={4} flexWrap="wrap">
                                {promotion.list_image && (
                                    <VStack gap={1}>
                                        <Image src={promotion.list_image} alt="Список" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Список</Text>
                                    </VStack>
                                )}
                                {promotion.detail_desktop_image && (
                                    <VStack gap={1}>
                                        <Image src={promotion.detail_desktop_image} alt="Десктоп" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Десктоп</Text>
                                    </VStack>
                                )}
                                {promotion.detail_mobile_image && (
                                    <VStack gap={1}>
                                        <Image src={promotion.detail_mobile_image} alt="Мобильное" w="120px" h="80px" objectFit="cover" borderRadius="md" />
                                        <Text fontSize="xs" color="gray.500">Мобильное</Text>
                                    </VStack>
                                )}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {promotion.products?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Товары ({promotion.products.length})</Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={2} align="stretch">
                                {promotion.products.map((p) => (
                                    <HStack key={p.id} gap={3}>
                                        {p.image_url && (
                                            <Image src={p.image_url} alt={p.name} w="40px" h="40px" objectFit="contain" borderRadius="sm" />
                                        )}
                                        <Text fontSize="sm" flex={1}>{p.name}</Text>
                                        <Text fontSize="xs" color="gray.500">{p.brand_name}</Text>
                                    </HStack>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                <PromotionRulesBlock promotionId={promotion.id} rules={rules} />
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
