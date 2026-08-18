import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, ConfirmDialog, MarkdownTextEditor, MarkdownView } from '@/Admin/Components';
import { Badge, Box, Card, Flex, HStack, Input, Stack, Text, Textarea } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';
import { useFlashToast } from '@/hooks/useFlashToast';
import { usePermission } from '@/Admin/hooks/usePermission';

const STATUS_META = {
    open: { label: 'Открыт', color: 'gray' },
    in_progress: { label: 'Идёт диалог', color: 'blue' },
    resolved: { label: 'Итог согласован', color: 'green' },
    closed: { label: 'Закрыт', color: 'purple' },
};

const AUTHOR_META = {
    site: { label: 'Агент сайта', color: 'blue' },
    erp: { label: 'Агент 1С', color: 'orange' },
    moderator: { label: 'Модератор', color: 'purple' },
    system: { label: 'Система', color: 'gray' },
};

const KIND_META = {
    proposal: { label: 'Предложение итога', color: 'yellow' },
    resolution: { label: 'Итог подтверждён', color: 'green' },
};

const TURN_LABELS = {
    site: 'Агент сайта',
    erp: 'Агент 1С',
};

export default function Show({ topic, messages }) {
    useFlashToast();
    const { can } = usePermission();
    const canModerate = can('agent-topics.edit');
    const finished = topic.status === 'resolved' || topic.status === 'closed';

    const [closeDialogOpen, setCloseDialogOpen] = useState(false);
    const [editingTask, setEditingTask] = useState(false);
    const { data, setData, post, processing, reset } = useForm({ body: '' });
    const taskForm = useForm({ title: topic.title, task_body: topic.task_body });

    // Диалог живёт без вебсокетов: пока топик активен, страница раз в 5 секунд
    // подтягивает свежие сообщения. На время правки задачи опрос замирает.
    // Пока предыдущий reload не завершился, новый не запускается — иначе при
    // медленном сервере запросы стекаются и занимают все php-fpm воркеры.
    const reloadInFlight = useRef(false);

    useEffect(() => {
        if (finished || editingTask) return undefined;

        const id = setInterval(() => {
            if (reloadInFlight.current) return;
            reloadInFlight.current = true;
            router.reload({
                only: ['topic', 'messages'],
                onFinish: () => {
                    reloadInFlight.current = false;
                },
            });
        }, 5000);

        return () => clearInterval(id);
    }, [finished, editingTask]);

    const copyLink = (url, label) => {
        navigator.clipboard.writeText(url).then(() => {
            toaster.create({ title: `${label} скопирована`, type: 'success' });
        });
    };

    const sendMessage = (e) => {
        e.preventDefault();
        post(route('admin.agent-topics.messages.store', topic.id), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const startTaskEdit = () => {
        taskForm.setData({ title: topic.title, task_body: topic.task_body });
        taskForm.clearErrors();
        setEditingTask(true);
    };

    const saveTask = (e) => {
        e.preventDefault();
        taskForm.put(route('admin.agent-topics.update', topic.id), {
            preserveScroll: true,
            onSuccess: () => setEditingTask(false),
        });
    };

    const passTurn = () => {
        router.post(route('admin.agent-topics.pass-turn', topic.id), {}, { preserveScroll: true });
    };

    const closeTopic = () => {
        setCloseDialogOpen(false);
        router.post(route('admin.agent-topics.close', topic.id), {}, { preserveScroll: true });
    };

    const statusMeta = STATUS_META[topic.status] ?? { label: topic.status, color: 'gray' };

    return (
        <>
            <PageHeader title={topic.title} />

            <Stack gap={4}>
                <Card.Root>
                    <Card.Body>
                        <Flex justify="space-between" align="center" wrap="wrap" gap={3}>
                            <HStack gap={3}>
                                <Badge colorPalette={statusMeta.color} size="lg">{statusMeta.label}</Badge>
                                {!finished && (
                                    <Text fontSize="sm" color="fg.muted">
                                        Ход: <b>{TURN_LABELS[topic.turn] ?? topic.turn}</b>
                                        {topic.turn_started_at ? ` (с ${topic.turn_started_at})` : ''}
                                    </Text>
                                )}
                                <Text fontSize="sm" color="fg.muted">Создан: {topic.created_at}</Text>
                            </HStack>
                            {canModerate && !finished && (
                                <HStack gap={2}>
                                    <Button size="sm" variant="outline" onClick={passTurn}>
                                        Передать ход
                                    </Button>
                                    <Button size="sm" colorPalette="red" variant="outline" onClick={() => setCloseDialogOpen(true)}>
                                        Закрыть топик
                                    </Button>
                                </HStack>
                            )}
                        </Flex>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Flex justify="space-between" align="center" gap={3}>
                            <Text fontWeight="semibold">Задача</Text>
                            {canModerate && !editingTask && (
                                <Button size="xs" variant="outline" onClick={startTaskEdit}>
                                    Редактировать
                                </Button>
                            )}
                        </Flex>
                    </Card.Header>
                    <Card.Body>
                        {editingTask ? (
                            <form onSubmit={saveTask}>
                                <Stack gap={4}>
                                    <FormField label="Название" error={taskForm.errors.title} required>
                                        <Input
                                            value={taskForm.data.title}
                                            onChange={(e) => taskForm.setData('title', e.target.value)}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Постановка задачи"
                                        error={taskForm.errors.task_body}
                                        required
                                        helpText="Markdown. Агенты увидят изменение: в тред уйдёт системное сообщение."
                                    >
                                        <MarkdownTextEditor
                                            value={taskForm.data.task_body}
                                            onChange={(value) => taskForm.setData('task_body', value ?? '')}
                                            minHeight={320}
                                        />
                                    </FormField>
                                    <HStack gap={2}>
                                        <Button type="submit" size="sm" loading={taskForm.processing}>
                                            Сохранить
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setEditingTask(false)}>
                                            Отмена
                                        </Button>
                                    </HStack>
                                </Stack>
                            </form>
                        ) : (
                            <MarkdownView source={topic.task_body} />
                        )}
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold">Ссылки агентов</Text>
                    </Card.Header>
                    <Card.Body>
                        <Stack gap={3}>
                            {[
                                { label: 'Ссылка агента сайта', url: topic.site_url },
                                { label: 'Ссылка агента 1С', url: topic.erp_url },
                            ].map(({ label, url }) => (
                                <FormField key={label} label={label}>
                                    <HStack gap={2}>
                                        <Input value={url} readOnly fontFamily="mono" fontSize="xs" />
                                        <Button size="sm" variant="outline" onClick={() => copyLink(url, label)}>
                                            Копировать
                                        </Button>
                                    </HStack>
                                </FormField>
                            ))}
                            <Text fontSize="xs" color="fg.muted">
                                Каждая сторона получает только свою ссылку: по токену сервер определяет,
                                кто пишет, и следит за очерёдностью ходов.
                            </Text>
                        </Stack>
                    </Card.Body>
                </Card.Root>

                {topic.resolution && (
                    <Card.Root borderColor="green.400" borderWidth="1px">
                        <Card.Header>
                            <Text fontWeight="semibold">Итог</Text>
                        </Card.Header>
                        <Card.Body>
                            <MarkdownView source={topic.resolution} />
                        </Card.Body>
                    </Card.Root>
                )}

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold">Диалог ({messages.length})</Text>
                    </Card.Header>
                    <Card.Body>
                        <Stack gap={4}>
                            {messages.length === 0 && (
                                <Text fontSize="sm" color="fg.muted">
                                    Сообщений пока нет. Первый ход — за агентом сайта.
                                </Text>
                            )}

                            {messages.map((message) => {
                                const author = AUTHOR_META[message.author] ?? { label: message.author, color: 'gray' };
                                const kind = KIND_META[message.kind];

                                return (
                                    <Box
                                        key={message.id}
                                        borderWidth="1px"
                                        borderRadius="md"
                                        p={3}
                                        bg={message.author === 'system' ? 'bg.muted' : undefined}
                                    >
                                        <HStack gap={2} mb={2} wrap="wrap">
                                            <Badge colorPalette={author.color}>{author.label}</Badge>
                                            {kind && <Badge colorPalette={kind.color}>{kind.label}</Badge>}
                                            <Text fontSize="xs" color="fg.muted">#{message.seq}</Text>
                                            <Text fontSize="xs" color="fg.muted">{message.created_at}</Text>
                                        </HStack>
                                        {message.author === 'system' ? (
                                            <Text fontSize="sm" color="fg.muted">{message.body}</Text>
                                        ) : (
                                            <MarkdownView source={message.body} />
                                        )}
                                        {message.payload && (
                                            <Box
                                                as="pre"
                                                mt={2}
                                                p={2}
                                                borderRadius="sm"
                                                bg="bg.muted"
                                                fontSize="xs"
                                                overflowX="auto"
                                            >
                                                {JSON.stringify(message.payload, null, 2)}
                                            </Box>
                                        )}
                                    </Box>
                                );
                            })}

                            {canModerate && topic.status !== 'closed' && (
                                <form onSubmit={sendMessage}>
                                    <Stack gap={2}>
                                        <FormField label="Сообщение модератора" helpText="Видно обоим агентам, отправляется вне очереди и ход не передаёт.">
                                            <Textarea
                                                value={data.body}
                                                onChange={(e) => setData('body', e.target.value)}
                                                placeholder="Уточнение или указание агентам..."
                                                rows={3}
                                            />
                                        </FormField>
                                        <Box>
                                            <Button type="submit" size="sm" loading={processing} disabled={!data.body.trim()}>
                                                Отправить
                                            </Button>
                                        </Box>
                                    </Stack>
                                </form>
                            )}
                        </Stack>
                    </Card.Body>
                </Card.Root>
            </Stack>

            <ConfirmDialog
                open={closeDialogOpen}
                onClose={() => setCloseDialogOpen(false)}
                onConfirm={closeTopic}
                title="Закрыть топик?"
                description="Агенты больше не смогут писать в этот топик. Действие нельзя отменить."
            />
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
