import { useState } from 'react';
import {
    Badge, Box, Dialog, Flex, Heading, HStack, Input, Portal, Stack, Text, Textarea,
} from '@chakra-ui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { LuArrowLeft, LuCheck, LuDownload, LuPaperclip, LuTriangleAlert } from 'react-icons/lu';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/Admin/hooks/usePermission';

const formatDate = (iso) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
};

const formatSize = (bytes) => {
    if (!bytes) return '';
    const units = ['Б', 'КБ', 'МБ', 'ГБ'];
    let n = bytes;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
};

export default function UserQuestionShow({ question, statuses }) {
    const { can } = usePermission();
    const [rejectOpen, setRejectOpen] = useState(false);

    const answerForm = useForm({ answer: question.answer ?? '' });
    const rejectForm = useForm({ rejected_reason: question.rejected_reason ?? '' });

    const isAnswered = question.status === 'answered';
    const isRejected = question.status === 'rejected';
    const isClosed = isAnswered || isRejected;

    const submitAnswer = (e) => {
        e.preventDefault();
        answerForm.post(route('admin.user-questions.answer', question.id), {
            preserveScroll: true,
            onSuccess: () => toaster.create({
                title: 'Ответ отправлен',
                description: 'Пользователю отправлено уведомление.',
                type: 'success',
            }),
        });
    };

    const submitReject = (e) => {
        e.preventDefault();
        rejectForm.post(route('admin.user-questions.reject', question.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRejectOpen(false);
                toaster.create({
                    description: 'Вопрос отклонён. Пользователь не получит уведомление.',
                    type: 'success',
                });
            },
        });
    };

    const changeStatus = (status) => {
        router.patch(route('admin.user-questions.status', question.id), { status }, {
            preserveScroll: true,
            onSuccess: () => toaster.create({ description: 'Статус обновлён', type: 'success' }),
        });
    };

    return (
        <AdminLayout>
            <Head title={`Вопрос #${question.id}`} />

            <Flex justify="space-between" align="center" mb="4">
                <Button variant="ghost" onClick={() => router.visit(route('admin.user-questions.index'))}>
                    <LuArrowLeft /> К списку
                </Button>
                <HStack>
                    <Badge colorPalette={question.status_color} size="lg">{question.status_label}</Badge>
                </HStack>
            </Flex>

            <PageHeader title={`Вопрос #${question.id}: ${question.subject}`} />

            <Flex direction={{ base: 'column', lg: 'row' }} gap="6" align="flex-start">
                <Box flex="1" minW="0">
                    {/* Метаданные */}
                    <Box bg="bg" border="1px solid" borderColor="border.muted" borderRadius="md" p="4" mb="4">
                        <Stack gap="2" fontSize="sm">
                            <HStack>
                                <Text color="gray.500" w="140px">От кого:</Text>
                                <Text fontWeight="medium">{question.name || '—'}</Text>
                                <Text color="gray.500">&lt;{question.email}&gt;</Text>
                                {question.is_registered ? (
                                    <Badge colorPalette="purple" variant="subtle" size="xs">Зарегистрирован</Badge>
                                ) : (
                                    <Badge colorPalette="gray" variant="subtle" size="xs">Гость</Badge>
                                )}
                            </HStack>
                            <HStack>
                                <Text color="gray.500" w="140px">Дата:</Text>
                                <Text>{formatDate(question.created_at)}</Text>
                            </HStack>
                            {question.answered_at && (
                                <HStack>
                                    <Text color="gray.500" w="140px">Отвечен:</Text>
                                    <Text>{formatDate(question.answered_at)}</Text>
                                    {question.answered_by && (
                                        <Text color="gray.500">— {question.answered_by.name}</Text>
                                    )}
                                </HStack>
                            )}
                            {question.ip && (
                                <HStack>
                                    <Text color="gray.500" w="140px">IP:</Text>
                                    <Text fontFamily="mono" fontSize="xs">{question.ip}</Text>
                                </HStack>
                            )}
                        </Stack>
                    </Box>

                    {/* Вопрос */}
                    <Box bg="bg" border="1px solid" borderColor="border.muted" borderRadius="md" p="4" mb="4">
                        <Heading size="sm" mb="2">Вопрос</Heading>
                        <Text whiteSpace="pre-wrap">{question.body}</Text>

                        {question.attachment && (
                            <HStack mt="3" p="2" bg="gray.50" _dark={{ bg: 'gray.700' }} borderRadius="sm">
                                <LuPaperclip />
                                <Stack gap="0" flex="1">
                                    <Text fontSize="sm" fontWeight="medium">{question.attachment.name}</Text>
                                    <Text fontSize="xs" color="gray.500">{formatSize(question.attachment.size)}</Text>
                                </Stack>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => window.open(question.attachment.url, '_blank')}
                                >
                                    <LuDownload /> Скачать
                                </Button>
                            </HStack>
                        )}
                    </Box>

                    {/* Форма ответа */}
                    {!isRejected && (
                        <Box
                            as="form"
                            onSubmit={submitAnswer}
                            bg="bg"
                            border="1px solid"
                            borderColor="border.muted"
                            borderRadius="md"
                            p="4"
                            mb="4"
                        >
                            <Heading size="sm" mb="3">
                                {isAnswered ? 'Отправленный ответ' : 'Ваш ответ'}
                            </Heading>
                            <Field
                                invalid={!!answerForm.errors.answer}
                                errorText={answerForm.errors.answer}
                            >
                                <Textarea
                                    value={answerForm.data.answer}
                                    onChange={(e) => answerForm.setData('answer', e.target.value)}
                                    placeholder="Напишите развёрнутый ответ..."
                                    rows={6}
                                    resize="vertical"
                                />
                            </Field>
                            {can('user-questions.edit') && (
                                <HStack mt="3" gap="2">
                                    <Button
                                        type="submit"
                                        colorPalette="green"
                                        loading={answerForm.processing}
                                    >
                                        <LuCheck /> {isAnswered ? 'Обновить ответ' : 'Отправить ответ'}
                                    </Button>
                                    {!isAnswered && (
                                        <Button
                                            type="button"
                                            colorPalette="red"
                                            variant="outline"
                                            onClick={() => setRejectOpen(true)}
                                        >
                                            <LuTriangleAlert /> Отклонить (спам)
                                        </Button>
                                    )}
                                </HStack>
                            )}
                        </Box>
                    )}

                    {/* Информация об отклонении */}
                    {isRejected && (
                        <Box bg="red.50" _dark={{ bg: 'red.900' }} border="1px solid" borderColor="red.200" borderRadius="md" p="4" mb="4">
                            <Heading size="sm" mb="2" color="red.700">Вопрос отклонён</Heading>
                            {question.rejected_reason ? (
                                <Text fontSize="sm">{question.rejected_reason}</Text>
                            ) : (
                                <Text fontSize="sm" color="gray.600">Без указания причины.</Text>
                            )}
                            <Text fontSize="xs" color="gray.500" mt="2">
                                Пользователь не получил уведомление об отклонении.
                            </Text>
                        </Box>
                    )}
                </Box>

                {/* Боковая панель: смена статуса */}
                <Box w={{ base: '100%', lg: '280px' }} flexShrink="0">
                    <Box bg="bg" border="1px solid" borderColor="border.muted" borderRadius="md" p="4">
                        <Heading size="sm" mb="3">Управление</Heading>
                        <Field label="Статус">
                            <select
                                value={question.status}
                                onChange={(e) => changeStatus(e.target.value)}
                                disabled={!can('user-questions.edit')}
                                style={{
                                    padding: '6px 8px',
                                    border: '1px solid #ddd',
                                    borderRadius: 4,
                                    width: '100%',
                                }}
                            >
                                {(statuses || []).map((s) => (
                                    <option key={s.value} value={s.value}>{s.label}</option>
                                ))}
                            </select>
                        </Field>
                    </Box>
                </Box>
            </Flex>

            <Dialog.Root open={rejectOpen} onOpenChange={({ open }) => setRejectOpen(open)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content as="form" onSubmit={submitReject}>
                            <Dialog.Header>
                                <Dialog.Title>Отклонить вопрос</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text mb="3" fontSize="sm">
                                    Вопрос будет помечен как отклонённый. Пользователь не получит уведомление.
                                </Text>
                                <Field
                                    label="Причина (для истории, не отправляется)"
                                    optionalText="необязательно"
                                    invalid={!!rejectForm.errors.rejected_reason}
                                    errorText={rejectForm.errors.rejected_reason}
                                >
                                    <Input
                                        value={rejectForm.data.rejected_reason}
                                        onChange={(e) => rejectForm.setData('rejected_reason', e.target.value)}
                                        placeholder="Например: спам, оффтопик"
                                        maxLength={500}
                                    />
                                </Field>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" type="button" onClick={() => setRejectOpen(false)}>
                                    Отмена
                                </Button>
                                <Button type="submit" colorPalette="red" loading={rejectForm.processing}>
                                    Отклонить
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </AdminLayout>
    );
}
