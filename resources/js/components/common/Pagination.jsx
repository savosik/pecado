import { HStack, Button, Text, IconButton } from '@chakra-ui/react';
import { LuChevronLeft, LuChevronRight } from 'react-icons/lu';

/**
 * Пагинация — обёртка над Laravel paginate().
 * Работает совместно с хуком usePagination().
 *
 * @param {{
 *   currentPage: number,
 *   lastPage: number,
 *   pageNumbers: (number|string)[],
 *   onPageChange: (page: number) => void,
 *   total?: number,
 *   perPage?: number,
 * }} props
 */
export default function Pagination({ currentPage, lastPage, pageNumbers, onPageChange, total, perPage }) {
    if (lastPage <= 1) return null;

    const isFirstPage = currentPage <= 1;
    const isLastPage = currentPage >= lastPage;

    return (
        <HStack justify="center" gap="1" mt="8" flexWrap="wrap">
            {/* Кнопка «Назад» */}
            <IconButton
                aria-label="Предыдущая страница"
                variant="ghost"
                size="sm"
                disabled={isFirstPage}
                onClick={() => onPageChange(currentPage - 1)}
            >
                <LuChevronLeft size={18} />
            </IconButton>

            {/* Номера страниц */}
            {pageNumbers.map((page, index) => {
                if (page === '...') {
                    return (
                        <Text
                            key={`ellipsis-${index}`}
                            px="2"
                            color="fg.muted"
                            fontSize="sm"
                            userSelect="none"
                        >
                            …
                        </Text>
                    );
                }

                const isActive = page === currentPage;

                return (
                    <Button
                        key={page}
                        size="sm"
                        variant={isActive ? 'solid' : 'ghost'}
                        colorPalette={isActive ? 'pecado' : undefined}
                        fontWeight={isActive ? 'bold' : 'normal'}
                        onClick={() => onPageChange(page)}
                        minW="9"
                    >
                        {page}
                    </Button>
                );
            })}

            {/* Кнопка «Вперёд» */}
            <IconButton
                aria-label="Следующая страница"
                variant="ghost"
                size="sm"
                disabled={isLastPage}
                onClick={() => onPageChange(currentPage + 1)}
            >
                <LuChevronRight size={18} />
            </IconButton>
        </HStack>
    );
}
