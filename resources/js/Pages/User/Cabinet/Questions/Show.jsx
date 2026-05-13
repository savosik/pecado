import { Badge, Box, Flex, Heading, HStack, Stack, Text } from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import { LuArrowLeft, LuCircleCheck, LuClock, LuDownload, LuPaperclip } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Button } from '@/components/ui/button';

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

export default function CabinetQuestionShow({ question }) {
    const isAnswered = question.status === 'answered';

    return (
        <CabinetLayout title="Вопрос">
            <Head title={question.subject} />

            <Box mb="4">
                <Button variant="ghost" onClick={() => router.visit('/cabinet/questions')}>
                    <LuArrowLeft /> К списку вопросов
                </Button>
            </Box>

            <Stack gap="4">
                {/* Шапка */}
                <Box bg="bg" border="1px solid" borderColor="border.muted" borderRadius="lg" p="5">
                    <Flex justify="space-between" align="flex-start" gap="3" mb="3">
                        <Heading size="lg">{question.subject}</Heading>
                        <Badge colorPalette={question.status_color} size="lg">{question.status_label}</Badge>
                    </Flex>
                    <HStack gap="2" color="gray.500" fontSize="sm">
                        <LuClock size={14} />
                        <Text>Отправлен {formatDate(question.created_at)}</Text>
                    </HStack>
                </Box>

                {/* Вопрос */}
                <Box bg="bg" border="1px solid" borderColor="border.muted" borderRadius="lg" p="5">
                    <Heading size="sm" mb="2" color="gray.600">Ваш вопрос</Heading>
                    <Text whiteSpace="pre-wrap">{question.body}</Text>

                    {question.attachment && (
                        <HStack
                            mt="4"
                            p="3"
                            bg="gray.50"
                            _dark={{ bg: 'gray.700' }}
                            borderRadius="md"
                        >
                            <LuPaperclip />
                            <Stack gap="0" flex="1" minW="0">
                                <Text fontSize="sm" fontWeight="medium" truncate>
                                    {question.attachment.name}
                                </Text>
                                <Text fontSize="xs" color="gray.500">
                                    {formatSize(question.attachment.size)}
                                </Text>
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

                {/* Ответ или статус */}
                {isAnswered && question.answer ? (
                    <Box
                        bg="green.50"
                        _dark={{ bg: 'green.900' }}
                        border="1px solid"
                        borderColor="green.200"
                        borderRadius="lg"
                        p="5"
                    >
                        <HStack mb="2">
                            <Box color="green.500"><LuCircleCheck size={20} /></Box>
                            <Heading size="sm" color="green.700">
                                Ответ менеджера
                            </Heading>
                            {question.answered_at && (
                                <Text fontSize="xs" color="gray.500">
                                    {formatDate(question.answered_at)}
                                </Text>
                            )}
                        </HStack>
                        <Text whiteSpace="pre-wrap">{question.answer}</Text>
                    </Box>
                ) : (
                    <Box
                        bg="blue.50"
                        _dark={{ bg: 'blue.900' }}
                        border="1px solid"
                        borderColor="blue.200"
                        borderRadius="lg"
                        p="5"
                    >
                        <HStack>
                            <LuClock size={18} />
                            <Text fontSize="sm">
                                Вопрос в обработке. Ответ появится здесь и придёт на email.
                            </Text>
                        </HStack>
                    </Box>
                )}
            </Stack>
        </CabinetLayout>
    );
}
