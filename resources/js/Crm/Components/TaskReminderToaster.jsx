import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import TaskCloseDialog from '@/Crm/Components/TaskCloseDialog';
import { toaster } from '@/components/ui/toaster';
import { toastError, toastSuccess } from '@/utils/toast';

const POLL_MS = 60_000;
const MAX_TOASTS = 3;

/**
 * Тосты-напоминания по задачам на всех страницах CRM.
 *
 * Polling раз в минуту (вебсокетов в проекте нет намеренно); дедупликацию
 * между вкладками делает сервер — повод выдаётся ровно один раз. Тосты о
 * сроке не автозакрываются: напоминание, исчезнувшее за пять секунд, пока
 * менеджер говорил по телефону, — не напоминание.
 */
export default function TaskReminderToaster() {
    const { auth } = usePage().props;
    const [postponeTask, setPostponeTask] = useState(null);
    const timerRef = useRef(null);

    useEffect(() => {
        if (!auth?.user) {
            return undefined;
        }

        let stopped = false;

        const poll = async () => {
            try {
                const res = await axios.get('/crm/notifications/poll');

                if (stopped) {
                    return;
                }

                showReminders(res.data.reminders || [], setPostponeTask);
            } catch {
                // Сеть моргнула — следующий тик попробует снова.
            }
        };

        poll();
        timerRef.current = setInterval(poll, POLL_MS);

        return () => {
            stopped = true;
            clearInterval(timerRef.current);
        };
    }, [auth?.user?.id]);

    return (
        <TaskCloseDialog
            task={postponeTask}
            onClose={() => setPostponeTask(null)}
            onClosed={() => setPostponeTask(null)}
        />
    );
}

function showReminders(reminders, setPostponeTask) {
    const visible = reminders.slice(0, MAX_TOASTS);
    const rest = reminders.length - visible.length;

    visible.forEach((reminder) => {
        const { task } = reminder;

        toaster.create({
            title: reminder.kind_label,
            description: task.title + (task.due_at_label ? ` · ${task.due_at_label}` : ''),
            type: reminder.kind === 'overdue' ? 'error' : 'info',
            // Срок и просрочка висят до закрытия рукой.
            duration: reminder.sticky ? null : 8000,
            closable: true,
            meta: {
                buttons: [
                    {
                        label: 'Открыть',
                        onClick: () => router.visit(`/crm/tasks?task=${task.id}`),
                    },
                    {
                        label: 'Перенести на завтра',
                        onClick: async () => {
                            try {
                                const due = new Date();
                                due.setDate(due.getDate() + 1);
                                const time = (task.due_at || '').slice(11, 16) || '10:00';
                                const pad = (value) => String(value).padStart(2, '0');
                                const target = `${due.getFullYear()}-${pad(due.getMonth() + 1)}-${pad(due.getDate())}T${time}`;

                                await axios.post(`/crm/tasks/${task.id}/postpone`, { due_at: target });
                                toastSuccess('Срок перенесён на завтра', task.title);
                            } catch (e) {
                                toastError('Не удалось перенести', e?.response?.data?.message || 'Попробуйте ещё раз.');
                            }
                        },
                    },
                    {
                        label: 'Выбрать дату…',
                        onClick: () => setPostponeTask(task),
                    },
                ],
            },
        });
    });

    if (rest > 0) {
        toaster.create({
            title: `И ещё ${rest} задач требуют внимания`,
            type: 'info',
            duration: 10000,
            closable: true,
            meta: {
                buttons: [{
                    label: 'Открыть раздел',
                    onClick: () => router.visit('/crm/tasks?preset=mine'),
                }],
            },
        });
    }
}
