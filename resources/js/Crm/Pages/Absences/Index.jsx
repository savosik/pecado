import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Badge, Box, HStack, Input, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Alert } from '@/components/ui/alert';
import { LuCalendarPlus, LuCheck, LuTrash2 } from 'react-icons/lu';

/**
 * Отсутствия и замещения менеджеров (abs-02).
 *
 * Пока менеджер отсутствует и указан замещающий, партнёры видят в кабинете
 * контакты замещающего, а письма о заказах уходят ему. Управляет руководитель,
 * смотрит весь отдел.
 */
export default function Index() {
    const { absences = [], managers = [], types = [], canEdit = false, errors = {} } = usePage().props;

    const emptyForm = {
        personal_manager_id: '',
        substitute_manager_id: '',
        type: 'vacation',
        starts_on: '',
        ends_on: '',
        comment: '',
    };
    const [form, setForm] = useState(emptyForm);
    const [busy, setBusy] = useState(false);
    const [finishFor, setFinishFor] = useState(null);
    const [deleteFor, setDeleteFor] = useState(null);

    const set = (key) => (event) => setForm((prev) => ({ ...prev, [key]: event.target.value }));

    const submit = (event) => {
        event.preventDefault();
        setBusy(true);

        router.post(route('crm.absences.store'), {
            ...form,
            personal_manager_id: Number(form.personal_manager_id) || null,
            substitute_manager_id: Number(form.substitute_manager_id) || null,
        }, {
            preserveScroll: true,
            onSuccess: () => setForm(emptyForm),
            onFinish: () => setBusy(false),
        });
    };

    const finish = () => {
        setBusy(true);
        router.put(route('crm.absences.finish', finishFor.id), {}, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setFinishFor(null);
            },
        });
    };

    const destroy = () => {
        setBusy(true);
        router.delete(route('crm.absences.destroy', deleteFor.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setDeleteFor(null);
            },
        });
    };

    const statusBadge = (row) => {
        if (row.is_active) {
            return <Badge colorPalette="green" variant="subtle">Идёт</Badge>;
        }
        if (row.is_upcoming) {
            return <Badge colorPalette="blue" variant="subtle">Запланировано</Badge>;
        }
        return <Badge colorPalette="gray" variant="subtle">Завершено</Badge>;
    };

    const columns = [
        {
            key: 'manager',
            label: 'Менеджер',
            render: (_, row) => <Text fontWeight="semibold">{row.manager}</Text>,
        },
        {
            key: 'type',
            label: 'Тип',
            render: (_, row) => (
                <Badge colorPalette={row.type_color} variant="subtle">{row.type_label}</Badge>
            ),
        },
        {
            key: 'period',
            label: 'Период',
            render: (_, row) => <Text fontSize="sm">{row.starts_on} — {row.ends_on}</Text>,
        },
        {
            key: 'substitute',
            label: 'Замещает',
            render: (_, row) => (
                <Text fontSize="sm">{row.substitute || <Text as="span" color="fg.muted">без замещения</Text>}</Text>
            ),
        },
        {
            key: 'comment',
            label: 'Комментарий',
            render: (_, row) => <Text fontSize="sm" color="fg.muted">{row.comment || '—'}</Text>,
        },
        {
            key: 'status',
            label: 'Статус',
            render: (_, row) => statusBadge(row),
        },
        ...(canEdit ? [{
            key: 'actions',
            label: '',
            render: (_, row) => (
                <HStack gap={1} justify="flex-end">
                    {row.is_active && (
                        <Button
                            size="xs"
                            variant="ghost"
                            disabled={busy}
                            onClick={() => setFinishFor(row)}
                            title="Завершить досрочно"
                        >
                            <LuCheck /> Завершить
                        </Button>
                    )}
                    <Button
                        size="xs"
                        variant="ghost"
                        colorPalette="red"
                        disabled={busy}
                        onClick={() => setDeleteFor(row)}
                        title="Удалить запись"
                    >
                        <LuTrash2 />
                    </Button>
                </HStack>
            ),
        }] : []),
    ];

    return (
        <>
            <Head title="CRM — Отсутствия и замещения" />
            <PageHeader
                title="Отсутствия и замещения"
                description="Отпуска, отгулы и больничные менеджеров: кто отсутствует и кто ведёт его партнёров"
            />

            <VStack align="stretch" gap={5}>
                <Alert status="info" title="Как работает замещение">
                    Если у отсутствия указан замещающий, партнёры менеджера на этот период видят
                    в кабинете контакты замещающего с пояснением, а письма о новых заказах приходят ему.
                    Привязка партнёров к менеджеру при этом не меняется.
                </Alert>

                {canEdit && (
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                        <form onSubmit={submit}>
                            <HStack gap={3} align="end" flexWrap="wrap">
                                <Field label="Менеджер" required invalid={!!errors.personal_manager_id} errorText={errors.personal_manager_id} maxW="240px">
                                    <NativeSelectRoot>
                                        <NativeSelectField value={form.personal_manager_id} onChange={set('personal_manager_id')}>
                                            <option value="">Выберите менеджера</option>
                                            {managers.map((manager) => (
                                                <option key={manager.id} value={manager.id}>{manager.name}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Field label="Тип" required invalid={!!errors.type} errorText={errors.type} maxW="160px">
                                    <NativeSelectRoot>
                                        <NativeSelectField value={form.type} onChange={set('type')}>
                                            {types.map((type) => (
                                                <option key={type.value} value={type.value}>{type.label}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Field label="С" required invalid={!!errors.starts_on} errorText={errors.starts_on} maxW="170px">
                                    <Input type="date" value={form.starts_on} onChange={set('starts_on')} />
                                </Field>

                                <Field label="По (включительно)" required invalid={!!errors.ends_on} errorText={errors.ends_on} maxW="170px">
                                    <Input type="date" value={form.ends_on} onChange={set('ends_on')} />
                                </Field>

                                <Field
                                    label="Замещающий"
                                    invalid={!!errors.substitute_manager_id}
                                    errorText={errors.substitute_manager_id}
                                    helperText="Его контакты увидят партнёры, ему придут письма о заказах"
                                    maxW="240px"
                                >
                                    <NativeSelectRoot>
                                        <NativeSelectField value={form.substitute_manager_id} onChange={set('substitute_manager_id')}>
                                            <option value="">Без замещения</option>
                                            {managers
                                                .filter((manager) => String(manager.id) !== String(form.personal_manager_id))
                                                .map((manager) => (
                                                    <option key={manager.id} value={manager.id}>
                                                        {manager.name}{manager.has_email ? '' : ' (нет email)'}
                                                    </option>
                                                ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Field label="Комментарий" invalid={!!errors.comment} errorText={errors.comment} maxW="260px">
                                    <Textarea
                                        rows={1}
                                        value={form.comment}
                                        onChange={set('comment')}
                                        placeholder="Причина, номер приказа…"
                                    />
                                </Field>

                                <Button type="submit" disabled={busy || !form.personal_manager_id || !form.starts_on || !form.ends_on}>
                                    <LuCalendarPlus /> Добавить
                                </Button>
                            </HStack>
                        </form>
                    </Box>
                )}

                <DataTable data={absences} columns={columns} />
            </VStack>

            <ConfirmDialog
                open={finishFor !== null}
                onClose={() => setFinishFor(null)}
                onConfirm={finish}
                title={`Завершить отсутствие: ${finishFor?.manager}?`}
                description="Менеджер вышел раньше срока? Партнёры снова увидят его контакты уже сегодня, письма о заказах вернутся к нему."
                confirmLabel="Завершить"
                cancelLabel="Отмена"
                isLoading={busy}
            />

            <ConfirmDialog
                open={deleteFor !== null}
                onClose={() => setDeleteFor(null)}
                onConfirm={destroy}
                title={`Удалить запись: ${deleteFor?.manager}, ${deleteFor?.starts_on} — ${deleteFor?.ends_on}?`}
                description="Удаление — для ошибочно созданных записей: строка исчезнет и из табеля. Для досрочного выхода используйте «Завершить»."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
                colorPalette="red"
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
