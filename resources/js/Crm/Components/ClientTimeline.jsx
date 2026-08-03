import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge, Box, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import CommentEntry from '@/Crm/Components/CommentEntry';
import TaskListItem from '@/Crm/Components/TaskListItem';
import { useCommentFeed } from '@/Crm/Components/useCommentFeed';

/**
 * Задача в ленте.
 *
 * Правится не здесь, а в разделе «Задачи»: лента — это хронология того, что было,
 * и превращать её во второй интерфейс редактирования незачем.
 */
function TimelineTaskEntry({ entry }) {
    return (
        <Box>
            <HStack gap={2} mb={1} flexWrap="wrap">
                <Badge colorPalette="purple" variant="subtle" size="sm">Задача</Badge>
                <Text fontSize="xs" color="fg.muted">
                    поставил {entry.author?.name}, {entry.happened_at_label}
                </Text>
            </HStack>
            <TaskListItem
                task={entry.task}
                showEntity
                onEdit={(task) => router.visit(route('crm.tasks.index', { task: task.id }))}
            />
        </Box>
    );
}

/**
 * Письмо в ленте.
 *
 * Тело в ленту не тянем — только тему и получателей: HTML целиком раздувал бы страницу
 * и требовал бы санитайзинга на каждой записи. Открывается письмо в журнале.
 */
function TimelineEmailEntry({ entry }) {
    const email = entry.email;

    return (
        <Box borderWidth="1px" borderRadius="md" p={3}>
            <VStack align="stretch" gap={2}>
                <HStack gap={2} flexWrap="wrap">
                    <Badge colorPalette="teal" variant="subtle" size="sm">Письмо</Badge>
                    <Badge colorPalette={email.status_color} variant="subtle" size="sm">
                        {email.status_label}
                    </Badge>
                    <Text fontSize="xs" color="fg.muted">
                        {entry.author?.name}, {entry.happened_at_label}
                    </Text>
                </HStack>

                <Text fontSize="sm" fontWeight="600">{email.subject}</Text>
                <Text fontSize="xs" color="fg.muted">{entry.excerpt}</Text>
                {email.error && <Text fontSize="xs" color="red.500">{email.error}</Text>}

                <HStack>
                    <Button
                        size="xs"
                        variant="ghost"
                        onClick={() => router.visit(route('crm.emails.index', { email: email.id }))}
                    >
                        Открыть письмо
                    </Button>
                </HStack>
            </VStack>
        </Box>
    );
}

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
                        : 'В ленте пока пусто. Комментарии, задачи и письма по клиенту, его заказам и реализациям появятся здесь.'}
                </Text>
            </Box>
        );
    }

    return (
        <VStack align="stretch" gap={2}>
            <Text fontSize="xs" color="fg.muted">Записей в ленте: {feed.total}</Text>

            {feed.entries.map((entry) => (entry.type === 'task' && entry.task ? (
                <TimelineTaskEntry key={`task-${entry.id}`} entry={entry} />
            ) : entry.type === 'email' && entry.email ? (
                <TimelineEmailEntry key={`email-${entry.id}`} entry={entry} />
            ) : (
                <CommentEntry
                    key={`${entry.type}-${entry.id}`}
                    entry={entry}
                    showEntity
                    busy={feed.busy}
                    onUpdate={feed.update}
                    onDelete={setPendingDelete}
                />
            )))}

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
