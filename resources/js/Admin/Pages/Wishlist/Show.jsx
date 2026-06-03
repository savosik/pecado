import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Card, HStack, Image, SimpleGrid, Text, VStack } from '@chakra-ui/react';
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
    const { wishlistItem } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Список желаний #${wishlistItem.id}`} />
            <PageHeader
                title={`Список желаний #${wishlistItem.id}`}
                backUrl={route('admin.wishlist.index')}
                backLabel="К списку желаний"
                actions={
                    can('wishlist.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.wishlist.edit', wishlistItem.id))}>
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
                            <InfoRow label="ID" value={wishlistItem.id?.toString()} />
                            <InfoRow label="Пользователь" value={wishlistItem.user ? `${wishlistItem.user.name} (${wishlistItem.user.email})` : null} />
                            <InfoRow label="Добавлено" value={wishlistItem.created_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {wishlistItem.product && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Товар</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={4}>
                                {wishlistItem.product.image_url && (
                                    <Image src={wishlistItem.product.image_url} alt={wishlistItem.product.name} w="80px" h="80px" objectFit="contain" borderRadius="md" borderWidth="1px" borderColor="gray.200" />
                                )}
                                <SimpleGrid columns={2} gap={3}>
                                    <InfoRow label="Название" value={wishlistItem.product.name} />
                                    <InfoRow label="Артикул" value={wishlistItem.product.sku} />
                                </SimpleGrid>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
