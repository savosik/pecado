import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Input, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import MailNav from '@/Crm/Pages/Emails/components/MailNav';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { LuFilter, LuMail } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';

/**
 * Стоп-лист адресов.
 *
 * Отвечает на единственный вопрос: почему на этот адрес не уходят письма.
 * Отдельным разделом меню не заводится — это настройка внутри «Писем».
 */
export default function Suppressions({ suppressions, canManage }) {
    const [email, setEmail] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const reload = () => router.reload();

    const add = async () => {
        setBusy(true);
        try {
            const res = await axios.post(route('crm.emails.suppressions.store'), { email, note });
            toastSuccess(res.data?.message || 'Добавлено');
            setEmail('');
            setNote('');
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Проверьте адрес.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (id) => {
        setBusy(true);
        try {
            await axios.delete(route('crm.emails.suppressions.destroy', id));
            toastSuccess('Адрес снова получает письма');
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const del = useConfirmDelete({
        title: 'Убрать адрес из стоп-листа?',
        description: (row) => `На «${row?.email ?? ''}» снова начнут уходить письма.`,
        confirmLabel: 'Убрать',
        onConfirm: (row) => remove(row.id),
    });

    const columns = [
        {
            key: 'email',
            label: 'Адрес',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" fontWeight="600">{row.email}</Text>
                    {row.note && <Text fontSize="xs" color="fg.muted" maxW="380px">{row.note}</Text>}
                </VStack>
            ),
        },
        {
            key: 'reason',
            label: 'Почему',
            render: (_, row) => <Text fontSize="sm">{row.reason_label}</Text>,
        },
        {
            key: 'scope',
            label: 'Что не уходит',
            render: (_, row) => <Badge variant="subtle">{row.scope_label}</Badge>,
        },
        {
            key: 'created',
            label: 'Когда',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="xs" color="fg.muted">{row.created_at_label}</Text>
                    {row.expires_at_label && (
                        <Text fontSize="xs" color="fg.muted">до {row.expires_at_label}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    delete={{
                        allowed: Boolean(canManage),
                        disabled: busy,
                        label: 'Убрать из стоп-листа',
                        onClick: () => del.request(row),
                    }}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Стоп-лист писем" />
            <PageHeader
                title="Стоп-лист"
                description="Адреса, на которые письма не уходят, и почему"
            />

            <MailNav description="Адреса, на которые письма не уходят: клиент отписался или почта отбивает доставку. Обычная отписка настраивается партнёром в кабинете — сюда попадает то, что мы гасим со своей стороны." />

            <VStack align="stretch" gap={4}>
                <Alert status="info" title="Как адрес сюда попадает">
                    Человек нажал «отписаться» в письме, почтовый сервер отверг адрес как
                    несуществующий, либо сотрудник внёс адрес руками. Пока адрес здесь,
                    автоматическая отправка на него отказывается работать и пишет причину
                    в карточке письма.
                </Alert>

                {canManage && (
                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <HStack gap={2} flexWrap="wrap" align="end">
                            <Box>
                                <Text fontSize="sm" fontWeight="600" mb={1}>Адрес</Text>
                                <Input
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    placeholder="buh@romashka.ru"
                                    minW="260px"
                                />
                            </Box>
                            <Box flex="1" minW="240px">
                                <Text fontSize="sm" fontWeight="600" mb={1}>Пояснение</Text>
                                <Input
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                    placeholder="Клиент попросил не писать на этот адрес"
                                />
                            </Box>
                            <Button size="sm" onClick={add} loading={busy} disabled={!email}>
                                Добавить
                            </Button>
                        </HStack>
                    </Box>
                )}

                <DataTable
                    data={suppressions}
                    columns={columns}
                    emptyMessage="Стоп-лист пуст — письма уходят на все адреса"
                />
            </VStack>

            <ConfirmDialog {...del.dialogProps} />
        </>
    );
}

Suppressions.layout = (page) => <CrmLayout>{page}</CrmLayout>;
