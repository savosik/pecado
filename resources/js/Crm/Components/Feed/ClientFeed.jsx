import { useMemo, useState } from 'react';
import { Box, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import { LuFilter } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { MenuContent, MenuRoot, MenuTrigger } from '@/components/ui/menu';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import CommentEntry from '@/Crm/Components/CommentEntry';
import TaskDialog from '@/Crm/Components/TaskDialog';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import { useCommentFeed } from '@/Crm/Components/useCommentFeed';
import FeedComposer from './FeedComposer';
import DocumentFeedEntry from './entries/DocumentFeedEntry';
import TaskFeedEntry from './entries/TaskFeedEntry';
import EmailFeedEntry from './entries/EmailFeedEntry';
import { entryFromEmail, entryFromTask } from './timelineEntry';

/**
 * Что можно показать в ленте. Пусто = всё.
 */
const TYPES = [
    { value: 'comment', label: 'Комментарии' },
    { value: 'task', label: 'Задачи' },
    { value: 'email', label: 'Письма' },
    { value: 'order', label: 'Заказы' },
    { value: 'shipment', label: 'Реализации' },
];

/**
 * Стена клиента: всё, что с ним происходило, и поле ввода снизу.
 *
 * Сервер отдаёт записи от новых к старым — так работает пагинация по ключам,
 * и переворачивать её ради вида чата означало бы либо грузить всю историю,
 * либо считать страницы с конца. Поэтому лента идёт сверху вниз от свежего
 * к старому, а composer прибит снизу: новая запись появляется прямо над ним.
 *
 * Закреплённые вынесены отдельным блоком: в хронологии они ломали бы порядок,
 * а в чате «важное» должно быть на виду постоянно.
 *
 * @param {number} clientId
 * @param {string|null} clientEmail — подставляется в письмо
 */
export default function ClientFeed({ clientId, clientEmail = null }) {
    const [types, setTypes] = useState([]);
    const [taskOpen, setTaskOpen] = useState(false);
    const [emailOpen, setEmailOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);

    const feed = useCommentFeed(`/crm/clients/${clientId}/timeline`, { types });

    const { pinned, chronology } = useMemo(() => ({
        pinned: feed.entries.filter((entry) => entry.is_pinned),
        chronology: feed.entries.filter((entry) => !entry.is_pinned),
    }), [feed.entries]);

    const toggleType = (value, checked) => {
        setTypes((prev) => (checked ? [...prev, value] : prev.filter((item) => item !== value)));
    };

    const renderEntry = (entry) => {
        const key = `${entry.type}-${entry.id}`;

        if (entry.type === 'order' || entry.type === 'shipment') {
            return <DocumentFeedEntry key={key} entry={entry} />;
        }

        if (entry.type === 'task') {
            return <TaskFeedEntry key={key} entry={entry} onChanged={feed.reload} />;
        }

        if (entry.type === 'email') {
            return <EmailFeedEntry key={key} entry={entry} />;
        }

        return (
            <CommentEntry
                key={key}
                entry={entry}
                showEntity
                busy={feed.busy}
                onUpdate={feed.update}
                onDelete={setPendingDelete}
            />
        );
    };

    return (
        <VStack align="stretch" gap={0}>
            <HStack justify="space-between" px={1} pb={2}>
                <Text fontSize="xs" color="fg.muted">
                    {feed.loading && feed.entries.length === 0 ? 'Загружаем ленту…' : `Записей: ${feed.total}`}
                </Text>

                <MenuRoot closeOnSelect={false}>
                    <MenuTrigger asChild>
                        <Button size="xs" variant={types.length ? 'subtle' : 'ghost'}>
                            <LuFilter /> {types.length ? `Показано: ${types.length}` : 'Всё'}
                        </Button>
                    </MenuTrigger>
                    <MenuContent p={2}>
                        <VStack align="stretch" gap={1}>
                            {TYPES.map((type) => (
                                <Checkbox
                                    key={type.value}
                                    size="sm"
                                    checked={types.includes(type.value)}
                                    onCheckedChange={(e) => toggleType(type.value, !!e.checked)}
                                >
                                    {type.label}
                                </Checkbox>
                            ))}
                            {types.length > 0 && (
                                <Button size="xs" variant="ghost" onClick={() => setTypes([])}>
                                    Показать всё
                                </Button>
                            )}
                        </VStack>
                    </MenuContent>
                </MenuRoot>
            </HStack>

            {pinned.length > 0 && (
                <VStack align="stretch" gap={2} pb={3} mb={2} borderBottomWidth="1px">
                    <Text fontSize="xs" color="fg.muted">Закреплено</Text>
                    {pinned.map(renderEntry)}
                </VStack>
            )}

            <Box maxH="60vh" overflowY="auto" pr={1}>
                <VStack align="stretch" gap={2}>
                    {feed.loading && feed.entries.length === 0 && (
                        <HStack justify="center" py={6}><Spinner size="sm" /></HStack>
                    )}

                    {!feed.loading && feed.entries.length === 0 && (
                        <Box py={6}>
                            <Text fontSize="sm" color="fg.muted">
                                {feed.failed
                                    ? 'Лента недоступна.'
                                    : 'В ленте пока пусто. Напишите первую заметку или поставьте задачу — здесь же появятся заказы и реализации клиента.'}
                            </Text>
                        </Box>
                    )}

                    {chronology.map(renderEntry)}

                    {feed.hasMore && (
                        <Button size="sm" variant="outline" onClick={feed.loadMore} loading={feed.loading}>
                            Показать более ранние
                        </Button>
                    )}
                </VStack>
            </Box>

            <FeedComposer
                clientId={clientId}
                busy={feed.busy}
                onCreateComment={feed.create}
                onCreated={feed.prepend}
                onCompose={() => setEmailOpen(true)}
                onFullTask={() => setTaskOpen(true)}
            />

            <TaskDialog
                open={taskOpen}
                entity={{ type: 'client', id: clientId }}
                onClose={() => setTaskOpen(false)}
                onSaved={(task) => {
                    setTaskOpen(false);
                    feed.prepend(entryFromTask(task));
                }}
            />

            <EmailComposeDialog
                open={emailOpen}
                entity={{ type: 'client', id: clientId }}
                defaultTo={clientEmail}
                onClose={() => setEmailOpen(false)}
                onSaved={(email) => {
                    setEmailOpen(false);
                    // Черновик в ленту не идёт — он становится событием только после отправки.
                    if (email?.status && email.status !== 'draft') {
                        feed.prepend(entryFromEmail(email));
                    }
                }}
            />

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
