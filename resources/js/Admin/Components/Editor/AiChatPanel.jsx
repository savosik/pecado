import React, { useState, useRef, useEffect } from 'react';
import { Box, HStack, VStack, Text, Input, IconButton, Button, Flex } from '@chakra-ui/react';
import { LuSend, LuSparkles, LuUser, LuBot, LuLoader, LuPenLine, LuListPlus, LuScissors, LuWand } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

/**
 * Quick-action кнопки для быстрых AI-команд.
 */
const QUICK_ACTIONS = [
    { label: 'Напиши описание', icon: <LuPenLine size={14} />, prompt: 'Напиши подробное, продающее описание для данного раздела. Используй подзаголовки, списки и акценты.' },
    { label: 'Перепиши', icon: <LuWand size={14} />, prompt: 'Перепиши текст, улучши читаемость и стиль, сохрани смысл.' },
    { label: 'Добавь структуру', icon: <LuListPlus size={14} />, prompt: 'Добавь чёткую структуру: подзаголовки (h2, h3), списки (ul/ol), выделение ключевых моментов.' },
    { label: 'Сократи', icon: <LuScissors size={14} />, prompt: 'Сократи текст на 30-40%, оставь только ключевую информацию.' },
];

/**
 * AI Chat Panel — чат-интерфейс внизу редактора.
 *
 * @param {{ editor: object, context: string, onContentChange: function }} props
 */
