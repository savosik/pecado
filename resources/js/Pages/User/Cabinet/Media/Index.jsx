import { useState, useEffect, useCallback, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import MediaCard from './MediaCard';
import MediaLightbox from './MediaLightbox';
import {
    Box, Flex, HStack, VStack, Text, SimpleGrid, Input, Button, Badge,
    NativeSelectRoot, NativeSelectField, Stack, Spinner, Checkbox,
    IconButton,
} from '@chakra-ui/react';
import {
    LuSearch, LuDownload, LuImage, LuX, LuSquareCheck,
    LuChevronLeft, LuChevronRight,
} from 'react-icons/lu';

const MODEL_TYPE_LABELS = {
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

const SORT_OPTIONS = [
    { value: 'newest', label: 'Сначала новые' },
    { value: 'oldest', label: 'Сначала старые' },
    { value: 'size_desc', label: 'По размеру ↓' },
    { value: 'size_asc', label: 'По размеру ↑' },
    { value: 'name_asc', label: 'По имени А-Я' },
    { value: 'name_desc', label: 'По имени Я-А' },
];

export default function Index({ collections, modelTypes }) {
    const [media, setMedia] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [loading, setLoading] = useState(true);
    const [selectedMedia, setSelectedMedia] = useState(new Set());
    const [downloading, setDownloading] = useState(false);

    // Filters
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [type, setType] = useState('');
    const [collection, setCollection] = useState('');
    const [modelType, setModelType] = useState('');
    const [sort, setSort] = useState('newest');
    const [page, setPage] = useState(1);
    const perPage = 24;

    // Lightbox
    const [lightboxOpen, setLightboxOpen] = useState(false);
    const [lightboxIndex, setLightboxIndex] = useState(0);

    // Debounce search input
    useEffect(() => {
        const timeout = setTimeout(() => {
            setDebouncedSearch(search.trim());
            setPage(1);
        }, 300);
        return () => clearTimeout(timeout);
    }, [search]);

    // Fetch media when filters change
    const fetchMedia = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (debouncedSearch) params.set('search', debouncedSearch);
            if (type) params.set('type', type);
            if (collection) params.set('collection', collection);
            if (modelType) params.set('model_type', modelType);
            if (sort) params.set('sort', sort);
            params.set('per_page', perPage.toString());
            params.set('page', page.toString());

            const response = await fetch(`/cabinet/media/api?${params.toString()}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();

            setMedia(data.data || []);
            setPagination({
                current: data.current_page,
                last: data.last_page,
                total: data.total,
                from: data.from,
                to: data.to,
            });
        } catch (error) {
            console.error('Ошибка загрузки медиа:', error);
            setMedia([]);
            setPagination(null);
        } finally {
            setLoading(false);
        }
    }, [debouncedSearch, type, collection, modelType, sort, page]);

    useEffect(() => {
        fetchMedia();
    }, [fetchMedia]);

    // Selection handlers
    const toggleSelection = useCallback((mediaId) => {
        setSelectedMedia(prev => {
            const next = new Set(prev);
            next.has(mediaId) ? next.delete(mediaId) : next.add(mediaId);
            return next;
        });
    }, []);

    const selectAll = useCallback(() => {
        setSelectedMedia(new Set(media.map(m => m.id)));
    }, [media]);

    const deselectAll = useCallback(() => {
        setSelectedMedia(new Set());
    }, []);

    // Download batch
    const handleDownloadBatch = useCallback(async () => {
        if (selectedMedia.size === 0 || downloading) return;

        setDownloading(true);
        try {
            const response = await fetch('/cabinet/media/download-batch', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ ids: Array.from(selectedMedia) }),
            });

            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                const contentDisposition = response.headers.get('content-disposition');
                const filename = contentDisposition
                    ? contentDisposition.split('filename=')[1]?.replace(/"/g, '') || 'media.zip'
                    : 'media.zip';
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            }
        } catch (error) {
            console.error('Ошибка скачивания:', error);
        } finally {
            setDownloading(false);
        }
    }, [selectedMedia, downloading]);

    // Lightbox
    const openLightbox = useCallback((index) => {
        setLightboxIndex(index);
        setLightboxOpen(true);
    }, []);

    // Active filters count
    const activeFiltersCount = [type, collection, modelType].filter(Boolean).length;

    const resetFilters = () => {
        setSearch('');
        setType('');
        setCollection('');
        setModelType('');
        setSort('newest');
        setPage(1);
    };

    const hasSelection = selectedMedia.size > 0;

    // Pluralize файл
    const pluralizeFiles = (n) => {
        const mod10 = n % 10;
        const mod100 = n % 100;
        if (mod10 === 1 && mod100 !== 11) return 'файл';
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'файла';
        return 'файлов';
    };

    return (
        <CabinetLayout title="Медиатека">
            <Head title="Медиатека" />

            <VStack gap="5" align="stretch">
                {/* Search bar */}
                <Box position="relative">
                    <Box position="absolute" left="4" top="50%" transform="translateY(-50%)" color="gray.400" zIndex="1">
                        <LuSearch size={18} />
                    </Box>
                    <Input
                        pl="12"
                        pr={search ? '12' : '4'}
                        py="5"
                        size="lg"
                        placeholder="Поиск по товарам, брендам, артикулам, категориям..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        borderRadius="xl"
                        bg="white"
                        _dark={{ bg: 'gray.800' }}
                        borderColor="gray.200"
                        _focus={{ borderColor: 'pecado.500', boxShadow: '0 0 0 1px var(--chakra-colors-pecado-500)' }}
                    />
                    {search && (
                        <IconButton
                            aria-label="Очистить поиск"
                            variant="ghost"
                            size="sm"
                            position="absolute"
                            right="3"
                            top="50%"
                            transform="translateY(-50%)"
                            color="gray.400"
                            _hover={{ color: 'gray.600' }}
                            onClick={() => setSearch('')}
                        >
                            <LuX />
                        </IconButton>
                    )}
                </Box>

                {/* Filters panel */}
                <Box
                    bg="white"
                    _dark={{ bg: 'gray.800' }}
                    borderRadius="xl"
                    border="1px solid"
                    borderColor="gray.200"
                    _darkBorderColor="gray.700"
                    p="4"
                    shadow="sm"
                >
                    <SimpleGrid columns={{ base: 1, sm: 2, md: 4 }} gap="3">
                        <Box>
                            <Text fontSize="xs" fontWeight="600" mb="1" color="gray.500" textTransform="uppercase">
                                Тип файла
                            </Text>
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={type}
                                    onChange={(e) => { setType(e.target.value); setPage(1); }}
                                >
                                    <option value="">Все типы</option>
                                    <option value="images">Изображения</option>
                                    <option value="videos">Видео</option>
                                    <option value="documents">Документы</option>
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </Box>

                        <Box>
                            <Text fontSize="xs" fontWeight="600" mb="1" color="gray.500" textTransform="uppercase">
                                Коллекция
                            </Text>
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={collection}
                                    onChange={(e) => { setCollection(e.target.value); setPage(1); }}
                                >
                                    <option value="">Все коллекции</option>
                                    {collections.map((c) => (
                                        <option key={c} value={c}>{c}</option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </Box>

                        <Box>
                            <Text fontSize="xs" fontWeight="600" mb="1" color="gray.500" textTransform="uppercase">
                                Тип сущности
                            </Text>
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={modelType}
                                    onChange={(e) => { setModelType(e.target.value); setPage(1); }}
                                >
                                    <option value="">Все сущности</option>
                                    {modelTypes.map((mt) => (
                                        <option key={mt} value={mt}>{MODEL_TYPE_LABELS[mt] || mt.split('\\').pop()}</option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </Box>

                        <Box>
                            <Text fontSize="xs" fontWeight="600" mb="1" color="gray.500" textTransform="uppercase">
                                Сортировка
                            </Text>
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={sort}
                                    onChange={(e) => { setSort(e.target.value); setPage(1); }}
                                >
                                    {SORT_OPTIONS.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </Box>
                    </SimpleGrid>
                </Box>

                {/* Active filters chips */}
                {activeFiltersCount > 0 && (
                    <HStack gap="2" flexWrap="wrap">
                        {type && (
                            <Badge size="sm" colorPalette="blue" cursor="pointer" onClick={() => setType('')}>
                                {type === 'images' ? 'Изображения' : type === 'videos' ? 'Видео' : 'Документы'}
                                <Box as="span" ml="1" fontWeight="bold">×</Box>
                            </Badge>
                        )}
                        {collection && (
                            <Badge size="sm" colorPalette="purple" cursor="pointer" onClick={() => setCollection('')}>
                                {collection} <Box as="span" ml="1" fontWeight="bold">×</Box>
                            </Badge>
                        )}
                        {modelType && (
                            <Badge size="sm" colorPalette="green" cursor="pointer" onClick={() => setModelType('')}>
                                {MODEL_TYPE_LABELS[modelType] || modelType.split('\\').pop()}
                                <Box as="span" ml="1" fontWeight="bold">×</Box>
                            </Badge>
                        )}
                        <Button size="xs" variant="ghost" onClick={resetFilters}>
                            Сбросить все
                        </Button>
                    </HStack>
                )}

                {/* Selection bar */}
                {hasSelection && (
                    <Flex
                        align="center"
                        justify="space-between"
                        gap="3"
                        p="3"
                        bg="blue.50"
                        _dark={{ bg: 'blue.900/20' }}
                        borderRadius="xl"
                        border="1px solid"
                        borderColor="blue.200"
                        _darkBorderColor="blue.800"
                    >
                        <HStack gap="3">
                            <Text fontSize="sm" fontWeight="600" color="blue.800" _dark={{ color: 'blue.200' }}>
                                Выделено: {selectedMedia.size} {pluralizeFiles(selectedMedia.size)}
                            </Text>
                            <Button size="xs" variant="outline" onClick={selectAll}>
                                Выделить все
                            </Button>
                            <Button size="xs" variant="outline" onClick={deselectAll}>
                                Снять
                            </Button>
                        </HStack>
                        <Button
                            size="sm"
                            colorPalette="blue"
                            onClick={handleDownloadBatch}
                            disabled={downloading}
                        >
                            {downloading ? (
                                <><Spinner size="xs" mr="2" /> Загрузка...</>
                            ) : (
                                <><LuDownload size={14} /> <Text ml="1">Скачать ZIP</Text></>
                            )}
                        </Button>
                    </Flex>
                )}

                {/* Results info */}
                {pagination && !loading && (
                    <Flex justify="space-between" align="center">
                        <Text fontSize="sm" color="gray.500">
                            {pagination.total > 0
                                ? `Показано ${pagination.from}–${pagination.to} из ${pagination.total} ${pluralizeFiles(pagination.total)}`
                                : 'Ничего не найдено'
                            }
                        </Text>
                        {!hasSelection && media.length > 0 && (
                            <Button size="xs" variant="ghost" onClick={selectAll} color="gray.500">
                                <LuSquareCheck size={14} />
                                <Text ml="1">Выделить все</Text>
                            </Button>
                        )}
                    </Flex>
                )}

                {/* Content */}
                {loading ? (
                    <Flex align="center" justify="center" py="16" gap="3">
                        <Spinner size="lg" color="pecado.500" />
                        <Text color="gray.500">Загрузка медиатеки...</Text>
                    </Flex>
                ) : media.length === 0 ? (
                    <VStack py="16" gap="4" color="gray.400">
                        <Box fontSize="6xl"><LuImage /></Box>
                        <Text fontSize="lg" fontWeight="600" color="gray.500">Медиафайлы не найдены</Text>
                        <Text fontSize="sm" color="gray.400" textAlign="center" maxW="md">
                            {debouncedSearch
                                ? 'Попробуйте изменить поисковый запрос или сбросить фильтры'
                                : 'В системе пока нет загруженных медиафайлов'
                            }
                        </Text>
                        {(debouncedSearch || activeFiltersCount > 0) && (
                            <Button size="sm" variant="outline" onClick={resetFilters}>
                                Сбросить фильтры
                            </Button>
                        )}
                    </VStack>
                ) : (
                    <>
                        {/* Media grid */}
                        <SimpleGrid columns={{ base: 2, sm: 3, md: 3, lg: 4 }} gap="4">
                            {media.map((item, index) => (
                                <MediaCard
                                    key={item.id}
                                    item={item}
                                    selected={selectedMedia.has(item.id)}
                                    onToggleSelection={toggleSelection}
                                    onOpenLightbox={() => openLightbox(index)}
                                />
                            ))}
                        </SimpleGrid>

                        {/* Pagination */}
                        {pagination && pagination.last > 1 && (
                            <Flex justify="center" align="center" gap="2" pt="4">
                                <IconButton
                                    aria-label="Предыдущая"
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.current <= 1}
                                    onClick={() => { setPage(pagination.current - 1); window.scrollTo({ top: 0, behavior: 'smooth' }); }}
                                >
                                    <LuChevronLeft />
                                </IconButton>

                                {generatePageNumbers(pagination.current, pagination.last).map((p, i) =>
                                    p === '...' ? (
                                        <Text key={`dots-${i}`} color="gray.400" px="1">...</Text>
                                    ) : (
                                        <Button
                                            key={p}
                                            variant={p === pagination.current ? 'solid' : 'outline'}
                                            colorPalette={p === pagination.current ? 'pecado' : 'gray'}
                                            size="sm"
                                            minW="9"
                                            onClick={() => { setPage(p); window.scrollTo({ top: 0, behavior: 'smooth' }); }}
                                        >
                                            {p}
                                        </Button>
                                    )
                                )}

                                <IconButton
                                    aria-label="Следующая"
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.current >= pagination.last}
                                    onClick={() => { setPage(pagination.current + 1); window.scrollTo({ top: 0, behavior: 'smooth' }); }}
                                >
                                    <LuChevronRight />
                                </IconButton>
                            </Flex>
                        )}
                    </>
                )}
            </VStack>

            {/* Lightbox */}
            {lightboxOpen && media.length > 0 && (
                <MediaLightbox
                    media={media}
                    initialIndex={lightboxIndex}
                    isOpen={lightboxOpen}
                    onClose={() => setLightboxOpen(false)}
                />
            )}
        </CabinetLayout>
    );
}

/**
 * Generate page numbers with ellipsis for pagination.
 */
function generatePageNumbers(current, last) {
    const pages = [];
    const delta = 2;

    pages.push(1);

    if (current - delta > 2) {
        pages.push('...');
    }

    for (let i = Math.max(2, current - delta); i <= Math.min(last - 1, current + delta); i++) {
        pages.push(i);
    }

    if (current + delta < last - 1) {
        pages.push('...');
    }

    if (last > 1) {
        pages.push(last);
    }

    return pages;
}
