import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Badge, Box, Card, Heading, HStack, Input, SimpleGrid, Stack, Text, Textarea, VStack } from '@chakra-ui/react';
import { LuArrowLeft, LuCheck, LuDownload, LuPaperclip, LuTriangleAlert, LuX } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toastSuccess } from '@/utils/toast';

const formatSize = (bytes) => {
    if (!bytes) return '';
    const units = ['Б', 'КБ', 'МБ', 'ГБ'];
    let n = bytes;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i += 1;
    }
    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
};

function InfoRow({ label, children }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            {children}
        </Box>
    );
}

/**
 * Карточка вопроса клиента: кто спросил, что спросил, ответ менеджера.
 *
 * Открытие карточки переводит новый вопрос «в работу» — клиент в кабинете
 * видит, что его читают. Ответ уходит письмом клиенту (гостю — на его адрес),
 * отклонение — тихое: спам и оффтопик письма не заслуживают.
 */
export default function Show({ question, canEdit = false }) {
    const [rejecting, setRejecting] = useState(false);

    const answerForm = useForm({ answer: question.answer ?? '' });
    const rejectForm = useForm({ rejected_reason: question.rejected_reason ?? '' });

    const isAnswered = question.status === 'answered';
    const isRejected = question.status === 'rejected';

    const submitAnswer = (e) => {
        e.preventDefault();
        answerForm.post(route('crm.questions.answer', question.id), {
            preserveScroll: true,
            onSuccess: () => toastSuccess('Ответ отправлен', 'Клиент получит письмо с вашим ответом.'),
        });
    };

    const submitReject = (e) => {
        e.preventDefault();
        rejectForm.post(route('crm.questions.reject', question.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRejecting(false);
                toastSuccess('Вопрос отклонён', 'Клиент письма не получит.');
            },
        });
    };

    return (
        <>
            <Head title={`CRM — Вопрос №${question.id}`} />
            <PageHeader
                title={`Вопрос №${question.id}`}
                description={question.subject}
                actions={(
                    <HStack gap={2}>
                        <Badge colorPalette={question.status_color} size="lg">{question.status_label}</Badge>
                        <Link href={route('crm.questions.index')}>
                            <Button size="sm" variant="ghost"><LuArrowLeft /> К списку</Button>
                        </Link>
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                <Card.Root>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                            <InfoRow label="От кого">
                                {question.client
                                    ? (
                                        <Link href={question.client.url}>
                                            <Text fontSize="sm" fontWeight="500" color="blue.600">{question.client.name}</Text>
                                        </Link>
                                    )
                                    : (
                                        <HStack gap={1.5}>
                                            <Text fontSize="sm" fontWeight="500">{question.name || 'Без имени'}</Text>
                                            <Badge size="xs" variant="subtle" colorPalette="gray">гость</Badge>
                                        </HStack>
                                    )}
                                <Text fontSize="xs" color="fg.muted">{question.email}</Text>
                            </InfoRow>
                            <InfoRow label="Менеджер партнёра">
                                <Text fontSize="sm" color={question.manager ? undefined : 'fg.muted'}>{question.manager || 'не закреплён'}</Text>
                            </InfoRow>
                            <InfoRow label="Задан">
                                <Text fontSize="sm">{question.created_at}</Text>
                            </InfoRow>
                            {question.answered_at && (
                                <InfoRow label="Отвечен">
                                    <Text fontSize="sm">{question.answered_at}{question.answered_by ? ` — ${question.answered_by}` : ''}</Text>
                                </InfoRow>
                            )}
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        <Heading size="sm" mb="2">Вопрос</Heading>
                        <Text whiteSpace="pre-wrap" fontSize="sm">{question.body}</Text>

                        {question.attachment && (
                            <HStack mt="3" p="2" bg="bg.subtle" borderRadius="sm">
                                <LuPaperclip />
                                <Stack gap="0" flex="1">
                                    <Text fontSize="sm" fontWeight="medium">{question.attachment.name}</Text>
                                    <Text fontSize="xs" color="fg.muted">{formatSize(question.attachment.size)}</Text>
                                </Stack>
                                <Button size="sm" variant="outline" onClick={() => window.open(question.attachment.url, '_blank')}>
                                    <LuDownload /> Скачать
                                </Button>
                            </HStack>
                        )}
                    </Card.Body>
                </Card.Root>

                {!isRejected && (
                    <Card.Root as="form" onSubmit={submitAnswer}>
                        <Card.Body>
                            <Heading size="sm" mb="3">{isAnswered ? 'Отправленный ответ' : 'Ваш ответ'}</Heading>
                            <Field invalid={!!answerForm.errors.answer} errorText={answerForm.errors.answer}>
                                <Textarea
                                    value={answerForm.data.answer}
                                    onChange={(e) => answerForm.setData('answer', e.target.value)}
                                    placeholder="Напишите ответ клиенту…"
                                    rows={6}
                                    resize="vertical"
                                    readOnly={!canEdit}
                                />
                            </Field>
                            {canEdit && (
                                <HStack mt="3" gap="2" flexWrap="wrap">
                                    <Button type="submit" colorPalette="green" loading={answerForm.processing}>
                                        <LuCheck /> {isAnswered ? 'Обновить ответ' : 'Отправить ответ'}
                                    </Button>
                                    {!isAnswered && !rejecting && (
                                        <Button type="button" colorPalette="red" variant="outline" onClick={() => setRejecting(true)}>
                                            <LuTriangleAlert /> Отклонить (спам)
                                        </Button>
                                    )}
                                </HStack>
                            )}
                            {!isAnswered && (
                                <Text fontSize="xs" color="fg.muted" mt="2">
                                    Клиент получит ответ письмом и увидит его в кабинете.
                                </Text>
                            )}
                        </Card.Body>
                    </Card.Root>
                )}

                {canEdit && rejecting && !isRejected && (
                    <Card.Root as="form" onSubmit={submitReject} borderColor="red.200">
                        <Card.Body>
                            <Heading size="sm" mb="2">Отклонить вопрос</Heading>
                            <Text fontSize="sm" mb="3">Вопрос будет помечен как отклонённый. Клиент письма не получит.</Text>
                            <Field
                                label="Причина (для истории, клиенту не отправляется)"
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
                            <HStack mt="3" gap="2">
                                <Button type="submit" colorPalette="red" loading={rejectForm.processing}>Отклонить</Button>
                                <Button type="button" variant="outline" onClick={() => setRejecting(false)}><LuX /> Отмена</Button>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {isRejected && (
                    <Card.Root borderColor="red.200">
                        <Card.Body>
                            <Heading size="sm" mb="2" color="red.700">Вопрос отклонён</Heading>
                            <Text fontSize="sm">{question.rejected_reason || 'Без указания причины.'}</Text>
                            <Text fontSize="xs" color="fg.muted" mt="2">Клиент письма об отклонении не получал.</Text>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
