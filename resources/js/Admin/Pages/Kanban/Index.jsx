import React, { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Heading, Flex, VStack, HStack, Text, Button, IconButton } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import AdminLayout from '../../Layouts/AdminLayout';
import TaskCard from './Components/TaskCard';
import TaskModal from './Components/TaskModal';

const statusLabels = {
    todo: 'К ВЫПОЛНЕНИЮ',
    in_progress: 'В РАБОТЕ',
    testing: 'ТЕСТИРОВАНИЕ',
    done: 'ГОТОВО',
    reopen: 'ОТКРЫТО ПОВТОРНО'
};

export default function Index({ tasks, columns }) {
    const [localTasks, setLocalTasks] = useState(tasks || []);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedTask, setSelectedTask] = useState(null);
    const [currentStatus, setCurrentStatus] = useState('todo');

    // Поддерживаем локальный стейт при обновлении пропсов
    useEffect(() => {
        setLocalTasks(tasks);
    }, [tasks]);

    const handleDragStart = (e, task) => {
        e.dataTransfer.setData('taskId', task.id);
    };

    const handleDragOver = (e) => {
        e.preventDefault();
    };

    const handleDrop = (e, status) => {
        e.preventDefault();
        const taskId = e.dataTransfer.getData('taskId');
        if (!taskId) return;

        const task = localTasks.find(t => t.id === parseInt(taskId));
        if (task && task.status !== status) {
            // Оптимистичное обновление UI
            setLocalTasks(prev => prev.map(t =>
                t.id === parseInt(taskId) ? { ...t, status: status } : t
            ));

            // Отправляем на сервер
            router.put(route('admin.kanban.update-order', task.id), {
                status: status,
                order: 0, // Упрощенная сортировка, кидаем в начало колонки
            }, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    const handleOpenModal = (task = null, status = 'todo') => {
        setSelectedTask(task);
        setCurrentStatus(status);
        setIsModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
        setSelectedTask(null);
    };

    return (
        <>
            <Head title="Канбан доска" />
            <VStack align="stretch" gap={6} h="calc(100vh - 120px)">
                <HStack justify="space-between">
                    <Heading size="lg">Канбан доска</Heading>
                    <Button colorScheme="blue" onClick={() => handleOpenModal(null, 'todo')}>
                        <LuPlus /> Добавить задачу
                    </Button>
                </HStack>

                <Flex gap={4} overflowX="auto" pb={4} h="full" alignItems="flex-start">
                    {columns.map(status => {
                        const columnTasks = localTasks
                            .filter(t => t.status === status)
                            .sort((a, b) => a.order - b.order);

                        return (
                            <Box
                                key={status}
                                w="320px"
                                minW="320px"
                                bg="gray.100"
                                _dark={{ bg: 'gray.800' }}
                                p={3}
                                rounded="lg"
                                display="flex"
                                flexDirection="column"
                                maxH="100%"
                                onDragOver={handleDragOver}
                                onDrop={(e) => handleDrop(e, status)}
                            >
                                <HStack justify="space-between" mb={4}>
                                    <Text fontWeight="bold" fontSize="sm" color="fg.muted">
                                        {statusLabels[status] || status} ({columnTasks.length})
                                    </Text>
                                    <IconButton
                                        size="xs"
                                        variant="ghost"
                                        aria-label="Add task"
                                        onClick={() => handleOpenModal(null, status)}
                                    >
                                        <LuPlus />
                                    </IconButton>
                                </HStack>

                                <VStack overflowY="auto" gap={3} align="stretch" pb={2} flex="1">
                                    {columnTasks.map(task => (
                                        <TaskCard
                                            key={task.id}
                                            task={task}
                                            onDragStart={handleDragStart}
                                            onTaskClick={handleOpenModal}
                                        />
                                    ))}
                                    {columnTasks.length === 0 && (
                                        <Box
                                            p={6}
                                            borderWidth="2px"
                                            borderStyle="dashed"
                                            borderColor="border.subtle"
                                            rounded="md"
                                            textAlign="center"
                                            color="fg.muted"
                                        >
                                            <Text fontSize="sm">Перетащите задачу сюда</Text>
                                        </Box>
                                    )}
                                </VStack>
                            </Box>
                        );
                    })}
                </Flex>
            </VStack>

            {isModalOpen && (
                <TaskModal
                    isOpen={isModalOpen}
                    onClose={handleCloseModal}
                    task={selectedTask}
                    defaultStatus={currentStatus}
                />
            )}
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
