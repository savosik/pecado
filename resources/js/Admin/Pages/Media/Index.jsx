import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, SearchInput, ConfirmDialog, Pagination } from '@/Admin/Components';
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
    Stack,
    NativeSelectRoot,
    NativeSelectField,
    IconButton,
    CloseButton,
    Dialog,
    Portal,
} from '@chakra-ui/react';
import { LuTrash2, LuImage, LuVideo, LuFileText, LuFile, LuEye, LuDownload } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ media, filters, collections, modelTypes }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [mediaToDelete, setMediaToDelete] = useState(null);
    const [previewItem, setPreviewItem] = useState(null);
    const [previewOpen, setPreviewOpen] = useState(false);

    const handlePreview = (item) => {
        setPreviewItem(item);
        setPreviewOpen(true);
    };

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
            'App\\Models\\Brand': 'Бренд',
            'App\\Models\\BrandStory': 'История бренда',
            'App\\Models\\Story': 'Сторис',
            'App\\Models\\StorySlide': 'Слайд сторис',
            'App\\Models\\Certificate': 'Сертификат',
            'App\\Models\\ProductSelection': 'Подборка',
            'App\\Models\\User': 'Пользователь',
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
                    placeholder="Поиск по товарам, брендам, категориям, статьям, новостям..."
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
                        <Box
                            position="relative"
                            aspectRatio="4/3"
                            bg="gray.100"
                            _dark={{ bg: 'gray.800' }}
                            cursor="pointer"
                            onClick={() => handlePreview(item)}
                            _hover={{ opacity: 0.85 }}
                            transition="opacity 0.2s"
                        >
                            {item.mime_type?.startsWith('image/') ? (
                                <Image
                                    src={item.thumbnail_url || item.original_url}
                                    alt={item.name}
                                    width="100%"
                                    height="100%"
                                    objectFit="cover"
                                />
                            ) : item.mime_type?.startsWith('video/') ? (
                                <VStack height="100%" justify="center" align="center" color="gray.400" position="relative">
                                    <Box fontSize="4xl">
                                        <LuVideo />
                                    </Box>
                                    <Text fontSize="xs" color="gray.500">Нажмите для просмотра</Text>
                                </VStack>
                            ) : (
                                <VStack height="100%" justify="center" align="center" color="gray.400">
                                    <Box fontSize="4xl">
                                        {getMimeTypeIcon(item.mime_type)}
                                    </Box>
                                </VStack>
                            )}
                            {/* Иконка просмотра при наведении */}
                            <Box
                                position="absolute"
                                top={2}
                                right={2}
                                bg="blackAlpha.600"
                                borderRadius="full"
                                p={1}
                                opacity={0}
                                _groupHover={{ opacity: 1 }}
                                transition="opacity 0.2s"
                            >
                                <LuEye color="white" size={16} />
                            </Box>
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
                                    {item.owner_display_name && `: ${item.owner_display_name}`}
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
            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden" mt={6}>
                <Pagination pagination={media} onPageChange={handlePageChange} />
            </Box>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                onConfirm={confirmDelete}
                title="Удалить медиафайл?"
                description={`Вы уверены, что хотите удалить файл "${mediaToDelete?.file_name}"? Это действие нельзя отменить.`}
            />

            {/* Диалог просмотра медиафайла */}
            <Dialog.Root
                open={previewOpen}
                onOpenChange={({ open }) => {
                    setPreviewOpen(open);
                    if (!open) setPreviewItem(null);
                }}
                size="xl"
                placement="center"
            >
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content maxW="900px">
                            <Dialog.Header>
                                <Dialog.Title>{previewItem?.file_name}</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.CloseTrigger />
                            <Dialog.Body pb={6}>
                                {previewItem && (
                                    <VStack gap={4}>
                                        {previewItem.mime_type?.startsWith('video/') ? (
                                            <Box width="100%" borderRadius="md" overflow="hidden">
                                                <video
                                                    src={previewItem.original_url}
                                                    controls
                                                    autoPlay
                                                    style={{
                                                        width: '100%',
                                                        maxHeight: '70vh',
                                                        borderRadius: '8px',
                                                        backgroundColor: '#000',
                                                    }}
                                                />
                                            </Box>
                                        ) : previewItem.mime_type?.startsWith('image/') ? (
                                            <Image
                                                src={previewItem.original_url}
                                                alt={previewItem.name}
                                                maxH="70vh"
                                                objectFit="contain"
                                                borderRadius="md"
                                            />
                                        ) : (
                                            <VStack py={8} gap={4} color="gray.500">
                                                <Box fontSize="6xl">
                                                    {getMimeTypeIcon(previewItem.mime_type)}
                                                </Box>
                                                <Text>Предпросмотр недоступен для данного типа файла</Text>
                                            </VStack>
                                        )}

                                        <HStack gap={3} flexWrap="wrap" justify="center">
                                            <Badge size="sm" colorPalette="blue">
                                                {formatFileSize(previewItem.size)}
                                            </Badge>
                                            <Badge size="sm" colorPalette="gray">
                                                {previewItem.mime_type}
                                            </Badge>
                                            {previewItem.collection_name && (
                                                <Badge size="sm" colorPalette="purple">
                                                    {previewItem.collection_name}
                                                </Badge>
                                            )}
                                            {previewItem.model_type && (
                                                <Badge size="sm" colorPalette="green">
                                                    {getModelTypeLabel(previewItem.model_type)}
                                                    {previewItem.owner_display_name && `: ${previewItem.owner_display_name}`}
                                                </Badge>
                                            )}
                                        </HStack>

                                        <Button
                                            as="a"
                                            href={previewItem.original_url}
                                            target="_blank"
                                            download
                                            variant="outline"
                                            size="sm"
                                        >
                                            <LuDownload />
                                            Скачать файл
                                        </Button>
                                    </VStack>
                                )}
                            </Dialog.Body>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
