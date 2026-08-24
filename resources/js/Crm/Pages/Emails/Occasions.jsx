import { Head, Link } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack, Wrap } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { LuFilter, LuMail, LuPlus } from 'react-icons/lu';

/**
 * Реестр поводов: что система вообще умеет присылать.
 *
 * Подписать партнёра можно только на то, что видно. До этого экрана список
 * событий существовал лишь в конфиге, и «на что бывает подписка» выяснялось
 * по письмам, которые уже пришли.
 *
 * Сортировка по «мимо фильтров» намеренная: сверху оказывается то, что
 * система собирает, а не получает никто, — единственное, что здесь требует
 * действия.
 */
export default function Occasions({ occasions = [], streamEnabled, days = 30 }) {
    return (
        <>
            <Head title="CRM — Поводы писем" />

            <PageHeader
                title="Поводы"
                description="События, по которым система собирает письма"
                actions={(
                    <HStack gap={2}>
                        <Link href={route('crm.emails.index')}>
                            <Button size="sm" variant="outline"><LuMail /> К письмам</Button>
                        </Link>
                        <Link href={route('crm.emails.rules.index')}>
                            <Button size="sm" variant="outline"><LuFilter /> Правила</Button>
                        </Link>
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                {!streamEnabled && (
                    <Alert status="info" title="Система пока не собирает письма сама">
                        Поводы перечислены, но письма по ним не создаются: сборка выключена
                        переменной MAIL_STREAM_ENABLED.
                    </Alert>
                )}

                <Text fontSize="sm" color="fg.muted">
                    Счётчики — за последние {days} дней. «Никто не получает» — письма,
                    которые система собрала, но ни одно правило их не подобрало.
                </Text>

                {occasions.map((occasion) => (
                    <Box key={occasion.key} borderWidth="1px" borderRadius="lg" p={4}>
                        <HStack justify="space-between" align="start" gap={4}>
                            <VStack align="stretch" gap={1} flex="1">
                                <HStack gap={2}>
                                    <Text fontWeight="600">{occasion.label}</Text>
                                    {!occasion.domain_enabled && (
                                        <Badge colorPalette="gray" variant="subtle">домен выключен</Badge>
                                    )}
                                    {occasion.unmatched > 0 && (
                                        <Badge colorPalette="orange" variant="subtle">
                                            никто не получает: {occasion.unmatched}
                                        </Badge>
                                    )}
                                </HStack>

                                <Text fontSize="xs" color="fg.muted">{occasion.key}</Text>

                                {occasion.subject && (
                                    <Text fontSize="sm" color="fg.muted">
                                        Тема: {occasion.subject}
                                    </Text>
                                )}

                                <Text fontSize="xs" color="fg.muted">
                                    Собрано за {days} дней: {occasion.collected}
                                </Text>

                                {occasion.tags?.length > 0 && (
                                    <Wrap gap={1} mt={1}>
                                        {occasion.tags.map((tag) => (
                                            <Badge key={tag} variant="outline" size="sm">{tag}</Badge>
                                        ))}
                                    </Wrap>
                                )}
                            </VStack>

                            <Link
                                href={route('crm.emails.rules.index', {
                                    tag: occasion.tags?.[0] || `повод:${occasion.key}`,
                                })}
                            >
                                <Button size="sm" variant="outline">
                                    <LuPlus /> Подписать
                                </Button>
                            </Link>
                        </HStack>
                    </Box>
                ))}
            </VStack>
        </>
    );
}

Occasions.layout = (page) => <CrmLayout>{page}</CrmLayout>;
