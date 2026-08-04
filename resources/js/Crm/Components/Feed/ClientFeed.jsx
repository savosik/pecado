import { useMemo, useState } from 'react';
import { Badge, Box, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import { LuFilter } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { MenuContent, MenuRoot, MenuTrigger } from '@/components/ui/menu';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import TaskDialog from '@/Crm/Components/TaskDialog';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import { useCommentFeed } from '@/Crm/Components/useCommentFeed';
import CallDialog from '@/Crm/Components/CallDialog';
import FeedComposer from './FeedComposer';
import { ENTRY_STYLE } from './FeedEntryShell';
import CommentFeedEntry from './entries/CommentFeedEntry';
import DocumentFeedEntry from './entries/DocumentFeedEntry';
import TaskFeedEntry from './entries/TaskFeedEntry';
import EmailFeedEntry from './entries/EmailFeedEntry';
import CallFeedEntry from './entries/CallFeedEntry';
import { entryFromCall, entryFromEmail, entryFromTask } from './timelineEntry';

/**
 * Что можно показать в ленте. Пусто = всё.
 *
 * Порядок — от того, что менеджер пишет сам, к тому, что происходит без него.
 */
const TYPES = ['comment', 'task', 'call', 'email', 'order', 'shipment'];

/**
 * Заголовок дня в хронологии.
 *
 * Метка приходит с сервера в формате `d.m.Y H:i`, поэтому день сравнивается
 * строками: разбирать дату на фронте означало бы завести второй часовой пояс.
 */
function dayLabel(dayKey) {
    const pad = (n) => String(n).padStart(2, '0');
    const asKey = (date) => `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()}`;

    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (dayKey === asKey(today)) return 'Сегодня';
    if (dayKey === asKey(yesterday)) return 'Вчера';

    return dayKey;
}

/**
 * Стена клиента: всё, что с ним происходило, и поле ввода снизу.
 *
 * Сервер отдаёт записи от новых к старым — так работает пагинация по ключам,
 * и переворачивать её ради вида чата означало бы либо грузить всю историю,
 * либо считать страницы с конца. Поэтому лента идёт сверху вниз от свежего
 * к старому, а composer прибит снизу: новая запись появляется прямо над ним.
 *
 * Записи сгруппированы по дням: без разделителей двадцать карточек подряд
 * читаются как одна простыня, и понять «что было вчера» можно только сверяя
 * время в каждой шапке.
 *
 * Закреплённые вынесены отдельным блоком: в хронологии они ломали бы порядок,
 * а в чате «важное» должно быть на виду постоянно.
 *
 * @param {number} clientId
 * @param {object|null} client — карточка клиента, нужна диалогу звонка (номер)
 * @param {string|null} clientEmail — подставляется в письмо
 */
export default function ClientFeed({ clientId, client = null, clientEmail = null }) {
    const [types, setTypes] = useState([]);
    const [taskOpen, setTaskOpen] = useState(false);
    const [emailOpen, setEmailOpen] = useState(false);
    const [callOpen, setCallOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);

    const feed = useCommentFeed(`/crm/clients/${clientId}/timeline`, { types });

    const pinned = useMemo(
        () => feed.entries.filter((entry) => entry.is_pinned),
        [feed.entries],
    );

    // Группировка по дню сохраняет порядок сервера: записи уже отсортированы,
    // и достаточно резать их на серии по дате.
    const days = useMemo(() => {
        const groups = [];

        feed.entries
            .filter((entry) => !entry.is_pinned)
            .forEach((entry) => {
                const key = (entry.happened_at_label || '').slice(0, 10) || 'Без даты';
                const last = groups[groups.length - 1];

                if (last && last.key === key) {
                    last.entries.push(entry);
                } else {
                    groups.push({ key, entries: [entry] });
                }
            });

        return groups;
    }, [feed.entries]);

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

        if (entry.type === 'call') {
            return <CallFeedEntry key={key} entry={entry} />;
        }

        return (
            <CommentFeedEntry
                key={key}
                entry={entry}
                busy={feed.busy}
                onUpdate={feed.update}
                onDelete={setPendingDelete}
            />
        );
    };

    return (
        <VStack align="stretch" gap={0}>
            <HStack justify="space-between" px={1} pb={3}>
                <Text fontSize="xs" color="fg.muted">
                    {feed.loading && feed.entries.length === 0 ? 'Загружаем ленту…' : `Записей: ${feed.total}`}
                </Text>

                <MenuRoot closeOnSelect={false}>
                    <MenuTrigger asChild>
                        <Button size="xs" variant={types.length ? 'subtle' : 'ghost'}>
                            <LuFilter /> {types.length ? `Показано: ${types.length}` : 'Всё'}
                        </Button>
                    </MenuTrigger>
                    <MenuContent p={2} minW="200px">
                        <VStack align="stretch" gap={1.5}>
                            {TYPES.map((type) => {
                                const style = ENTRY_STYLE[type];

                                return (
                                    <Checkbox
                                        key={type}
                                        size="sm"
                                        checked={types.includes(type)}
                                        onCheckedChange={(e) => toggleType(type, !!e.checked)}
                                    >
                                        <HStack gap={2}>
                                            {/* Цвет в фильтре тот же, что у полосы записи —
                                                иначе фильтр пришлось бы читать, а не узнавать. */}
                                            <Box w="3px" h="14px" borderRadius="full" bg={`${style.palette}.solid`} />
                                            <Text fontSize="sm">{style.label}</Text>
                                        </HStack>
                                    </Checkbox>
                                );
                            })}
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
                <VStack align="stretch" gap={3} pb={5} mb={4} borderBottomWidth="1px">
                    <Text fontSize="xs" fontWeight="600" color="fg.muted">Закреплено</Text>
                    {pinned.map(renderEntry)}
                </VStack>
            )}

            <Box maxH="62vh" overflowY="auto" pr={2} py={1}>
                <VStack align="stretch" gap={8}>
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

                    {days.map((day) => (
                        <VStack key={day.key} align="stretch" gap={4}>
                            <HStack gap={3}>
                                <Badge colorPalette="gray" variant="subtle" size="sm">
                                    {dayLabel(day.key)}
                                </Badge>
                                <Box flex="1" h="1px" bg="border.muted" />
                            </HStack>

                            {day.entries.map(renderEntry)}
                        </VStack>
                    ))}

                    {feed.hasMore && (
                        <Button size="sm" variant="outline" onClick={feed.loadMore} loading={feed.loading}>
                            Показать более ранние
                        </Button>
                    )}
                </VStack>
            </Box>

            <Box pt={3}>
                <FeedComposer
                    clientId={clientId}
                    busy={feed.busy}
                    onCreateComment={feed.create}
                    onCreated={feed.prepend}
                    onCompose={() => setEmailOpen(true)}
                    onFullTask={() => setTaskOpen(true)}
                    onCall={() => setCallOpen(true)}
                />
            </Box>

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

            <CallDialog
                open={callOpen}
                client={client}
                onClose={() => setCallOpen(false)}
                onSaved={(data) => {
                    feed.prepend(entryFromCall(data.call));
                    // Следующий шаг — отдельная запись ленты: он попадает в неё
                    // тем же событием, что и в раздел задач.
                    if (data.follow_up) {
                        feed.prepend(entryFromTask(data.follow_up));
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
