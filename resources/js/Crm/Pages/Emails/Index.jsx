import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import { usePermission } from '@/shared/Panel/usePermission';
import { LuMail, LuPaperclip, LuPencil, LuSend, LuTrash2 } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

const selectStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '170px',
};

/**
 * Журнал писем: что кому отправляли и чем закончилось.
 *
 * Отправленное письмо неизменяемо — журнал, который можно переписать задним числом,
 * бесполезен как журнал. Править и удалять можно только черновики и то, что не ушло.
 */
export default function Index({ emails, filters, statuses, outboundEnabled, openEmailId }) {
    const { can } = usePermission();
    const [dialogEmail, setDialogEmail] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [busy, setBusy] = useState(false);

    // Ссылка из ленты клиента ведёт сюда с ?email=ID — открываем письмо сразу.
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

    const reload = () => router.reload({ only: ['emails'] });

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

    const openDialog = (email = null) => {
        setDialogEmail(email);
        setDialogOpen(true);
    };

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
                    <Text fontSize="xs" color="fg.muted">Кому: {row.to.join(', ')}</Text>
                </VStack>
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
            key: 'author',
            label: 'Автор',
            render: (_, row) => <Text fontSize="sm">{row.author?.name}</Text>,
        },
        {
            key: 'status',
            label: 'Статус',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Badge colorPalette={row.status_color} variant="subtle">{row.status_label}</Badge>
                    {row.sent_at_label && <Text fontSize="xs" color="fg.muted">{row.sent_at_label}</Text>}
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
                            title="Удалить черновик"
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
                description="Что отправляли клиентам и чем это закончилось"
                actions={can('crm-emails.create')
                    ? <Button size="sm" onClick={() => openDialog(null)}><LuMail /> Написать письмо</Button>
                    : null}
            />

            <VStack align="stretch" gap={4}>
                {!outboundEnabled && (
                    <Alert status="warning" title="Отправка писем выключена">
                        Письма можно составлять и сохранять черновиками, но отправка заблокирована
                        администратором (флаг MAIL_FEATURE_CRM_OUTBOUND).
                    </Alert>
                )}

                <HStack gap={3} align="center" flexWrap="wrap">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search || ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Поиск по теме и тексту..."
                        />
                    </Box>

                    <select
                        value={filters.status || ''}
                        onChange={(e) => apply({ status: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Любой статус</option>
                        {statuses.map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>
                </HStack>

                <DataTable data={emails.data} columns={columns} pagination={emails} />
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
                title="Удалить черновик?"
                description="Неотправленное письмо будет удалено безвозвратно."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
