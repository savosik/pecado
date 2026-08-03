import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Badge, Box, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { LuUsers, LuUsersRound, LuBriefcase } from 'react-icons/lu';
import TaskDialog from '@/Crm/Components/TaskDialog';
import TaskListItem from '@/Crm/Components/TaskListItem';
import { toastError } from '@/utils/toast';

function StatCard({ label, value, icon: Icon }) {
    return (
        <Card.Root>
            <Card.Body>
                <VStack align="start" gap={2}>
                    <Box color="fg.muted">
                        <Icon size={20} />
                    </Box>
                    <Text fontSize="xs" color="fg.muted">{label}</Text>
                    <Text fontSize="2xl" fontWeight="bold">{value}</Text>
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}

/**
 * «Мои задачи на сегодня»: просроченное и то, что горит сегодня, одним списком.
 *
 * Просрочку не выносим отдельно — менеджеру важно «что делать сейчас»,
 * а не «в какой день это протухло».
 */
function TodayTasks({ tasks }) {
    const [dialogTask, setDialogTask] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [closingTask, setClosingTask] = useState(null);
    const [busy, setBusy] = useState(false);

    // Закрытие задачи меняет и покрытие клиентов — перечитываем оба блока.
    const refresh = () => router.reload({ only: ['tasks', 'coverage'] });

    // Закрытие идёт через диалог: там спрашивают, что сделано и что дальше.
    const toggleDone = async (task) => {
        if (task.status !== 'done') {
            setClosingTask(task);

            return;
        }

        setBusy(true);
        try {
            await axios.patch(`/crm/tasks/${task.id}`, { status: 'open' });
            refresh();
        } catch (e) {
            toastError('Статус не изменён', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card.Root>
            <Card.Header>
                <HStack justify="space-between" flexWrap="wrap" gap={2}>
                    <HStack gap={2}>
                        <Text fontWeight="semibold">Мои задачи на сегодня</Text>
                        {tasks.overdue_count > 0 && (
                            <Badge colorPalette="red" variant="solid">просрочено: {tasks.overdue_count}</Badge>
                        )}
                        {tasks.today_count > 0 && (
                            <Badge colorPalette="blue" variant="subtle">на сегодня: {tasks.today_count}</Badge>
                        )}
                    </HStack>
                    <Button size="xs" variant="outline" onClick={() => router.visit(route('crm.tasks.index'))}>
                        Все задачи
                    </Button>
                </HStack>
            </Card.Header>
            <Card.Body>
                {tasks.items.length === 0 ? (
                    <Text fontSize="sm" color="fg.muted">
                        На сегодня задач нет и просроченных тоже — можно заняться клиентами.
                    </Text>
                ) : (
                    <VStack align="stretch" gap={2}>
                        {tasks.items.map((task) => (
                            <TaskListItem
                                key={task.id}
                                task={task}
                                showEntity
                                busy={busy}
                                onEdit={(item) => { setDialogTask(item); setDialogOpen(true); }}
                                onToggleDone={toggleDone}
                            />
                        ))}
                    </VStack>
                )}
            </Card.Body>

            <TaskDialog
                open={dialogOpen}
                task={dialogTask}
                onClose={() => setDialogOpen(false)}
                onSaved={refresh}
            />

            <TaskCloseDialog
                task={closingTask}
                onClose={() => setClosingTask(null)}
                onClosed={refresh}
            />
        </Card.Root>
    );
}

/**
 * Покрытие клиентов задачами.
 *
 * Клиент без следующего шага — это клиент, о котором вспомнят, только когда он сам
 * позвонит. Поэтому блок не ограничивается процентом: он сразу называет первых
 * из непокрытых и даёт поставить задачу, не уходя со страницы.
 */
function ClientCoverage({ coverage }) {
    const [taskFor, setTaskFor] = useState(null);

    if (!coverage.clients_total) {
        return null;
    }

    const allCovered = coverage.uncovered_count === 0;

    return (
        <Card.Root>
            <Card.Header>
                <HStack justify="space-between" flexWrap="wrap" gap={2}>
                    <HStack gap={2}>
                        <Text fontWeight="semibold">Покрытие клиентов задачами</Text>
                        <Badge colorPalette={allCovered ? 'green' : 'orange'} variant="subtle">
                            {coverage.covered_percent}% из {coverage.clients_total}
                        </Badge>
                    </HStack>
                    {!allCovered && (
                        <Button
                            size="xs"
                            variant="outline"
                            onClick={() => router.visit(route('crm.clients.index', { coverage: 'uncovered' }))}
                        >
                            Показать всех ({coverage.uncovered_count})
                        </Button>
                    )}
                </HStack>
            </Card.Header>
            <Card.Body>
                {allCovered ? (
                    <Text fontSize="sm" color="fg.muted">
                        По каждому клиенту есть следующий шаг. Так и держать.
                    </Text>
                ) : (
                    <VStack align="stretch" gap={2}>
                        <Text fontSize="sm" color="fg.muted">
                            По этим клиентам не поставлено ни одной незакрытой задачи:
                        </Text>
                        {coverage.examples.map((client) => (
                            <HStack key={client.id} justify="space-between" gap={2}>
                                <a href={route('crm.clients.show', client.id)}>
                                    <Text fontSize="sm">{client.name}</Text>
                                </a>
                                <Button
                                    size="xs"
                                    variant="ghost"
                                    onClick={() => setTaskFor(client)}
                                >
                                    Поставить задачу
                                </Button>
                            </HStack>
                        ))}
                    </VStack>
                )}
            </Card.Body>

            <TaskDialog
                open={taskFor !== null}
                entity={taskFor ? { type: 'client', id: taskFor.id } : null}
                onClose={() => setTaskFor(null)}
                onSaved={() => router.reload({ only: ['coverage', 'tasks'] })}
            />
        </Card.Root>
    );
}

export default function Dashboard() {
    const { stats, seesAll, managerProfileLinked, auth, tasks, coverage } = usePage().props;

    return (
        <>
            <Head title="CRM — Рабочий стол" />
            <PageHeader
                title={`Здравствуйте, ${auth?.user?.name || 'коллега'}`}
                description={seesAll ? 'Обзор по отделу продаж' : 'Обзор по вашим клиентам'}
            />

            <VStack gap={4} align="stretch">
                {!managerProfileLinked && (
                    <Alert
                        status="warning"
                        title="Аккаунт не связан с карточкой менеджера"
                    >
                        Ваш аккаунт не связан с карточкой персонального менеджера, поэтому клиенты не отображаются.
                        Обратитесь к администратору — привязка настраивается в разделе «Персональные менеджеры».
                    </Alert>
                )}

                <SimpleGrid columns={{ base: 1, md: seesAll ? 3 : 1 }} gap={4}>
                    <StatCard
                        label={seesAll ? 'Клиентов вам видно' : 'Моих клиентов'}
                        value={stats.visible_clients}
                        icon={LuUsers}
                    />
                    {seesAll && (
                        <>
                            <StatCard label="Клиентов в отделе" value={stats.department_clients} icon={LuBriefcase} />
                            <StatCard label="Менеджеров" value={stats.managers} icon={LuUsersRound} />
                        </>
                    )}
                </SimpleGrid>

                {tasks && <TodayTasks tasks={tasks} />}

                {coverage && <ClientCoverage coverage={coverage} />}

                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" color="fg.muted">
                            Раздел в разработке: здесь появятся сделки, планы продаж и воронка.
                            Сейчас доступны «Мои клиенты», «Задачи»{seesAll ? ' и «Команда»' : ''}.
                        </Text>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Dashboard.layout = (page) => <CrmLayout>{page}</CrmLayout>;
