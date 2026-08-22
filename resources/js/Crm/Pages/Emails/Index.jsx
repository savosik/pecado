import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack, Wrap } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import { usePermission } from '@/shared/Panel/usePermission';
import { LuBot, LuFilter, LuMail, LuPaperclip, LuPencil, LuSend, LuTrash2, LuUser } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

const selectStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '170px',
};

/**
 * Письма: один поток и папки поверх него.
 *
 * Здесь лежит и то, что написал менеджер, и то, что собрала система по поводу —
 * изменился заказ, выложен акт сверки, подошёл срок оплаты. Разницы в работе нет:
 * тот же список, тот же самолётик. Кому уйдёт письмо, решают правила-фильтры.
 *
 * Отправленное письмо неизменяемо — журнал, который можно переписать задним числом,
 * бесполезен как журнал.
 */
export default function Index({
    emails,
    filters,
    folders = [],
    outboundEnabled,
    openEmailId,
    unmatchedSummary = null,
    canManageRules = false,
    canSeeDepartment = false,
}) {
    const { can } = usePermission();
    const [dialogEmail, setDialogEmail] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [busy, setBusy] = useState(false);

    // Ссылка из ленты партнёра ведёт сюда с ?email=ID — открываем письмо сразу.
    useEffect(() => {
        if (!openEmailId) {
            return;
        }

        axios.get(`/crm/emails/${openEmailId}`)
            .then((res) => {
                setDialogEmail(res.data);
                setDialogOpen(true);
            })
            .catch(() => {});
    }, [openEmailId]);

    const apply = (patch) => {
        router.get(route('crm.emails.index'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const reload = () => router.reload();

    const send = async (email) => {
        setBusy(true);
        try {
            await axios.post(`/crm/emails/${email.id}/send`);
            toastSuccess('Письмо отправлено');
            reload();
        } catch (e) {
            toastError('Письмо не отправлено', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (id) => {
        setBusy(true);
        try {
            await axios.delete(`/crm/emails/${id}`);
            toastSuccess('Черновик удалён');
            reload();
        } catch (e) {
            toastError('Не удалось удалить', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
            setPendingDelete(null);
        }
    };

    const bulk = async (url, ids, fallback) => {
        if (!ids.length) {
            return;
        }

        setBusy(true);
        try {
            const res = await axios.post(url, { ids });
            toastSuccess(res.data?.message || fallback);
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const openDialog = (email = null) => {
        setDialogEmail(email);
        setDialogOpen(true);
    };

    const folder = filters.folder || 'drafts';
    const isWorkFolder = folder === 'drafts' || folder === 'unmatched';

    const bulkActions = isWorkFolder
        ? [
            ...(outboundEnabled && can('crm-emails.create')
                ? [{
                    label: 'Отправить выбранные',
                    colorPalette: 'blue',
                    action: (ids) => bulk(route('crm.emails.bulk-send'), ids, 'Отправлено'),
                }]
                : []),
            ...(can('crm-emails.delete')
                ? [{
                    label: 'Удалить выбранные',
                    colorPalette: 'red',
                    action: (ids) => bulk(route('crm.emails.bulk-delete'), ids, 'Удалено'),
                }]
                : []),
        ]
        : [];

    const columns = [
        {
            key: 'subject',
            label: 'Письмо',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <HStack gap={2}>
                        <Text fontSize="sm" fontWeight="600">{row.subject}</Text>
                        {row.attachments_count > 0 && (
                            <HStack gap={1} color="fg.muted">
                                <LuPaperclip size={12} />
                                <Text fontSize="xs">{row.attachments_count}</Text>
                            </HStack>
                        )}
                    </HStack>
                    <Text fontSize="xs" color="fg.muted">
                        {row.to?.length ? `Кому: ${row.to.join(', ')}` : 'Получатели не проставлены'}
                    </Text>
                    {row.delivered_to?.length > 0 && (
                        <Text fontSize="xs" color="green.600">
                            Уже ушло: {row.delivered_to.join(', ')}
                        </Text>
                    )}
                    {row.tags?.length > 0 && (
                        <Wrap gap={1}>
                            {row.tags.slice(0, 6).map((tag) => (
                                <Badge key={tag} size="sm" variant="outline" colorPalette="gray">{tag}</Badge>
                            ))}
                            {row.tags.length > 6 && (
                                <Text fontSize="xs" color="fg.muted">+{row.tags.length - 6}</Text>
                            )}
                        </Wrap>
                    )}
                </VStack>
            ),
        },
        {
            key: 'origin',
            label: 'Кем создано',
            render: (_, row) => (
                <HStack gap={2} color={row.origin === 'system' ? 'purple.500' : 'fg.muted'}>
                    {row.origin === 'system' ? <LuBot size={14} /> : <LuUser size={14} />}
                    <VStack align="start" gap={0}>
                        <Text fontSize="sm">{row.origin_label}</Text>
                        {row.origin !== 'system' && (
                            <Text fontSize="xs" color="fg.muted">{row.author?.name}</Text>
                        )}
                    </VStack>
                </HStack>
            ),
        },
        {
            key: 'entity',
            label: 'Привязка',
            render: (_, row) => (row.entity
                ? (
                    <VStack align="start" gap={0}>
                        <Text fontSize="xs" color="fg.muted">{row.entity.label}</Text>
                        {row.entity.url
                            ? <a href={row.entity.url}><Text fontSize="sm">{row.entity.title}</Text></a>
                            : <Text fontSize="sm">{row.entity.title}</Text>}
                    </VStack>
                )
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            key: 'status',
            label: 'Состояние',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Badge colorPalette={row.status_color} variant="subtle">{row.status_label}</Badge>
                    {row.sent_at_label && <Text fontSize="xs" color="fg.muted">{row.sent_at_label}</Text>}
                    {row.auto_sent_rule && (
                        <Text fontSize="xs" color="purple.500">Правило «{row.auto_sent_rule}»</Text>
                    )}
                    {row.skip_reason && (
                        <Text fontSize="xs" color="orange.600" maxW="220px">{row.skip_reason}</Text>
                    )}
                    {row.error && <Text fontSize="xs" color="red.500" maxW="220px">{row.error}</Text>}
                </VStack>
            ),
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <HStack gap={1}>
                    <Button size="xs" variant="ghost" onClick={() => openDialog(row)} title="Открыть письмо">
                        <LuPencil />
                    </Button>
                    {row.can?.send && outboundEnabled && (
                        <Button
                            size="xs"
                            variant="ghost"
                            colorPalette="blue"
                            disabled={busy}
                            onClick={() => send(row)}
                            title="Отправить"
                        >
                            <LuSend />
                        </Button>
                    )}
                    {row.can?.delete && (
                        <Button
                            size="xs"
                            variant="ghost"
                            colorPalette="red"
                            disabled={busy}
                            onClick={() => setPendingDelete(row.id)}
                            title="Удалить письмо"
                        >
                            <LuTrash2 />
                        </Button>
                    )}
                </HStack>
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Письма" />
            <PageHeader
                title="Письма"
                description="Один поток: что написали сами и что собрала система"
                actions={(
                    <HStack gap={2}>
                        {canManageRules && (
                            <Link href={route('crm.emails.rules.index')}>
                                <Button size="sm" variant="outline"><LuFilter /> Правила</Button>
                            </Link>
                        )}
                        {can('crm-emails.create') && (
                            <Button size="sm" onClick={() => openDialog(null)}><LuMail /> Написать письмо</Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                {!outboundEnabled && (
                    <Alert status="warning" title="Отправка писем выключена">
                        Письма можно составлять и сохранять черновиками, но отправка заблокирована
                        администратором (флаг MAIL_FEATURE_CRM_OUTBOUND).
                    </Alert>
                )}

                {/* Папки. Это не новая сущность, а другой показ того же списка:
                    состояние письма и есть папка. */}
                <Wrap gap={2}>
                    {folders.map((item) => (
                        <Button
                            key={item.value}
                            size="sm"
                            variant={folder === item.value ? 'solid' : 'outline'}
                            colorPalette={folder === item.value ? 'blue' : 'gray'}
                            title={item.hint}
                            onClick={() => apply({ folder: item.value })}
                        >
                            {item.label}
                            <Badge ml={2} variant="subtle" colorPalette={folder === item.value ? 'blue' : 'gray'}>
                                {item.count}
                            </Badge>
                        </Button>
                    ))}
                </Wrap>

                {unmatchedSummary && unmatchedSummary.rows.length > 0 && (
                    <Alert status="info" title="Это система умеет, но никто не забирает">
                        <VStack align="stretch" gap={1} mt={2}>
                            {unmatchedSummary.rows.map((row) => (
                                <HStack key={row.event} gap={3}>
                                    <Text fontSize="sm" flex="1">{row.label} — {row.total}</Text>
                                    {canManageRules && row.tag && (
                                        <Link href={route('crm.emails.rules.index', { tag: row.tag })}>
                                            <Button size="xs" variant="outline"><LuFilter /> Настроить</Button>
                                        </Link>
                                    )}
                                </HStack>
                            ))}
                            <Text fontSize="xs" color="fg.muted">
                                Ни одно правило их не ловит. Письма хранятся {unmatchedSummary.retention_days} дн.
                                и удаляются.
                            </Text>
                        </VStack>
                    </Alert>
                )}

                <HStack gap={3} align="center" flexWrap="wrap">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search || ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Поиск по теме, тексту и адресу..."
                        />
                    </Box>

                    <select
                        value={filters.origin || ''}
                        onChange={(e) => apply({ origin: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Кем угодно</option>
                        <option value="manual">Написал менеджер</option>
                        <option value="system">Собрала система</option>
                    </select>

                    <ScopeToggle
                        section="emails"
                        scope={filters.scope}
                        available={canSeeDepartment}
                    />
                </HStack>

                <DataTable
                    data={emails.data}
                    columns={columns}
                    pagination={emails}
                    selectable={bulkActions.length > 0}
                    bulkActions={bulkActions}
                    emptyMessage="В этой папке пусто"
                />
            </VStack>

            <EmailComposeDialog
                open={dialogOpen}
                email={dialogEmail}
                onClose={() => setDialogOpen(false)}
                onSaved={reload}
            />

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => remove(pendingDelete)}
                title="Удалить письмо?"
                description="Неотправленное письмо будет удалено безвозвратно."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
