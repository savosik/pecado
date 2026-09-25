import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, ConfirmDialog } from '@/Admin/Components';
import { Badge, Box, Card, HStack, Input, Stack, Table, Text, Textarea } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import RowActions from '@/shared/Panel/RowActions';
import { toaster } from '@/components/ui/toaster';
import { useFlashToast } from '@/hooks/useFlashToast';
import { usePermission } from '@/Admin/hooks/usePermission';

/**
 * Ссылки-хеши на пульт Agent Hub: кому выдана, где смотреть, когда пользовались.
 * Ссылка одновременно и страница для человека, и ключ API для внешнего агента,
 * поэтому в строке два адреса.
 */
export default function Links({ links }) {
    useFlashToast();
    const { can } = usePermission();
    const canCreate = can('agent-topics.create');
    const canRevoke = can('agent-topics.edit');

    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState(null);
    const form = useForm({ label: '', note: '' });

    const copy = (value, title) => {
        navigator.clipboard.writeText(value).then(() => {
            toaster.create({ title: `${title} скопирована`, type: 'success' });
        });
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(route('admin.agent-topics.links.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    const revoke = () => {
        const id = revoking;
        setRevoking(null);
        router.delete(route('admin.agent-topics.links.destroy', id), { preserveScroll: true });
    };

    return (
        <>
            <PageHeader
                title="Ссылки на пульт агентов"
                description="По ссылке пульт открывается без входа на сайт: список топиков, создание и управление. Этой же ссылкой внешний агент авторизует API создания топиков."
                createPermission="agent-topics.create"
                onCreate={canCreate ? () => setCreating((value) => !value) : undefined}
                createLabel={creating ? 'Отмена' : 'Выдать ссылку'}
                actions={(
                    <Button variant="outline" onClick={() => router.visit(route('admin.agent-topics.index'))}>
                        К диалогам
                    </Button>
                )}
            />

            <Stack gap={4}>
                {creating && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold">Новая ссылка</Text>
                        </Card.Header>
                        <Card.Body>
                            <form onSubmit={submit}>
                                <Stack gap={4}>
                                    <FormField
                                        label="Кому выдана"
                                        error={form.errors.label}
                                        required
                                        helpText="Видно на пульте и в ленте, когда с этой ссылки пишут модератором: «Админ 1С», «Наш админ»."
                                    >
                                        <Input
                                            value={form.data.label}
                                            onChange={(e) => form.setData('label', e.target.value)}
                                            placeholder="Админ 1С"
                                        />
                                    </FormField>
                                    <FormField label="Заметка" error={form.errors.note}>
                                        <Textarea
                                            value={form.data.note}
                                            onChange={(e) => form.setData('note', e.target.value)}
                                            placeholder="Зачем выдана, кому передана"
                                            rows={2}
                                        />
                                    </FormField>
                                    <HStack gap={2}>
                                        <Button type="submit" size="sm" loading={form.processing}>
                                            Выдать
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
                        {links.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">
                                Ссылок нет. Выдайте первую — и отдайте адрес тому, кто должен видеть диалоги агентов.
                            </Text>
                        ) : (
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Кому выдана</Table.ColumnHeader>
                                        <Table.ColumnHeader>Адреса</Table.ColumnHeader>
                                        <Table.ColumnHeader>Топиков</Table.ColumnHeader>
                                        <Table.ColumnHeader>Выдана</Table.ColumnHeader>
                                        <Table.ColumnHeader>Последний вход</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Действия</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {links.map((link) => (
                                        <Table.Row key={link.id} opacity={link.revoked_at ? 0.5 : 1}>
                                            <Table.Cell>
                                                <Stack gap={1}>
                                                    <Text fontWeight="semibold">{link.label}</Text>
                                                    {link.note && (
                                                        <Text fontSize="xs" color="fg.muted">{link.note}</Text>
                                                    )}
                                                    {link.revoked_at && (
                                                        <Badge colorPalette="red" width="fit-content">
                                                            Отозвана {link.revoked_at}
                                                        </Badge>
                                                    )}
                                                    {link.created_by && (
                                                        <Text fontSize="xs" color="fg.muted">Выдал: {link.created_by}</Text>
                                                    )}
                                                </Stack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Stack gap={2} minW="320px">
                                                    <HStack gap={2}>
                                                        <Input value={link.url} readOnly fontFamily="mono" fontSize="xs" size="xs" />
                                                        <Button size="xs" variant="outline" onClick={() => copy(link.url, 'Ссылка на пульт')}>
                                                            Пульт
                                                        </Button>
                                                    </HStack>
                                                    <HStack gap={2}>
                                                        <Input value={link.api_url} readOnly fontFamily="mono" fontSize="xs" size="xs" />
                                                        <Button size="xs" variant="outline" onClick={() => copy(link.api_url, 'Ссылка API')}>
                                                            API
                                                        </Button>
                                                    </HStack>
                                                </Stack>
                                            </Table.Cell>
                                            <Table.Cell>{link.topics_count}</Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm">{link.created_at}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm">{link.last_used_at ?? '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <RowActions
                                                    delete={canRevoke && !link.revoked_at ? {
                                                        onClick: () => setRevoking(link.id),
                                                        label: 'Отозвать',
                                                    } : null}
                                                />
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Card.Body>
                </Card.Root>

                <Box>
                    <Text fontSize="xs" color="fg.muted">
                        Отзыв мгновенно гасит и страницу, и API этой ссылки; созданные по ней топики
                        и ссылки агентов продолжают работать.
                    </Text>
                </Box>
            </Stack>

            <ConfirmDialog
                open={revoking !== null}
                onClose={() => setRevoking(null)}
                onConfirm={revoke}
                title="Отозвать ссылку?"
                description="Пульт и API по этой ссылке перестанут открываться. Тому, кто ей пользовался, нужно будет выдать новую."
                confirmLabel="Отозвать"
            />
        </>
    );
}

Links.layout = (page) => <AdminLayout>{page}</AdminLayout>;
