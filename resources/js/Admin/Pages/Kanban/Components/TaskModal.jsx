import React, { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Box, VStack, HStack, Heading, Text, Button, IconButton, Input, Textarea, NativeSelect } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';
import CommentSection from './CommentSection';
import AttachmentSection from './AttachmentSection';
import VoiceMicButton from './VoiceMicButton';

export default function TaskModal({ isOpen, onClose, task, defaultStatus }) {
    const isEdit = !!task;

    const [form, setForm] = useState({
        title: task?.title || '',
        description: task?.description || '',
        status: task?.status || defaultStatus || 'todo',
        page_url: task?.page_url || '',
        browser: task?.browser || '',
        user_name: task?.user_name || ''
    });

    useEffect(() => {
        if (task) {
            setForm({
                title: task.title || '',
                description: task.description || '',
                status: task.status || 'todo',
                page_url: task.page_url || '',
                browser: task.browser || '',
                user_name: task.user_name || ''
            });
        }
    }, [task]);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setForm(prev => ({ ...prev, [name]: value }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (isEdit) {
            router.put(route('admin.kanban.update', task.id), form, {
                onSuccess: () => onClose()
            });
        } else {
            router.post(route('admin.kanban.store'), form, {
                onSuccess: () => onClose()
            });
        }
    };

    if (!isOpen) return null;

    return (
        <Box
            position="fixed" top={0} left={0} w="100vw" h="100vh"
            bg="black/60" zIndex={1400}
            display="flex" alignItems="center" justifyContent="center"
            onClick={onClose}
        >
            <Box
                bg="white" _dark={{ bg: 'gray.800' }}
                w="100%" maxW="800px" maxH="90vh"
                rounded="lg" shadow="xl" overflow="hidden"
                display="flex" flexDirection="column"
                onClick={(e) => e.stopPropagation()}
            >
                <HStack p={4} borderBottomWidth="1px" borderColor="border.default" justify="space-between">
                    <Heading size="md">{isEdit ? 'Редактировать задачу' : 'Новая задача'}</Heading>
                    <IconButton variant="ghost" onClick={onClose}><LuX /></IconButton>
                </HStack>

                <Box p={6} overflowY="auto" flex="1">
                    <form id="task-form" onSubmit={handleSubmit}>
                        <VStack align="stretch" gap={4}>
                            <Box>
                                <Text fontWeight="medium" mb={1}>Название</Text>
                                <Input name="title" value={form.title} onChange={handleChange} required />
                            </Box>

                            <Box>
                                <HStack justify="space-between" mb={1}>
                                    <Text fontWeight="medium">Описание</Text>
                                    <VoiceMicButton onText={(t) => setForm(p => ({ ...p, description: p.description ? p.description + ' ' + t : t }))} />
                                </HStack>
                                <Textarea name="description" value={form.description} onChange={handleChange} rows={4} />
                            </Box>

                            <HStack gap={4}>
                                <Box flex={1}>
                                    <Text fontWeight="medium" mb={1}>Статус</Text>
                                    <NativeSelect.Root>
                                        <NativeSelect.Field name="status" value={form.status} onChange={handleChange}>
                                            <option value="backlog">БЭКЛОГ</option>
                                            <option value="todo">К ВЫПОЛНЕНИЮ</option>
                                            <option value="in_progress">В РАБОТЕ</option>
                                            <option value="testing">ТЕСТИРОВАНИЕ</option>
                                            <option value="done">ГОТОВО</option>
                                            <option value="reopen">ОТКРЫТО ПОВТОРНО</option>
                                        </NativeSelect.Field>
                                    </NativeSelect.Root>
                                </Box>
                                <Box flex={1}>
                                    <Text fontWeight="medium" mb={1}>Имя пользователя</Text>
                                    <Input name="user_name" value={form.user_name} onChange={handleChange} />
                                </Box>
                            </HStack>

                            <HStack gap={4}>
                                <Box flex={1}>
                                    <Text fontWeight="medium" mb={1}>URL страницы</Text>
                                    <Input name="page_url" type="url" value={form.page_url} onChange={handleChange} />
                                </Box>
                                <Box flex={1}>
                                    <Text fontWeight="medium" mb={1}>Браузер</Text>
                                    <Input name="browser" value={form.browser} onChange={handleChange} />
                                </Box>
                            </HStack>
                        </VStack>
                    </form>

                    {isEdit && <AttachmentSection task={task} />}
                    {isEdit && <CommentSection task={task} />}
                </Box>

                <HStack p={4} borderTopWidth="1px" borderColor="border.default" justify="flex-end">
                    <Button variant="outline" onClick={onClose}>Отмена</Button>
                    <Button type="submit" form="task-form" colorPalette="blue">Сохранить</Button>
                </HStack>
            </Box>
        </Box>
    );
}
