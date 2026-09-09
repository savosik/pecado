import { useEffect, useRef } from 'react';
import { Box, HStack, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

/**
 * Подвал списка с бесконечной прокруткой — как в каталоге товаров.
 *
 * Два режима: страницы (обычная пагинация DataTable) и прокрутка — тогда
 * пагинация прячется, а здесь живут «Показано N из M», кнопка «Загрузить ещё»
 * и часовой для IntersectionObserver. Переключатель виден в обоих режимах,
 * иначе из прокрутки некуда было бы вернуться.
 *
 * @param {boolean} infinite — режим бесконечной прокрутки
 * @param {Function} onToggle — (enabled: boolean) => void
 * @param {number} shown — сколько строк уже на экране
 * @param {number} total — сколько всего по отбору
 * @param {boolean} hasMore
 * @param {boolean} loadingMore
 * @param {Function} onLoadMore
 * @param {string} allLoadedText — подпись, когда догружать больше нечего
 */
export default function ListScrollFooter({
    infinite,
    onToggle,
    shown,
    total,
    hasMore,
    loadingMore,
    onLoadMore,
    allLoadedText = 'Все записи загружены',
}) {
    const sentinelRef = useRef(null);

    // Часовой: страница догружается, когда до конца списка остаётся 400px,
    // а не когда прокрутили в самый низ — иначе видна пауза на каждой порции.
    useEffect(() => {
        const sentinel = sentinelRef.current;

        if (!infinite || !hasMore || loadingMore || !sentinel) {
            return undefined;
        }

        const observer = new IntersectionObserver(
            (entries) => entries[0].isIntersecting && onLoadMore(),
            { rootMargin: '0px 0px 400px 0px' },
        );

        observer.observe(sentinel);

        return () => observer.disconnect();
    }, [infinite, hasMore, loadingMore, onLoadMore]);

    return (
        <Box borderTopWidth="1px" borderColor="border.muted" p={3} bg="bg.subtle">
            <HStack justifyContent="space-between" flexWrap="wrap" gap={3}>
                {infinite ? (
                    <HStack gap={3} flexWrap="wrap">
                        <Text fontSize="sm" color="fg.muted">
                            Показано {shown} из {total}
                        </Text>
                        {hasMore ? (
                            <Button
                                size="xs"
                                variant="outline"
                                onClick={onLoadMore}
                                loading={loadingMore}
                                loadingText="Загружаю…"
                            >
                                Загрузить ещё
                            </Button>
                        ) : (
                            <Text fontSize="sm" color="fg.muted">{allLoadedText}</Text>
                        )}
                    </HStack>
                ) : <Box />}

                <Switch
                    size="sm"
                    checked={infinite}
                    onCheckedChange={(e) => onToggle(e.checked)}
                >
                    <Text fontSize="sm" color="fg.muted">Бесконечная прокрутка</Text>
                </Switch>
            </HStack>

            {infinite && hasMore && <Box ref={sentinelRef} h="1px" w="full" />}
        </Box>
    );
}
