import { useState } from 'react';
import { Box, HStack, Spinner, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import CommentEntry from '@/Crm/Components/CommentEntry';
import { useCommentFeed } from '@/Crm/Components/useCommentFeed';

/**
 * Комментарии одной сущности: форма + лента.
 *
 * Встраивается в карточку клиента, заказа и реализации — тип и ID сущности передаются
 * пропсами, всё остальное компонент делает сам.
 *
 * @param {string} entityType — 'client' | 'order' | 'shipment' (App\Support\Crm\CrmEntityMap)
 * @param {number} entityId
 * @param {boolean} canCreate — есть ли у сотрудника право crm-comments.create
 */
export default function CommentThread({ entityType, entityId, canCreate = true }) {
    const feed = useCommentFeed('/crm/comments', { entity_type: entityType, entity_id: entityId });
    const [body, setBody] = useState('');
    const [pinned, setPinned] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);

    const submit = async () => {
        const text = body.trim();
        if (!text) return;

        const ok = await feed.create({
            entity_type: entityType,
            entity_id: entityId,
            body: text,
            is_pinned: pinned,
        });

        if (ok) {
            setBody('');
            setPinned(false);
        }
    };

    return (
        <VStack align="stretch" gap={3}>
            {canCreate && (
                <VStack align="stretch" gap={2}>
                    <Textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        placeholder="Что важного узнали или о чём договорились?"
                        rows={3}
                    />
                    <HStack justify="space-between" gap={3} flexWrap="wrap">
                        <Checkbox
                            checked={pinned}
                            onCheckedChange={(e) => setPinned(!!e.checked)}
                            size="sm"
                        >
                            Закрепить вверху
                        </Checkbox>
                        <Button size="sm" onClick={submit} loading={feed.busy} disabled={!body.trim()}>
                            Добавить комментарий
                        </Button>
                    </HStack>
                </VStack>
            )}

            {feed.loading && feed.entries.length === 0 ? (
                <HStack justify="center" py={6}><Spinner size="sm" /></HStack>
            ) : feed.entries.length === 0 ? (
                <Box py={4}>
                    <Text fontSize="sm" color="fg.muted">
                        {feed.failed ? 'Комментарии недоступны.' : 'Комментариев пока нет.'}
                    </Text>
                </Box>
            ) : (
                <VStack align="stretch" gap={2}>
                    {feed.entries.map((entry) => (
                        <CommentEntry
                            key={entry.id}
                            entry={entry}
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
                </VStack>
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
