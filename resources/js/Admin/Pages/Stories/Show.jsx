import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box, Card, HStack, VStack, Text, Badge, SimpleGrid,
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
    const { story } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Сторис: ${story.name}`} />

            <PageHeader
                title={story.name}
                backUrl={route('admin.stories.index')}
                backLabel="К списку сторисов"
                actions={
                    can('stories.edit') && (
                        <Button
                            size="sm"
                            onClick={() => router.visit(route('admin.stories.edit', story.id))}
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
                            <InfoRow label="Название" value={story.name} />
                            <InfoRow label="Slug" value={story.slug} />
                            <InfoRow label="Порядок сортировки" value={story.sort_order?.toString()} />
                            <InfoRow label="Количество слайдов" value={story.slides_count?.toString()} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {/* Статусы */}
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Статусы</Text>
                    </Card.Header>
                    <Card.Body>
                        <HStack gap={2} flexWrap="wrap">
                            <Badge colorPalette={story.is_active ? 'green' : 'gray'} variant="subtle">
                                {story.is_active ? 'Активен' : 'Неактивен'}
                            </Badge>
                            <Badge colorPalette={story.is_published ? 'blue' : 'gray'} variant="subtle">
                                {story.is_published ? 'Опубликован' : 'Не опубликован'}
                            </Badge>
                            {story.show_name && (
                                <Badge colorPalette="teal" variant="subtle">Показывать название</Badge>
                            )}
                        </HStack>
                    </Card.Body>
                </Card.Root>

                {/* Регионы */}
                {story.regions?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Регионы</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {story.regions.map((region) => (
                                    <Badge key={region.id} variant="outline">{region.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
