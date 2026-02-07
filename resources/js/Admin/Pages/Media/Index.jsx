import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, SearchInput, ConfirmDialog } from '@/Admin/Components';
import {
    Card,
    SimpleGrid,
    Image,
    Text,
    Button,
    HStack,
    VStack,
    Badge,
    Box,
    NativeSelectRoot,
    NativeSelectField,
    Stack,
} from '@chakra-ui/react';
import { LuTrash2, LuImage, LuVideo, LuFileText, LuFile } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ media, filters, collections, modelTypes }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [mediaToDelete, setMediaToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.media.index'),
            { ...filters, search: value, page: 1 },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleFilterChange = (key, value) => {
        router.get(route('admin.media.index'),
            { ...filters, [key]: value, page: 1 },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleDelete = (item) => {
        setMediaToDelete(item);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (mediaToDelete) {
            router.delete(route('admin.media.destroy', mediaToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Медиафайл успешно удален',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setMediaToDelete(null);
                },
            });
        }
    };

    const formatFileSize = (bytes) => {
        if (!bytes) return '0 B';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const getMimeTypeIcon = (mimeType) => {
        if (!mimeType) return <LuFile />;
        if (mimeType.startsWith('image/')) return <LuImage />;
        if (mimeType.startsWith('video/')) return <LuVideo />;
        if (mimeType.includes('pdf')) return <LuFileText />;
        return <LuFile />;
    };

    const getModelTypeLabel = (modelType) => {
        if (!modelType) return null;
        const typeMap = {
            'App\\Models\\Product': 'Товар',
            'App\\Models\\Article': 'Статья',
            'App\\Models\\Banner': 'Баннер',
            'App\\Models\\Page': 'Страница',
            'App\\Models\\News': 'Новость',
            'App\\Models\\Category': 'Категория',
            'App\\Models\\Promotion': 'Акция',
        };
        return typeMap[modelType] || modelType.split('\\').pop();
    };

    const handlePageChange = (page) => {
        router.get(route('admin.media.index'),
            { ...filters, page },
            { preserveState: true, preserveScroll: true }
        );
    };

    return (
        <>
            <PageHeader
                title="Медиафайлы"
                description={`Всего: ${media.total} файлов`}
            />

            <Stack gap={4} mb={6}>
                <SearchInput
                    value={search}
                    onChange={handleSearch}
                    placeholder="Поиск по названию или имени файла..."
                />

                <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                    <Box>
                        <Text fontSize="sm" fontWeight="medium" mb={2}>
                            Тип файла
                        </Text>
                        <NativeSelectRoot>
                            <NativeSelectField
                                value={filters.type || ''}
                                onChange={(e) => handleFilterChange('type', e.target.value)}
                            >
                                <option value="">Все типы</option>
                                <option value="images">Изображения</option>
                                <option value="videos">Видео</option>
                                <option value="documents">Документы</option>
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>

                    <Box>
                        <Text fontSize="sm" fontWeight="medium" mb={2}>
                            Коллекция
                        </Text>
                        <NativeSelectRoot>
                            <NativeSelectField
                                value={filters.collection || ''}
                                onChange={(e) => handleFilterChange('collection', e.target.value)}
                            >
                                <option value="">Все коллекции</option>
                                {collections.map((collection) => (
                                    <option key={collection} value={collection}>
                                        {collection}
                                    </option>
                                ))}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>

                    <Box>
                        <Text fontSize="sm" fontWeight="medium" mb={2}>
                            Модель
                        </Text>
                        <NativeSelectRoot>
                            <NativeSelectField
                                value={filters.model_type || ''}
                                onChange={(e) => handleFilterChange('model_type', e.target.value)}
                            >
                                <option value="">Все модели</option>
                                {modelTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {getModelTypeLabel(type)}
                                    </option>
                                ))}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>
                </SimpleGrid>
            </Stack>

            <SimpleGrid columns={{ base: 1, sm: 2, md: 3, lg: 4 }} gap={4} mb={6}>
                {media.data.map((item) => (
                    <Card.Root key={item.id} overflow="hidden">
                        <Box position="relative" aspectRatio="4/3" bg="gray.100" _dark={{ bg: 'gray.800' }}>
                            {item.mime_type?.startsWith('image/') ? (
                                <Image
                                    src={item.preview_url}
                                    alt={item.name}
                                    width="100%"
                                    height="100%"
                                    objectFit="cover"
                                />
                            ) : (
                                <VStack height="100%" justify="center" align="center" color="gray.400">
                                    <Box fontSize="4xl">
                                        {getMimeTypeIcon(item.mime_type)}
                                    </Box>
                                </VStack>
                            )}
                        </Box>

                        <Card.Body gap={2}>
                            <Text
                                fontSize="sm"
                                fontWeight="semibold"
                                noOfLines={1}
                                title={item.file_name}
                            >
                                {item.file_name}
                            </Text>

                            <HStack flexWrap="wrap" gap={1}>
                                <Badge size="sm" colorPalette="blue">
                                    {formatFileSize(item.size)}
                                </Badge>
                                {item.collection_name && (
                                    <Badge size="sm" colorPalette="purple">
                                        {item.collection_name}
                                    </Badge>
                                )}
                            </HStack>

                            {item.model_type && (
                                <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.400' }}>
                                    {getModelTypeLabel(item.model_type)}
                                    {item.model_name && `: ${item.model_name}`}
                                </Text>
                            )}

                            <Text fontSize="xs" color="gray.500">
                                {new Date(item.created_at).toLocaleDateString('ru-RU')}
                            </Text>

                            <Button
                                size="sm"
                                width="full"
                                colorPalette="red"
                                variant="outline"
                                onClick={() => handleDelete(item)}
                            >
                                <LuTrash2 />
                                Удалить
                            </Button>
                        </Card.Body>
                    </Card.Root>
                ))}
            </SimpleGrid>

            {media.data.length === 0 && (
                <Card.Root>
                    <Card.Body>
                        <VStack py={8} color="gray.500">
                            <Box fontSize="4xl">
                                <LuImage />
                            </Box>
                            <Text>Медиафайлы не найдены</Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            )}

            {/* Пагинация */}
            {media.last_page > 1 && (
                <HStack justify="center" mt={6} gap={2}>
                    <Button
                        size="sm"
                        disabled={!media.prev_page_url}
                        onClick={() => handlePageChange(media.current_page - 1)}
                    >
                        Назад
                    </Button>

                    <Text fontSize="sm" color="gray.600" _dark={{ color: 'gray.400' }}>
                        Страница {media.current_page} из {media.last_page}
                    </Text>

                    <Button
                        size="sm"
                        disabled={!media.next_page_url}
                        onClick={() => handlePageChange(media.current_page + 1)}
                    >
                        Вперёд
                    </Button>
                </HStack>
            )}

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                onConfirm={confirmDelete}
                title="Удалить медиафайл?"
                description={`Вы уверены, что хотите удалить файл "${mediaToDelete?.file_name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
