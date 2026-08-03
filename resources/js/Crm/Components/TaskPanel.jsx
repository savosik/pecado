import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Box, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuPlus } from 'react-icons/lu';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import TaskDialog from '@/Crm/Components/TaskDialog';
import TaskListItem from '@/Crm/Components/TaskListItem';
import { usePermission } from '@/shared/Panel/usePermission';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Врезка «Задачи по этой сущности».
 *
 * Тип и ID передаются пропсами, всё остальное компонент делает сам — так же, как
 * `CommentThread`. Встраивается в карточку клиента, заказа и реализации и дальше
 * везде, где появится привязка из `CrmEntityMap`.
 *
 * @param {string} entityType — 'client' | 'order' | 'shipment'
 * @param {number} entityId
 */
export default function TaskPanel({ entityType, entityId }) {
    const { can } = usePermission();
    const [tasks, setTasks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const [dialogTask, setDialogTask] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        setFailed(false);
        try {
            const res = await axios.get('/crm/tasks/list', {
                params: { entity_type: entityType, entity_id: entityId },
            });
            setTasks(res.data.data);
        } catch (e) {
            setFailed(true);
            if (![403, 404].includes(e?.response?.status)) {
                toastError('Не удалось загрузить задачи', 'Попробуйте обновить страницу.');
            }
        } finally {
            setLoading(false);
        }
    }, [entityType, entityId]);

    useEffect(() => { load(); }, [load]);

    const toggleDone = async (task) => {
        setBusy(true);
        try {
            const res = await axios.patch(`/crm/tasks/${task.id}`, {
                status: task.status === 'done' ? 'open' : 'done',
            });
            setTasks((prev) => prev.map((item) => (item.id === task.id ? res.data : item)));
        } catch (e) {
            toastError('Статус не изменён', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (id) => {
        setBusy(true);
        try {
            await axios.delete(`/crm/tasks/${id}`);
            setTasks((prev) => prev.filter((item) => item.id !== id));
            toastSuccess('Задача удалена');
        } catch (e) {
            toastError('Не удалось удалить задачу', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
            setPendingDelete(null);
        }
    };

    const openDialog = (task = null) => {
        setDialogTask(task);
        setDialogOpen(true);
    };

    const onSaved = (saved) => {
        setTasks((prev) => (prev.some((item) => item.id === saved.id)
            ? prev.map((item) => (item.id === saved.id ? saved : item))
            : [saved, ...prev]));
    };

    return (
        <VStack align="stretch" gap={3}>
            {can('crm-tasks.create') && (
                <HStack justify="flex-end">
                    <Button size="sm" onClick={() => openDialog(null)}>
                        <LuPlus /> Поставить задачу
                    </Button>
                </HStack>
            )}

            {loading && tasks.length === 0 ? (
                <HStack justify="center" py={6}><Spinner size="sm" /></HStack>
            ) : tasks.length === 0 ? (
                <Box py={4}>
                    <Text fontSize="sm" color="fg.muted">
                        {failed ? 'Задачи недоступны.' : 'Задач по этой записи пока нет.'}
                    </Text>
                </Box>
            ) : (
                <VStack align="stretch" gap={2}>
                    {tasks.map((task) => (
                        <TaskListItem
                            key={task.id}
                            task={task}
                            busy={busy}
                            onEdit={openDialog}
                            onToggleDone={toggleDone}
                            onDelete={(item) => setPendingDelete(item.id)}
                        />
                    ))}
                </VStack>
            )}

            <TaskDialog
                open={dialogOpen}
                task={dialogTask}
                entity={{ type: entityType, id: entityId }}
                onClose={() => setDialogOpen(false)}
                onSaved={onSaved}
            />

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => remove(pendingDelete)}
                title="Удалить задачу?"
                description="Задача пропадёт из списков и из ленты клиента. Восстановить её сможет только администратор."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </VStack>
    );
}
