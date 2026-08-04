import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Badge, Box, Code, HStack, Input, List, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Alert } from '@/components/ui/alert';
import { LuCheck, LuCopy, LuKeyRound, LuTrash2 } from 'react-icons/lu';

/**
 * Токены ИИ-агентов: выдача и отзыв руководителем отдела.
 *
 * Токен виден в списке всегда, а не единожды при создании. Он хранится
 * открытым текстом (это bearer для машинного доступа), и прятать его в
 * интерфейсе, оставляя доступным в базе, было бы имитацией безопасности —
 * зато стоило бы менеджеру перевыпуска при каждой потере.
 */
export default function Index() {
    const { tokens = [], users = [], endpoints = {}, canCreate = false, canRevoke = false, errors = {} } = usePage().props;

    const [name, setName] = useState('');
    const [userId, setUserId] = useState('');
    const [busy, setBusy] = useState(false);
    const [revokeFor, setRevokeFor] = useState(null);
    const [copied, setCopied] = useState(null);

    const submit = (event) => {
        event.preventDefault();
        setBusy(true);

        router.post(route('crm.agent-tokens.store'), { name, user_id: Number(userId) || null }, {
            preserveScroll: true,
            onSuccess: () => {
                setName('');
                setUserId('');
            },
            onFinish: () => setBusy(false),
        });
    };

    const revoke = () => {
        setBusy(true);
        router.delete(route('crm.agent-tokens.destroy', revokeFor.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setRevokeFor(null);
            },
        });
    };

    const copy = (token) => {
        navigator.clipboard?.writeText(token.token);
        setCopied(token.id);
        setTimeout(() => setCopied(null), 2000);
    };

    const columns = [
        {
            key: 'name',
            label: 'Кому выдан',
            render: (_, row) => (
                <VStack align="start" gap={0} opacity={row.is_active ? 1 : 0.55}>
                    <Text fontWeight="semibold">{row.name}</Text>
                    <Text fontSize="xs" color="fg.muted">{row.user || 'сотрудник удалён'}</Text>
                </VStack>
            ),
        },
        {
            key: 'token',
            label: 'Токен',
            render: (_, row) => (
                <HStack gap={2}>
                    <Code fontSize="xs" maxW="220px" overflow="hidden" textOverflow="ellipsis" whiteSpace="nowrap">
                        {row.token}
                    </Code>
                    <Button
                        size="xs"
                        variant="ghost"
                        onClick={() => copy(row)}
                        aria-label="Скопировать токен"
                        title="Скопировать токен"
                    >
                        {copied === row.id ? <LuCheck /> : <LuCopy />}
                    </Button>
                </HStack>
            ),
        },
        {
            key: 'is_active',
            label: 'Состояние',
            render: (_, row) => (
                <Badge colorPalette={row.is_active ? 'green' : 'gray'} variant="subtle">
                    {row.is_active ? 'Активен' : 'Отозван'}
                </Badge>
            ),
        },
        {
            key: 'last_used_at',
            label: 'Последнее обращение',
            render: (_, row) => <Text fontSize="sm">{row.last_used_at || 'ни разу'}</Text>,
        },
        {
            key: 'created_at',
            label: 'Выдан',
            render: (_, row) => <Text fontSize="sm">{row.created_at || '—'}</Text>,
        },
        ...(canRevoke ? [{
            key: 'actions',
            label: '',
            render: (_, row) => (row.is_active ? (
                <Button
                    size="xs"
                    variant="ghost"
                    colorPalette="red"
                    disabled={busy}
                    onClick={() => setRevokeFor(row)}
                    title="Отозвать токен"
                >
                    <LuTrash2 /> Отозвать
                </Button>
            ) : null),
        }] : []),
    ];

    return (
        <>
            <Head title="CRM — Токены ИИ-агентов" />
            <PageHeader
                title="Токены ИИ-агентов"
                description="Пишущий доступ в CRM для ИИ-агентов менеджеров: выдача и отзыв"
            />

            <VStack align="stretch" gap={5}>
                <Alert status="warning" title="Токен работает от имени сотрудника">
                    Агент с этим токеном сможет оставлять комментарии, ставить задачи, записывать звонки
                    и готовить письма от имени выбранного сотрудника — в пределах его прав и его клиентов.
                    Отзыв закрывает доступ немедленно.
                </Alert>

                {canCreate && (
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                        <form onSubmit={submit}>
                            <HStack gap={3} align="end" flexWrap="wrap">
                                <Field label="Название" invalid={!!errors.name} errorText={errors.name} maxW="280px">
                                    <Input
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        placeholder="Агент Ксении"
                                    />
                                </Field>

                                <Field label="Сотрудник" invalid={!!errors.user_id} errorText={errors.user_id} maxW="280px">
                                    <NativeSelectRoot>
                                        <NativeSelectField
                                            value={userId}
                                            onChange={(e) => setUserId(e.target.value)}
                                        >
                                            <option value="">Выберите сотрудника</option>
                                            {users.map((user) => (
                                                <option key={user.id} value={user.id}>{user.name}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Button type="submit" disabled={busy || !name || !userId}>
                                    <LuKeyRound /> Выпустить токен
                                </Button>
                            </HStack>
                        </form>
                    </Box>
                )}

                <DataTable data={tokens} columns={columns} />

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <Text fontWeight="semibold" mb={2}>Как подключить агента</Text>
                    <List.Root gap={1} fontSize="sm" color="fg.muted" ps={5}>
                        <List.Item>
                            В настройках ИИ-агента добавьте MCP-сервер по адресу <Code>{endpoints.mcp}</Code>.
                        </List.Item>
                        <List.Item>
                            Тип подключения — HTTP, авторизация — <Code>Bearer</Code>, значение — токен из таблицы выше.
                        </List.Item>
                        <List.Item>
                            Агент начинает работу с инструмента <Code>crm-catalog</Code>: он сам расскажет,
                            что доступно этому сотруднику.
                        </List.Item>
                        <List.Item>
                            Тем же токеном работает REST-вариант: <Code>{endpoints.rest}</Code>.
                            Описание всех операций — <a href={endpoints.docs} target="_blank" rel="noreferrer">
                                <Text as="span" textDecoration="underline">документация CRM API</Text>
                            </a>.
                        </List.Item>
                    </List.Root>
                </Box>
            </VStack>

            <ConfirmDialog
                open={revokeFor !== null}
                onClose={() => setRevokeFor(null)}
                onConfirm={revoke}
                title={`Отозвать токен «${revokeFor?.name}»?`}
                description="Доступ закроется немедленно. Запись останется в списке — видно будет, что токен был и кем использовался."
                confirmLabel="Отозвать"
                cancelLabel="Отмена"
                isLoading={busy}
                colorPalette="red"
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
