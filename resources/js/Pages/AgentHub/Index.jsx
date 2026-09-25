import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Badge, Box, Card, Flex, HStack, Input, Stack, Table, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { FormField, MarkdownTextEditor } from '@/Admin/Components';
import RowActions from '@/shared/Panel/RowActions';
import { toaster } from '@/components/ui/toaster';
import { useFlashToast } from '@/hooks/useFlashToast';
import HubLayout from './HubLayout';

const STATUS_META = {
    open: { label: 'Открыт', color: 'gray' },
    in_progress: { label: 'Идёт диалог', color: 'blue' },
    resolved: { label: 'Итог согласован', color: 'green' },
    closed: { label: 'Закрыт', color: 'purple' },
};

const STATUS_FILTERS = [
    { value: '', label: 'Все' },
    { value: 'open', label: 'Открытые' },
    { value: 'in_progress', label: 'Идёт диалог' },
    { value: 'resolved', label: 'Итог согласован' },
    { value: 'closed', label: 'Закрытые' },
];

const TURN_LABELS = {
    site: 'Агент сайта',
    erp: 'Агент 1С',
};

export default function Index({ hub, topics, filters }) {
    useFlashToast();

    const [search, setSearch] = useState(filters.search ?? '');
    const [creating, setCreating] = useState(false);
    const form = useForm({ title: '', task_body: '' });

    const applyFilters = (next) => {
        router.get(route('agent-hub.index', hub.token), {
            search: next.search ?? search,
            status: next.status ?? filters.status,
        }, { preserveState: true, replace: true });
    };

    const submitSearch = (e) => {
        e.preventDefault();
        applyFilters({});
    };

    const createTopic = (e) => {
        e.preventDefault();
        form.post(route('agent-hub.topics.store', hub.token), {
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    const copyApiUrl = () => {
        navigator.clipboard.writeText(hub.api_url).then(() => {
            toaster.create({ title: 'Адрес API скопирован', type: 'success' });
        });
    };

    return (
        <>
            <Head title="Диалоги ИИ-агентов" />

            <Stack gap={4}>
                <Flex justify="space-between" align="center" gap={3} wrap="wrap">
                    <Text fontSize="sm" color="fg.muted">
                        Топиков: {topics.total ?? topics.data.length}
                    </Text>
                    <HStack gap={2}>
                        <Button size="sm" variant="outline" onClick={copyApiUrl}>
                            Скопировать адрес API
                        </Button>
                        <Button size="sm" onClick={() => setCreating((value) => !value)}>
                            {creating ? 'Отмена' : 'Создать топик'}
                        </Button>
                    </HStack>
                </Flex>

                {creating && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold">Новый топик</Text>
                        </Card.Header>
                        <Card.Body>
                            <form onSubmit={createTopic}>
                                <Stack gap={4}>
                                    <FormField label="Название" error={form.errors.title} required>
                                        <Input
                                            value={form.data.title}
                                            onChange={(e) => form.setData('title', e.target.value)}
                                            placeholder="Например: сверка остатков по складу"
                                        />
                                    </FormField>
                                    <FormField
                                        label="Постановка задачи"
                                        error={form.errors.task_body}
                                        required
                                        helpText="Markdown. Пишите самодостаточно: агенты видят только этот текст и ленту топика."
                                    >
                                        <MarkdownTextEditor
                                            value={form.data.task_body}
                                            onChange={(value) => form.setData('task_body', value ?? '')}
                                            minHeight={280}
                                        />
                                    </FormField>
                                    <HStack gap={2}>
                                        <Button type="submit" size="sm" loading={form.processing}>
                                            Создать
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setCreating(false)}>
                                            Отмена
                                        </Button>
                                    </HStack>
                                </Stack>
                            </form>
                        </Card.Body>
                    </Card.Root>
                )}

                <Card.Root>
                    <Card.Body>
                        <Stack gap={3}>
                            <form onSubmit={submitSearch}>
                                <HStack gap={2}>
                                    <Input
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Поиск по названию..."
                                        size="sm"
                                    />
                                    <Button type="submit" size="sm" variant="outline">Найти</Button>
                                </HStack>
                            </form>

                            <HStack gap={2} wrap="wrap">
                                {STATUS_FILTERS.map((item) => (
                                    <Button
                                        key={item.value || 'all'}
                                        size="xs"
                                        variant={(filters.status ?? '') === item.value ? 'solid' : 'outline'}
                                        onClick={() => applyFilters({ status: item.value })}
                                    >
                                        {item.label}
                                    </Button>
                                ))}
                            </HStack>
                        </Stack>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        {topics.data.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">
                                Топиков нет. Создайте первый — агенты получат ссылки из его карточки.
                            </Text>
                        ) : (
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Название</Table.ColumnHeader>
                                        <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                        <Table.ColumnHeader>Чей ход</Table.ColumnHeader>
                                        <Table.ColumnHeader>Сообщений</Table.ColumnHeader>
                                        <Table.ColumnHeader>Обновлён</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Действия</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {topics.data.map((topic) => {
                                        const meta = STATUS_META[topic.status] ?? { label: topic.status, color: 'gray' };

                                        return (
                                            <Table.Row key={topic.id}>
                                                <Table.Cell fontFamily="mono">{topic.id}</Table.Cell>
                                                <Table.Cell fontWeight="semibold">{topic.title}</Table.Cell>
                                                <Table.Cell>
                                                    <Badge colorPalette={meta.color}>{meta.label}</Badge>
                                                </Table.Cell>
                                                <Table.Cell>{TURN_LABELS[topic.turn] ?? topic.turn}</Table.Cell>
                                                <Table.Cell>{topic.messages_count}</Table.Cell>
                                                <Table.Cell>{topic.updated_at}</Table.Cell>
                                                <Table.Cell>
                                                    <RowActions
                                                        view={{
                                                            href: route('agent-hub.topics.show', {
                                                                token: hub.token,
                                                                agentTopic: topic.id,
                                                            }),
                                                        }}
                                                    />
                                                </Table.Cell>
                                            </Table.Row>
                                        );
                                    })}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Card.Body>
                </Card.Root>

                {topics.last_page > 1 && (
                    <HStack gap={2} justify="center">
                        {topics.links.map((link, index) => (
                            <Button
                                key={index}
                                size="xs"
                                variant={link.active ? 'solid' : 'outline'}
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url, { preserveState: true })}
                            >
                                <Box dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Button>
                        ))}
                    </HStack>
                )}
            </Stack>
        </>
    );
}

Index.layout = (page) => <HubLayout hub={page.props.hub}>{page}</HubLayout>;