export const AiChatPanel = ({ editor, context = '', onContentChange }) => {
    const [messages, setMessages] = useState([]);
    const [inputValue, setInputValue] = useState('');
    const [loading, setLoading] = useState(false);
    const [pendingContent, setPendingContent] = useState(null); // Контент ожидающий Accept
    const [previousContent, setPreviousContent] = useState(null); // Snapshot для Reject
    const messagesEndRef = useRef(null);
    const inputRef = useRef(null);

    // Auto-scroll to bottom
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    const sendMessage = async (text) => {
        if (!text?.trim() || loading) return;

        const userMessage = { role: 'user', content: text.trim() };
        setMessages((prev) => [...prev, userMessage]);
        setInputValue('');
        setLoading(true);

        try {
            const currentHtml = editor?.getHTML() || '';
            const isEdit = currentHtml && currentHtml !== '<p></p>';

            // Сохраняем snapshot перед изменением
            setPreviousContent(currentHtml);

            const response = await axios.post(route('admin.ai.generate'), {
                prompt: text.trim(),
                context,
                current_content: isEdit ? currentHtml : '',
                mode: isEdit ? 'edit' : 'generation',
            });

            const aiContent = response.data.content;

            // Применяем контент в редактор
            if (editor) {
                const cleanHtml = aiContent
                    .replace(/^```html\s*/i, '')
                    .replace(/\s*```$/i, '')
                    .replace(/^<html>\s*/i, '')
                    .replace(/\s*<\/html>$/i, '')
                    .trim();

                setPendingContent(cleanHtml);
                editor.commands.setContent(cleanHtml, false);
                onContentChange?.(cleanHtml);
            }

            const aiMessage = {
                role: 'assistant',
                content: isEdit
                    ? 'Готово! Изменения внесены. Проверьте результат и нажмите «Принять» или «Отменить».'
                    : 'Готово! Контент сгенерирован. Проверьте результат и нажмите «Принять» или «Отменить».',
            };
            setMessages((prev) => [...prev, aiMessage]);

        } catch (error) {
            console.error('AI error:', error);
            const errMessage = {
                role: 'assistant',
                content: `Ошибка: ${error.response?.data?.message || 'Что-то пошло не так. Попробуйте ещё раз.'}`,
                isError: true,
            };
            setMessages((prev) => [...prev, errMessage]);
            toaster.create({
                title: 'Ошибка AI',
                description: error.response?.data?.message || 'Что-то пошло не так',
                type: 'error',
            });
        } finally {
            setLoading(false);
        }
    };

    const handleAccept = () => {
        setPendingContent(null);
        setPreviousContent(null);
        setMessages((prev) => [...prev, { role: 'system', content: '✓ Изменения приняты' }]);
    };

    const handleReject = () => {
        if (previousContent !== null && editor) {
            editor.commands.setContent(previousContent, false);
            onContentChange?.(previousContent);
        }
        setPendingContent(null);
        setPreviousContent(null);
        setMessages((prev) => [...prev, { role: 'system', content: '✕ Изменения отменены' }]);
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(inputValue);
        }
    };

    return (
        <Box borderTopWidth="1px" borderColor="border" bg="bg.subtle">
            {/* Accept / Reject bar */}
            {pendingContent !== null && (
                <HStack
                    justify="center"
                    py="2"
                    px="3"
                    bg="green.50"
                    _dark={{ bg: 'green.950/30' }}
                    borderBottomWidth="1px"
                    borderColor="green.200"
                    gap="3"
                >
                    <Text fontSize="sm" color="green.700" _dark={{ color: 'green.300' }}>
                        AI внёс изменения в документ
                    </Text>
                    <Button
                        size="xs"
                        colorPalette="green"
                        onClick={handleAccept}
                    >
                        ✓ Принять
                    </Button>
                    <Button
                        size="xs"
                        variant="outline"
                        colorPalette="red"
                        onClick={handleReject}
                    >
                        ✕ Отменить
                    </Button>
                </HStack>
            )}

            {/* Quick actions */}
            {messages.length === 0 && (
                <Box px="3" pt="3" pb="1">
                    <Text fontSize="2xs" color="fg.muted" fontWeight="bold" textTransform="uppercase" letterSpacing="wider" mb="2">
                        Быстрые действия
                    </Text>
                    <Flex gap="2" flexWrap="wrap">
                        {QUICK_ACTIONS.map((action) => (
                            <Button
                                key={action.label}
                                size="xs"
                                variant="outline"
                                colorPalette="purple"
                                onClick={() => sendMessage(action.prompt)}
                                disabled={loading}
                            >
                                {action.icon}
                                {action.label}
                            </Button>
                        ))}
                    </Flex>
                </Box>
            )}

            {/* Chat messages */}
            {messages.length > 0 && (
                <Box
                    maxH="200px"
                    overflowY="auto"
                    px="3"
                    pt="2"
                >
                    <VStack gap="2" align="stretch">
                        {messages.map((msg, i) => (
                            <HStack
                                key={i}
                                align="start"
                                gap="2"
                                opacity={msg.role === 'system' ? 0.6 : 1}
                            >
                                {msg.role === 'user' && (
                                    <Box
                                        w="5" h="5" borderRadius="full"
                                        bg="purple.100" _dark={{ bg: 'purple.900/40' }}
                                        display="flex" alignItems="center" justifyContent="center"
                                        flexShrink={0} mt="0.5"
                                    >
                                        <LuUser size={12} />
                                    </Box>
                                )}
                                {msg.role === 'assistant' && (
                                    <Box
                                        w="5" h="5" borderRadius="full"
                                        bg={msg.isError ? 'red.100' : 'green.100'}
                                        _dark={{ bg: msg.isError ? 'red.900/40' : 'green.900/40' }}
                                        display="flex" alignItems="center" justifyContent="center"
                                        flexShrink={0} mt="0.5"
                                    >
                                        <LuBot size={12} />
                                    </Box>
                                )}
                                <Text
                                    fontSize="sm"
                                    color={msg.isError ? 'red.600' : msg.role === 'system' ? 'fg.muted' : 'fg'}
                                    fontStyle={msg.role === 'system' ? 'italic' : 'normal'}
                                    lineHeight="1.4"
                                >
                                    {msg.content}
                                </Text>
                            </HStack>
                        ))}
                        {loading && (
                            <HStack gap="2" color="purple.500">
                                <Box
                                    w="5" h="5" borderRadius="full"
                                    bg="purple.100" _dark={{ bg: 'purple.900/40' }}
                                    display="flex" alignItems="center" justifyContent="center"
                                    flexShrink={0}
                                >
                                    <LuBot size={12} />
                                </Box>
                                <HStack gap="1" fontSize="sm">
                                    <LuLoader className="animate-spin" size={14} />
                                    <Text>AI думает...</Text>
                                </HStack>
                            </HStack>
                        )}
                        <div ref={messagesEndRef} />
                    </VStack>
                </Box>
            )}

            {/* Input area */}
            <HStack px="3" py="2.5" gap="2">
                <LuSparkles size={16} style={{ flexShrink: 0, color: 'var(--chakra-colors-purple-500)' }} />
                <Input
                    ref={inputRef}
                    size="sm"
                    placeholder="Опишите что нужно сделать с контентом..."
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                    onKeyDown={handleKeyDown}
                    disabled={loading}
                    variant="flushed"
                    _focus={{ borderColor: 'purple.400' }}
                />
                <IconButton
                    size="sm"
                    colorPalette="purple"
                    variant="solid"
                    borderRadius="full"
                    onClick={() => sendMessage(inputValue)}
                    disabled={!inputValue.trim() || loading}
                    aria-label="Отправить"
                >
                    <LuSend size={14} />
                </IconButton>
            </HStack>
        </Box>
    );
};
