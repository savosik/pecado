import { Badge, Box, Flex, HStack, Stack, Text } from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { LuChevronRight, LuMessageSquare, LuPaperclip } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import EmptyState from '@/components/common/EmptyState';
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

export default function CabinetQuestionsIndex() {
    const { questions } = usePage().props;
    const items = questions?.data ?? [];

    return (
        <CabinetLayout title="Мои вопросы">
            <Head title="Мои вопросы" />

            {items.length === 0 ? (
                <EmptyState
                    icon={LuMessageSquare}
                    title="У вас пока нет вопросов"
                    description="Задайте вопрос на странице FAQ — ответ появится здесь."
                    action={{ label: 'Перейти к FAQ', href: '/faq' }}
                />
            ) : (
                <Stack gap="3">
                    {items.map((q) => (
                        <Link key={q.id} href={`/cabinet/questions/${q.id}`}>
                            <Box
                                bg="bg"
                                border="1px solid"
                                borderColor="border.muted"
                                borderRadius="lg"
                                p="4"
                                _hover={{ borderColor: 'pecado.300', boxShadow: 'sm' }}
                                transition="all 0.15s"
                                cursor="pointer"
                            >
                                <Flex justify="space-between" align="flex-start" gap="3">
                                    <Stack gap="1" flex="1" minW="0">
                                        <HStack gap="2" flexWrap="wrap">
                                            <Badge colorPalette={q.status_color}>{q.status_label}</Badge>
                                            {q.has_attachment && (
                                                <Badge colorPalette="gray" variant="subtle">
                                                    <LuPaperclip size={11} /> Файл
                                                </Badge>
                                            )}
                                            <Text fontSize="xs" color="gray.500">
                                                {formatDate(q.created_at)}
                                            </Text>
                                        </HStack>
                                        <Text fontWeight="semibold" fontSize="md" truncate>
                                            {q.subject}
                                        </Text>
                                        {q.has_answer && (
                                            <Text fontSize="sm" color="green.600">
                                                Получен ответ {formatDate(q.answered_at)}
                                            </Text>
                                        )}
                                    </Stack>
                                    <Box color="gray.400" flexShrink="0">
                                        <LuChevronRight size={20} />
                                    </Box>
                                </Flex>
                            </Box>
                        </Link>
                    ))}

                    {questions.last_page > 1 && (
                        <HStack justify="center" gap="2" mt="4">
                            {Array.from({ length: questions.last_page }, (_, i) => i + 1).map((p) => (
                                <Button
                                    key={p}
                                    size="sm"
                                    variant={p === questions.current_page ? 'solid' : 'outline'}
                                    colorPalette={p === questions.current_page ? 'pecado' : 'gray'}
                                    onClick={() => router.get('/cabinet/questions', { page: p }, { preserveScroll: true })}
                                >
                                    {p}
                                </Button>
                            ))}
                        </HStack>
                    )}
                </Stack>
            )}
        </CabinetLayout>
    );
}
