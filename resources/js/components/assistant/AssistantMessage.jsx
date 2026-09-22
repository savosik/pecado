import { Box, HStack, Text } from '@chakra-ui/react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { LuCircleAlert, LuCircleCheck, LuCircleX, LuPaperclip } from 'react-icons/lu';
import { useProductQuickView } from '@/contexts/ProductQuickViewContext';

const PRODUCT_LINK = /^(?:https?:\/\/[^/]+)?\/products\/([^/?#]+)\/?$/;

/**
 * Ссылка в ответе помощника: карточка товара открывается быстрым просмотром
 * поверх чата, остальное — в новой вкладке (подписанные ссылки на файлы,
 * разделы кабинета).
 */
function ChatLink({ href, children, openQuickView }) {
    const match = typeof href === 'string' ? href.match(PRODUCT_LINK) : null;

    if (match) {
        const slug = decodeURIComponent(match[1]);
        return (
            <a
                href={href}
                onClick={(e) => { e.preventDefault(); openQuickView(slug); }}
                title="Быстрый просмотр товара"
            >
                {children}
            </a>
        );
    }

    return <a href={href} target="_blank" rel="noreferrer">{children}</a>;
}

const TOOL_LABELS = {
    'client-catalog': 'смотрю доступные разделы',
    'client-prices': 'проверяю цены и остатки',
    'client-order-status': 'смотрю заказ',
    'client-create-order': 'готовлю заказ',
    'client-balance': 'смотрю взаиморасчёты',
    'client-documents': 'ищу документы',
    'client-promotions': 'смотрю акции',
    'client-faq': 'читаю условия',
    'client-ask-manager': 'передаю менеджеру',
    'client-call': 'выполняю операцию',
    'client-describe': 'уточняю параметры',
};

const markdownStyles = {
    '& p': { my: '1' },
    '& p:first-of-type': { mt: 0 },
    '& p:last-of-type': { mb: 0 },
    '& ul, & ol': { pl: '5', my: '1' },
    '& li': { my: '0.5' },
    '& strong': { fontWeight: 'semibold' },
    '& code': { fontFamily: 'mono', fontSize: 'xs', bg: 'bg.muted', px: '1', borderRadius: 'sm' },
    '& table': { borderCollapse: 'collapse', my: '2', fontSize: 'xs', width: '100%' },
    '& th, & td': { border: '1px solid', borderColor: 'border.muted', px: '2', py: '1', textAlign: 'left', verticalAlign: 'top' },
    '& th': { bg: 'bg.muted', fontWeight: 'semibold' },
    '& a': { color: 'pecado.600', textDecoration: 'underline' },
    '& h1, & h2, & h3': { fontSize: 'sm', fontWeight: 'semibold', mt: '2', mb: '1' },
};

function ToolTrail({ tools }) {
    if (!tools?.length) return null;
    const labels = tools.map((t) => TOOL_LABELS[t] || t);

    return (
        <Text fontSize="xs" color="fg.subtle" mb="1">
            {labels.join(', ')}
        </Text>
    );
}

/**
 * Один ход в диалоге: реплика клиента справа, ответ помощника слева.
 * Служебные ходы (подтверждение, отказ) — тонкой строкой по центру.
 */
export default function AssistantMessage({ message, streaming = false }) {
    const { openQuickView } = useProductQuickView();
    const components = {
        a: ({ href, children }) => <ChatLink href={href} openQuickView={openQuickView}>{children}</ChatLink>,
    };

    if (message.kind === 'confirmation') {
        const approved = message.text === 'Подтверждено';

        return (
            <HStack justify="center" gap="1" fontSize="xs" color="fg.subtle" py="1">
                {approved ? <LuCircleCheck size={12} /> : <LuCircleX size={12} />}
                <Text>{approved ? 'Вы подтвердили действие' : 'Вы отменили действие'}</Text>
            </HStack>
        );
    }

    const mine = message.role === 'user';
    const failed = message.status === 'failed';

    return (
        <Box alignSelf={mine ? 'flex-end' : 'flex-start'} maxW="88%">
            {!mine && <ToolTrail tools={message.tools} />}
            <Box
                bg={mine ? 'pecado.500' : failed ? 'red.50' : 'bg.muted'}
                color={mine ? 'white' : failed ? 'red.700' : 'fg'}
                px="3"
                py="2"
                borderRadius="xl"
                borderBottomRightRadius={mine ? 'sm' : 'xl'}
                borderBottomLeftRadius={mine ? 'xl' : 'sm'}
                fontSize="sm"
                lineHeight="1.5"
                css={mine ? undefined : markdownStyles}
                wordBreak="break-word"
            >
                {mine ? (
                    <Text whiteSpace="pre-wrap">{message.text}</Text>
                ) : failed ? (
                    <HStack gap="2" align="flex-start">
                        <LuCircleAlert size={14} style={{ marginTop: 3, flexShrink: 0 }} />
                        <Text>{message.text}</Text>
                    </HStack>
                ) : message.text ? (
                    <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>{message.text}</ReactMarkdown>
                ) : (
                    <Text color="fg.subtle">{streaming ? 'Печатает…' : '…'}</Text>
                )}
                {streaming && message.text && (
                    <Box as="span" display="inline-block" w="6px" h="14px" bg="fg.subtle" ml="1" verticalAlign="text-bottom" opacity="0.6" />
                )}
            </Box>
            {message.attachments?.length > 0 && (
                <HStack gap="2" mt="1" flexWrap="wrap" justify={mine ? 'flex-end' : 'flex-start'}>
                    {message.attachments.map((a) => (
                        <HStack key={a.id} gap="1" fontSize="xs" color="fg.subtle">
                            <LuPaperclip size={11} />
                            <Text>{a.name}</Text>
                        </HStack>
                    ))}
                </HStack>
            )}
        </Box>
    );
}
