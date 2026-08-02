import { useState } from 'react';
import { Box, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import CommentEntry from '@/Crm/Components/CommentEntry';
import { useCommentFeed } from '@/Crm/Components/useCommentFeed';

/**
 * Сквозная лента клиента: всё, что оставлено по нему самому, его заказам и отгрузкам,
 * в одной хронологии — с указанием, где именно запись оставлена.
 *
 * @param {number} clientId
 */
export default function ClientTimeline({ clientId }) {
    const feed = useCommentFeed(`/crm/clients/${clientId}/timeline`);
    const [pendingDelete, setPendingDelete] = useState(null);

    if (feed.loading && feed.entries.length === 0) {
        return <HStack justify="center" py={6}><Spinner size="sm" /></HStack>;
    }

    if (feed.entries.length === 0) {
        return (
            <Box py={4}>
                <Text fontSize="sm" color="fg.muted">
                    {feed.failed
                        ? 'Лента недоступна.'
                        : 'В ленте пока пусто. Комментарии по клиенту, его заказам и реализациям появятся здесь.'}
                </Text>
            </Box>
        );
    }

    return (
        <VStack align="stretch" gap={2}>
            <Text fontSize="xs" color="fg.muted">Записей в ленте: {feed.total}</Text>

            {feed.entries.map((entry) => (
                <CommentEntry
                    key={`${entry.type}-${entry.id}`}
                    entry={entry}
                    showEntity
                    busy={feed.busy}
                    onUpdate={feed.update}
                    onDelete={setPendingDelete}
                />
            ))}

            {feed.hasMore && (
                <Button size="sm" variant="outline" onClick={feed.loadMore} loading={feed.loading}>
                    Показать ещё
                </Button>
            )}

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => feed.remove(pendingDelete)}
                title="Удалить комментарий?"
                description="Комментарий пропадёт из ленты клиента. Восстановить его сможет только администратор."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={feed.busy}
            />
        </VStack>
    );
}
