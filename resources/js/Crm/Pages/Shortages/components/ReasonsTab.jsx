import { useEffect, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Badge, Box, HStack, IconButton, Input, Text, VStack } from '@chakra-ui/react';
import { LuArrowDown, LuArrowUp, LuPlus } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { DataTable } from '@/Admin/Components/DataTable';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Справочник причин недоборов — вкладка руководителя.
 *
 * Причина здесь строка таблицы, а не значение перечисления в коде: формулировки
 * меняются вместе с работой отдела, и ждать релиза ради «увели из-под резерва»
 * никто не будет. Категория при этом остаётся закрытым списком — на ней держатся
 * чипы и сводки, а свободные категории развели бы синонимы одной и той же зоны.
 *
 * Правки уходят по месту, без кнопки «Сохранить»: строк десяток, каждая правка
 * самостоятельна, и общая кнопка означала бы «применится когда-нибудь потом».
 */
export default function ReasonsTab({
    reasons = [],
    categories = [],
    canManage = false,
    canCreate = false,
    canDelete = false,
}) {
    const [rows, setRows] = useState(reasons);
    const [busy, setBusy] = useState(false);
    const [draft, setDraft] = useState({ name: '', category: categories[0]?.value ?? '', description: '' });

    useEffect(() => setRows(reasons), [reasons]);

    const refresh = () => router.reload({ only: ['reasons', 'reasonUsage', 'chips', 'rows'] });

    const patchLocal = (id, changes) => setRows((prev) => prev.map((row) => (
        row.id === id ? { ...row, ...changes } : row
    )));

    const save = async (row, changes) => {
        patchLocal(row.id, changes);

        try {
            await axios.patch(route('crm.shortage-reasons.update', row.id), changes);
            refresh();
        } catch (error) {
            patchLocal(row.id, row);
            toastError(firstError(error) ?? 'Не удалось сохранить причину.');
        }
    };

    /**
     * Порядок меняем обменом значений соседних строк: пересчитывать весь
     * справочник ради одной перестановки — десяток лишних запросов.
     */
    const move = async (index, direction) => {
        const target = index + direction;

        if (target < 0 || target >= rows.length) {
            return;
        }

        const current = rows[index];
        const neighbour = rows[target];

        setBusy(true);

        try {
            await Promise.all([
                axios.patch(route('crm.shortage-reasons.update', current.id), { sort_order: neighbour.sort_order }),
                axios.patch(route('crm.shortage-reasons.update', neighbour.id), { sort_order: current.sort_order }),
            ]);
            refresh();
        } catch {
            toastError('Не удалось изменить порядок причин.');
        } finally {
            setBusy(false);
        }
    };

    const add = async () => {
        if (draft.name.trim().length < 3) {
            toastError('Введите формулировку причины — хотя бы три символа.');

            return;
        }

        setBusy(true);

        try {
            await axios.post(route('crm.shortage-reasons.store'), {
                name: draft.name.trim(),
                category: draft.category,
                description: draft.description.trim() || null,
            });
            setDraft({ name: '', category: categories[0]?.value ?? '', description: '' });
            toastSuccess('Причина добавлена.');
            refresh();
        } catch (error) {
            toastError(firstError(error) ?? 'Не удалось добавить причину.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (row) => {
        try {
            await axios.delete(route('crm.shortage-reasons.destroy', row.id));
            toastSuccess('Причина удалена.');
            refresh();
        } catch (error) {
            // Причину с разметкой и заводскую удалить нельзя — сервер объясняет,
            // почему и что делать вместо этого. Показываем именно его объяснение.
            toastError(firstError(error) ?? 'Не удалось удалить причину.');
        }
    };

    const confirmRemove = useConfirmDelete({
        title: 'Удалить причину?',
        description: (row) => `Причина «${row?.name}» исчезнет из выпадающего списка. Разметку прошлых периодов удаление не трогает.`,
        onConfirm: remove,
    });

    const columns = [
        {
            key: 'name',
            label: 'Причина',
            render: (value, row) => (canManage ? (
                <Input
                    size="xs"
                    variant="subtle"
                    minW="240px"
                    defaultValue={value}
                    maxLength={191}
                    onBlur={(event) => {
                        const next = event.target.value.trim();

                        if (next && next !== row.name) {
                            save(row, { name: next });
                        }
                    }}
                />
            ) : <Text fontSize="sm">{value}</Text>),
        },
        {
            key: 'category',
            label: 'Категория',
            render: (value, row) => (canManage ? (
                <NativeSelectRoot size="xs" minW="170px">
                    <NativeSelectField
                        value={value}
                        onChange={(event) => save(row, { category: event.target.value })}
                        aria-label="Категория причины"
                    >
                        {categories.map((category) => (
                            <option key={category.value} value={category.value}>{category.label}</option>
                        ))}
                    </NativeSelectField>
                </NativeSelectRoot>
            ) : (
                <Badge colorPalette={row.color} variant="subtle">{row.category_label}</Badge>
            )),
        },
        {
            key: 'description',
            label: 'Пояснение для легенды',
            render: (value, row) => (canManage ? (
                <Input
                    size="xs"
                    variant="subtle"
                    minW="280px"
                    placeholder="Когда выбирать эту причину"
                    defaultValue={value ?? ''}
                    maxLength={500}
                    onBlur={(event) => {
                        const next = event.target.value.trim();

                        if (next !== (row.description ?? '')) {
                            save(row, { description: next || null });
                        }
                    }}
                />
            ) : <Text fontSize="sm" color="fg.muted">{value || '—'}</Text>),
        },
        {
            key: 'lines_count',
            label: 'Размечено строк',
            render: (value) => <Text fontSize="sm">{value ?? 0}</Text>,
        },
        {
            key: 'is_active',
            label: 'В списке',
            render: (value, row) => (canManage ? (
                <Switch
                    size="sm"
                    checked={Boolean(value)}
                    onCheckedChange={({ checked }) => save(row, { is_active: checked })}
                />
            ) : (
                <Badge colorPalette={value ? 'green' : 'gray'} variant="subtle">
                    {value ? 'да' : 'нет'}
                </Badge>
            )),
        },
        {
            key: 'is_system',
            label: 'Тип',
            render: (value) => (value
                ? <Badge colorPalette="gray" variant="outline">заводская</Badge>
                : <Text fontSize="xs" color="fg.muted">своя</Text>),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_value, row) => {
                const index = rows.findIndex((item) => item.id === row.id);

                return (
                    <HStack gap={1}>
                        {canManage && (
                            <>
                                <IconButton
                                    size="xs"
                                    variant="ghost"
                                    aria-label="Выше"
                                    disabled={busy || index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    <LuArrowUp />
                                </IconButton>
                                <IconButton
                                    size="xs"
                                    variant="ghost"
                                    aria-label="Ниже"
                                    disabled={busy || index === rows.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    <LuArrowDown />
                                </IconButton>
                            </>
                        )}

                        <RowActions
                            size="xs"
                            delete={canDelete && !row.is_system ? {
                                onClick: () => confirmRemove.request(row),
                                disabled: row.lines_count > 0
                                    ? 'Причиной размечены строки журнала — отключите её вместо удаления'
                                    : false,
                            } : undefined}
                        />
                    </HStack>
                );
            },
        },
    ];

    return (
        <VStack align="stretch" gap={3}>
            {canCreate && (
                <Box borderWidth="1px" borderRadius="lg" p={3} bg="bg.subtle">
                    <HStack gap={2} wrap="wrap" align="end">
                        <VStack align="start" gap={1} flex="1" minW="240px">
                            <Text fontSize="xs" color="fg.muted">Новая причина</Text>
                            <Input
                                size="sm"
                                placeholder="Например: не пришла поставка от поставщика"
                                value={draft.name}
                                maxLength={191}
                                onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                            />
                        </VStack>

                        <VStack align="start" gap={1} minW="180px">
                            <Text fontSize="xs" color="fg.muted">Категория</Text>
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={draft.category}
                                    onChange={(event) => setDraft({ ...draft, category: event.target.value })}
                                    aria-label="Категория новой причины"
                                >
                                    {categories.map((category) => (
                                        <option key={category.value} value={category.value}>{category.label}</option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </VStack>

                        <VStack align="start" gap={1} flex="1" minW="240px">
                            <Text fontSize="xs" color="fg.muted">Пояснение (необязательно)</Text>
                            <Input
                                size="sm"
                                placeholder="Когда менеджеру выбирать эту причину"
                                value={draft.description}
                                maxLength={500}
                                onChange={(event) => setDraft({ ...draft, description: event.target.value })}
                            />
                        </VStack>

                        <Button size="sm" colorPalette="pecado" loading={busy} onClick={add}>
                            <LuPlus /> Добавить
                        </Button>
                    </HStack>
                </Box>
            )}

            <DataTable data={rows} columns={columns} emptyMessage="Справочник причин пуст." />

            <Text fontSize="xs" color="fg.muted">
                Категория задаёт цвет, чип и место в легенде — её набор закрыт намеренно.
                Отключённая причина исчезает из выпадающего списка, но остаётся в сводках
                и в уже размеченных строках. Заводские причины удалить нельзя — только отключить.
            </Text>

            <ConfirmDialog {...confirmRemove.dialogProps} />
        </VStack>
    );
}

/** Первое внятное сообщение сервера: валидация Laravel кладёт его в errors. */
function firstError(error) {
    const errors = error?.response?.data?.errors;

    if (errors) {
        const first = Object.values(errors)[0];

        return Array.isArray(first) ? first[0] : first;
    }

    return error?.response?.data?.message;
}
