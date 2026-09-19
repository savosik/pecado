import { useEffect, useState } from 'react';
import { Badge, Box, HStack, Stack, Text } from '@chakra-ui/react';
import { LuBot, LuChevronLeft, LuDownload, LuPaperclip } from 'react-icons/lu';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { Button } from '@/components/ui/button';
import RowActions from '@/shared/Panel/RowActions';

const formatDate = (iso) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return iso;
    }
};

const formatUsd = (usd) => `$${Number(usd || 0).toFixed(2)}`;

const PAGE_LABELS = {
    product: 'товар', catalog: 'каталог', search: 'поиск', cart: 'корзина', checkout: 'оформление', order: 'заказ',
    orders: 'заказы', reserves: 'резервы', shipments: 'реализации', documents: 'документы', finance: 'оплаты',
    returns: 'возвраты', promotions: 'акции', faq: 'FAQ', cabinet: 'кабинет', home: 'главная', other: 'сайт',
};

function Message({ message }) {
    if (message.kind === 'confirmation') {
        return (
            <Text fontSize="xs" color="fg.subtle" textAlign="center">
                {message.text === 'Подтверждено' ? 'Клиент подтвердил действие' : 'Клиент отменил действие'}
            </Text>
        );
    }

    const mine = message.role === 'user';

    return (
        <Box alignSelf={mine ? 'flex-end' : 'flex-start'} maxW="85%">
            {!mine && message.tools?.length > 0 && (
                <Text fontSize="2xs" color="fg.subtle">{message.tools.join(', ')}</Text>
            )}
            <Box
                bg={mine ? 'blue.50' : message.status === 'failed' ? 'red.50' : 'bg.muted'}
                px="3"
                py="2"
                borderRadius="lg"
                fontSize="sm"
                css={{ '& p': { margin: '0.25em 0' }, '& ul, & ol': { paddingLeft: '1.25em' }, '& table': { fontSize: '12px', borderCollapse: 'collapse' }, '& td, & th': { border: '1px solid var(--chakra-colors-border-muted)', padding: '2px 6px' } }}
            >
                {mine ? <Text whiteSpace="pre-wrap">{message.text}</Text> : <ReactMarkdown remarkPlugins={[remarkGfm]}>{message.text || '…'}</ReactMarkdown>}
            </Box>
            <HStack gap="2" mt="0.5" fontSize="2xs" color="fg.subtle" justify={mine ? 'flex-end' : 'flex-start'}>
                <Text>{formatDate(message.created_at)}</Text>
                {!mine && message.cost > 0 && <Text>{formatUsd(message.cost)}</Text>}
                {message.attachments?.map((a) => (
                    <HStack key={a.id} gap="1" as="a" href={a.download_url} target="_blank" rel="noreferrer" color="blue.600">
                        <LuPaperclip size={10} />
                        <Text>{a.name}</Text>
                        <LuDownload size={10} />
                    </HStack>
                ))}
            </HStack>
        </Box>
    );
}

/**
 * Вкладка «Помощник» в карточке партнёра: список тредов, переписка, заметка
 * помощника о клиенте. Только чтение — менеджер понимает, что клиент спрашивал,
 * и вмешивается письмом или задачей, а не правкой чужого разговора.
 */
export default function AssistantPanel({ clientId }) {
    const [data, setData] = useState(null);
    const [thread, setThread] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        window.axios.get(`/crm/partners/${clientId}/assistant`)
            .then(({ data: d }) => { if (alive) setData(d); })
            .catch(() => { if (alive) setError('Не удалось загрузить переписку.'); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [clientId]);

    const openThread = (id) => {
        setLoading(true);
        window.axios.get(`/crm/partners/${clientId}/assistant/${id}`)
            .then(({ data: d }) => setThread(d))
            .catch(() => setError('Не удалось открыть разговор.'))
            .finally(() => setLoading(false));
    };

    if (error) return <Text fontSize="sm" color="red.600">{error}</Text>;
    if (loading && !data) return <Text fontSize="sm" color="fg.muted">Загрузка…</Text>;

    if (thread) {
        return (
            <Stack gap="3">
                <HStack justify="space-between">
                    <Button size="xs" variant="ghost" onClick={() => setThread(null)}>
                        <LuChevronLeft /> К списку
                    </Button>
                    <HStack gap="2" fontSize="xs" color="fg.subtle">
                        <Text>{formatDate(thread.thread.created_at)}</Text>
                        <Text>·</Text>
                        <Text>{formatUsd(thread.thread.cost)}</Text>
                        {thread.thread.status === 'closed' && <Badge size="xs" colorPalette="gray">закрыт</Badge>}
                    </HStack>
                </HStack>
                {thread.thread.summary && (
                    <Box bg="yellow.50" borderRadius="md" p="2" fontSize="xs">
                        <Text fontWeight="semibold" mb="1">Резюме</Text>
                        <Text>{thread.thread.summary}</Text>
                    </Box>
                )}
                <Stack gap="2" maxH="60vh" overflowY="auto" pr="1">
                    {thread.messages.map((m) => <Message key={m.id} message={m} />)}
                </Stack>
            </Stack>
        );
    }

    const threads = data?.threads || [];

    return (
        <Stack gap="3">
            <Text fontSize="xs" color="fg.muted">
                Переписка партнёра с помощником на сайте — только чтение. Вмешаться можно письмом или задачей.
            </Text>
            {data?.note && (
                <Box bg="yellow.50" borderRadius="md" p="3" fontSize="sm">
                    <HStack justify="space-between" mb="1">
                        <Text fontWeight="semibold" fontSize="xs">Что помощник помнит о клиенте</Text>
                        <Text fontSize="2xs" color="fg.subtle">версия {data.note.version}, {formatDate(data.note.updated_at)}</Text>
                    </HStack>
                    <Text whiteSpace="pre-wrap" fontSize="xs">{data.note.content}</Text>
                </Box>
            )}
            {threads.length === 0 ? (
                <HStack gap="2" color="fg.subtle" fontSize="sm">
                    <LuBot size={16} />
                    <Text>Партнёр с помощником ещё не разговаривал.</Text>
                </HStack>
            ) : (
                <Stack gap="1">
                    {threads.map((t) => (
                        <HStack key={t.id} justify="space-between" borderWidth="1px" borderColor="border.muted" borderRadius="md" px="3" py="2" gap="3">
                            <Stack gap="0" flex="1" minW="0">
                                <HStack gap="2">
                                    <Text fontSize="sm" truncate>{t.title || 'Без темы'}</Text>
                                    {t.status === 'closed' && <Badge size="xs" colorPalette="gray">закрыт</Badge>}
                                    {t.page?.type && <Badge size="xs" variant="outline">{PAGE_LABELS[t.page.type] || t.page.type}</Badge>}
                                </HStack>
                                <Text fontSize="xs" color="fg.subtle">
                                    {formatDate(t.last_message_at)} · сообщений: {t.messages_count} · {formatUsd(t.cost)}
                                </Text>
                                {t.summary && <Text fontSize="xs" color="fg.muted" lineClamp={2}>{t.summary}</Text>}
                            </Stack>
                            <RowActions view={{ onClick: () => openThread(t.id) }} />
                        </HStack>
                    ))}
                </Stack>
            )}
        </Stack>
    );
}
