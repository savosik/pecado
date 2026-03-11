import { useRef, useEffect } from 'react';
import {
    Box, Flex, Text, IconButton, Button, Switch,
} from '@chakra-ui/react';
import { LuChevronLeft, LuChevronRight } from 'react-icons/lu';
import { pluralGoods } from '@/utils/plural';

/**
 * ProductPagination — пагинация каталога товаров.
 *
 * Поддерживает два режима:
 * 1. Классическая пагинация (номера страниц, ‹ ›)
 * 2. Бесконечная прокрутка (кнопка «Загрузить ещё» + IntersectionObserver)
 *
 * @param {{
 *   meta: { current_page, last_page, from, to, total, per_page },
 *   onPageChange: (page: number) => void,
 *   onLoadMore: () => void,
 *   loadingMore: boolean,
 *   infiniteScroll: boolean,
 *   onInfiniteScrollToggle: (enabled: boolean) => void,
 * }} props
 */
export default function ProductPagination({
    meta,
    onPageChange,
    onLoadMore,
    loadingMore = false,
    infiniteScroll = false,
    onInfiniteScrollToggle,
}) {
    if (!meta || meta.last_page <= 1) return null;

    const { current_page, last_page, from, to, total } = meta;

    return (
        <Box mt="8">
            {/* Информация о показанных товарах */}
            <Text fontSize="sm" color="gray.400" textAlign="center" mb="3">
                Показано {from}–{to} из {total} {pluralGoods(total)}
            </Text>

            {infiniteScroll ? (
                /* ─── Infinite Scroll mode ─── */
                <InfiniteScrollControls
                    currentPage={current_page}
                    lastPage={last_page}
                    onLoadMore={onLoadMore}
                    loadingMore={loadingMore}
                />
            ) : (
                /* ─── Classic pagination ─── */
                <ClassicPagination
                    currentPage={current_page}
                    lastPage={last_page}
                    onPageChange={onPageChange}
                />
            )}

            {/* Переключатель бесконечной прокрутки */}
            <Flex
                align="center"
                justify="center"
                gap="2"
                mt="4"
                pt="4"
                borderTop="1px solid"
                borderColor="gray.100"
                _dark={{ borderColor: 'gray.700' }}
            >
                <Switch.Root
                    checked={infiniteScroll}
                    onCheckedChange={(e) => onInfiniteScrollToggle(e.checked)}
                    size="sm"
                    colorPalette="pecado"
                >
                    <Switch.HiddenInput />
                    <Switch.Control>
                        <Switch.Thumb />
                    </Switch.Control>
                    <Switch.Label>
                        <Text fontSize="sm" color="gray.500">
                            Бесконечная прокрутка
                        </Text>
                    </Switch.Label>
                </Switch.Root>
            </Flex>
        </Box>
    );
}

/**
 * ClassicPagination — номера страниц с многоточиями.
 */
function ClassicPagination({ currentPage, lastPage, onPageChange }) {
    // Генерация видимых страниц (≤5 + многоточия)
    const pages = buildPageNumbers(currentPage, lastPage);

    return (
        <>
            {/* Desktop: полная пагинация */}
            <Flex
                align="center"
                justify="center"
                gap="1"
                display={{ base: 'none', md: 'flex' }}
            >
                <IconButton
                    aria-label="Предыдущая"
                    variant="ghost"
                    size="sm"
                    disabled={currentPage === 1}
                    onClick={() => onPageChange(currentPage - 1)}
                >
                    <LuChevronLeft />
                </IconButton>

                {pages.map((page, idx) =>
                    page === '...' ? (
                        <Text key={`dots-${idx}`} px="2" fontSize="sm" color="gray.400">
                            …
                        </Text>
                    ) : (
                        <IconButton
                            key={page}
                            size="sm"
                            variant={page === currentPage ? 'solid' : 'ghost'}
                            colorPalette={page === currentPage ? 'pecado' : 'gray'}
                            borderRadius="lg"
                            minW="9"
                            fontWeight="600"
                            onClick={() => onPageChange(page)}
                            aria-label={`Страница ${page}`}
                            aria-current={page === currentPage ? 'page' : undefined}
                        >
                            {page}
                        </IconButton>
                    )
                )}

                <IconButton
                    aria-label="Следующая"
                    variant="ghost"
                    size="sm"
                    disabled={currentPage === lastPage}
                    onClick={() => onPageChange(currentPage + 1)}
                >
                    <LuChevronRight />
                </IconButton>
            </Flex>

            {/* Mobile: упрощённая пагинация (только ‹ ›) */}
            <Flex
                align="center"
                justify="center"
                gap="3"
                display={{ base: 'flex', md: 'none' }}
            >
                <IconButton
                    aria-label="Предыдущая"
                    variant="outline"
                    size="sm"
                    disabled={currentPage === 1}
                    onClick={() => onPageChange(currentPage - 1)}
                >
                    <LuChevronLeft />
                </IconButton>

                <Text fontSize="sm" fontWeight="600" color="gray.600">
                    {currentPage} / {lastPage}
                </Text>

                <IconButton
                    aria-label="Следующая"
                    variant="outline"
                    size="sm"
                    disabled={currentPage === lastPage}
                    onClick={() => onPageChange(currentPage + 1)}
                >
                    <LuChevronRight />
                </IconButton>
            </Flex>
        </>
    );
}

/**
 * InfiniteScrollControls — кнопка «Загрузить ещё» + sentinel для автоподгрузки.
 */
function InfiniteScrollControls({ currentPage, lastPage, onLoadMore, loadingMore }) {
    const sentinelRef = useRef(null);
    const hasMore = currentPage < lastPage;

    // IntersectionObserver для автоподгрузки
    useEffect(() => {
        if (!hasMore || loadingMore) return;

        const sentinel = sentinelRef.current;
        if (!sentinel) return;

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting && !loadingMore) {
                    onLoadMore();
                }
            },
            { rootMargin: '0px 0px 200px 0px' }
        );

        observer.observe(sentinel);
        return () => observer.disconnect();
    }, [hasMore, loadingMore, onLoadMore]);

    if (!hasMore) {
        return (
            <Text fontSize="sm" color="gray.400" textAlign="center">
                Все товары загружены
            </Text>
        );
    }

    return (
        <Flex direction="column" align="center" gap="3">
            <Button
                variant="outline"
                colorPalette="pecado"
                size="md"
                onClick={onLoadMore}
                disabled={loadingMore}
                loading={loadingMore}
                loadingText="Загрузка..."
            >
                Загрузить ещё
            </Button>

            {/* Sentinel для IntersectionObserver */}
            <Box ref={sentinelRef} h="1px" w="full" />
        </Flex>
    );
}

/**
 * Построить массив номеров страниц с многоточиями.
 * Макс 5 видимых номеров + первая/последняя.
 */
function buildPageNumbers(currentPage, lastPage) {
    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, i) => i + 1);
    }

    const pages = [];
    const delta = 2;
    const left = Math.max(2, currentPage - delta);
    const right = Math.min(lastPage - 1, currentPage + delta);

    pages.push(1);
    if (left > 2) pages.push('...');
    for (let i = left; i <= right; i++) pages.push(i);
    if (right < lastPage - 1) pages.push('...');
    if (lastPage > 1) pages.push(lastPage);

    return pages;
}
