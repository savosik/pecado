import React, { useState, useRef, useEffect } from 'react';
import { Box, HStack, VStack, Text, Input, IconButton, Button, Flex } from '@chakra-ui/react';
import { LuSend, LuSparkles, LuUser, LuBot, LuLoader, LuPenLine, LuListPlus, LuScissors, LuWand, LuMousePointerClick } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

/**
 * Quick-action кнопки для быстрых AI-команд.
 */
const QUICK_ACTIONS = [
    { label: 'Напиши описание', icon: <LuPenLine size={14} />, prompt: 'Напиши подробное, продающее описание для данного раздела. Используй подзаголовки h2/h3, списки, выделения. Минимум 5 абзацев.' },
    { label: 'Перепиши', icon: <LuWand size={14} />, prompt: 'Перепиши текст, улучши читаемость и стиль, сохрани смысл и длину.' },
    { label: 'Добавь структуру', icon: <LuListPlus size={14} />, prompt: 'Добавь чёткую структуру: подзаголовки (h2, h3), списки (ul/ol), выделение ключевых моментов жирным. Не обрезай текст.' },
    { label: 'Сократи', icon: <LuScissors size={14} />, prompt: 'Сократи текст на 30-40%, оставь только ключевую информацию. Сохрани форматирование.' },
];

/**
 * AI Chat Panel — чат-интерфейс внизу редактора.
 */
export const AiChatPanel = ({ editor, context = '', onContentChange }) => {
    const [messages, setMessages] = useState([]);
    const [inputValue, setInputValue] = useState('');
    const [loading, setLoading] = useState(false);
    const [pendingContent, setPendingContent] = useState(null);
    const [previousContent, setPreviousContent] = useState(null);
    const [selectionInfo, setSelectionInfo] = useState(null); // { from, to, text }
    const messagesEndRef = useRef(null);
    const inputRef = useRef(null);

    // Auto-scroll to bottom
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    // Track selection changes
    useEffect(() => {
        if (!editor) return;

        const handleSelectionUpdate = () => {
            const { from, to } = editor.state.selection;
            if (from !== to) {
                const selectedText = editor.state.doc.textBetween(from, to, ' ');
                // Get HTML of selection
                const slice = editor.state.selection.content();
                const div = document.createElement('div');
                const fragment = slice.content;
                const serializer = editor.view.dom.ownerDocument.createElement('div');

                // Use editor's view to serialize the fragment
                const tempEditor = document.createElement('div');
                const tempView = editor.view;
                const selectedHtml = getSelectedHtml(editor, from, to);

                setSelectionInfo({ from, to, text: selectedText, html: selectedHtml });
            } else {
                setSelectionInfo(null);
            }
        };

        editor.on('selectionUpdate', handleSelectionUpdate);
        return () => editor.off('selectionUpdate', handleSelectionUpdate);
    }, [editor]);

    /**
     * Получить HTML из выделенного диапазона
     */
    function getSelectedHtml(editor, from, to) {
        try {
            const { state } = editor;
            const slice = state.doc.slice(from, to);
            const serializer = window.DOMSerializer || null;

            // Простой способ — используем текстовый контент + пробуем HTML
            const tempDiv = document.createElement('div');
            const fragment = slice.content;

            // Serialize fragment to HTML using ProseMirror
            const domSerializer = editor.view.domSerializer || editor.schema;
            if (domSerializer && domSerializer.serializeFragment) {
                const dom = domSerializer.serializeFragment(fragment);
                tempDiv.appendChild(dom);
                return tempDiv.innerHTML;
            }

            // Fallback: берём текстовый контент
            return editor.state.doc.textBetween(from, to, '\n');
        } catch (e) {
            return editor.state.doc.textBetween(from, to, '\n');
        }
    }

    const sendMessage = async (text) => {
        if (!text?.trim() || loading) return;

        const isSelectionMode = selectionInfo && selectionInfo.from !== selectionInfo.to;
        const currentHtml = editor?.getHTML() || '';
        const hasContent = currentHtml && currentHtml !== '<p></p>';

        const displayText = isSelectionMode
            ? `[выделено: "${selectionInfo.text.slice(0, 50)}${selectionInfo.text.length > 50 ? '...' : ''}"] ${text.trim()}`
            : text.trim();

        const userMessage = { role: 'user', content: displayText };
        setMessages((prev) => [...prev, userMessage]);
        setInputValue('');
        setLoading(true);

        try {
            // Сохраняем snapshot
            setPreviousContent(currentHtml);

            let mode, requestData;

            if (isSelectionMode) {
                // Режим: только выделенный текст
                mode = 'edit_selection';
                requestData = {
                    prompt: text.trim(),
                    context,
                    selected_text: selectionInfo.html || selectionInfo.text,
                    current_content: currentHtml,
                    mode: 'edit_selection',
                };
            } else if (hasContent) {
                // Режим: редактирование всего документа
                mode = 'edit';
                requestData = {
                    prompt: text.trim(),
                    context,
                    current_content: currentHtml,
                    mode: 'edit',
                };
            } else {
                // Режим: генерация с нуля
                mode = 'generation';
                requestData = {
                    prompt: text.trim(),
                    context,
                    mode: 'generation',
                };
            }

            const response = await axios.post(route('admin.ai.generate'), requestData);
            let aiContent = response.data.content;

            // Чистим ответ от markdown-обёрток
            aiContent = aiContent
                .replace(/^```html\s*/i, '')
                .replace(/\s*```$/i, '')
                .replace(/^```\s*/i, '')
                .replace(/^<html>\s*/i, '')
                .replace(/\s*<\/html>$/i, '')
                .trim();

            if (editor) {
                if (isSelectionMode) {
                    // Заменяем только выделенный фрагмент
                    editor.chain()
                        .focus()
                        .deleteRange({ from: selectionInfo.from, to: selectionInfo.to })
                        .insertContentAt(selectionInfo.from, aiContent, { parseOptions: { preserveWhitespace: false } })
                        .run();

                    const updatedHtml = editor.getHTML();
                    setPendingContent(updatedHtml);
                    onContentChange?.(updatedHtml);
                } else {
                    // Заменяем весь контент
                    setPendingContent(aiContent);
                    editor.commands.setContent(aiContent, false);
                    onContentChange?.(aiContent);
                }
            }

            const aiMessage = {
                role: 'assistant',
                content: isSelectionMode
                    ? 'Выделенный фрагмент изменён. Проверьте и нажмите «Принять» или «Отменить».'
                    : mode === 'edit'
                        ? 'Документ обновлён. Проверьте и нажмите «Принять» или «Отменить».'
                        : 'Контент сгенерирован! Проверьте и нажмите «Принять» или «Отменить».',
            };
            setMessages((prev) => [...prev, aiMessage]);
            setSelectionInfo(null);

        } catch (error) {
            console.error('AI error:', error);
            const errMessage = {
                role: 'assistant',
                content: `Ошибка: ${error.response?.data?.message || 'Что-то пошло не так. Попробуйте ещё раз.'}`,
                isError: true,
            };
            setMessages((prev) => [...prev, errMessage]);
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
                    <Button size="xs" colorPalette="green" onClick={handleAccept}>
                        ✓ Принять
                    </Button>
                    <Button size="xs" variant="outline" colorPalette="red" onClick={handleReject}>
                        ✕ Отменить
                    </Button>
                </HStack>
            )}

            {/* Selection indicator */}
            {selectionInfo && !loading && (
                <HStack px="3" py="1.5" bg="purple.50" _dark={{ bg: 'purple.950/20' }} borderBottomWidth="1px" borderColor="purple.200" gap="2">
                    <LuMousePointerClick size={14} style={{ color: 'var(--chakra-colors-purple-500)', flexShrink: 0 }} />
                    <Text fontSize="xs" color="purple.700" _dark={{ color: 'purple.300' }} truncate>
                        Выделено: «{selectionInfo.text.slice(0, 80)}{selectionInfo.text.length > 80 ? '...' : ''}» — AI изменит только этот фрагмент
                    </Text>
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
                <Box maxH="200px" overflowY="auto" px="3" pt="2">
                    <VStack gap="2" align="stretch">
                        {messages.map((msg, i) => (
                            <HStack key={i} align="start" gap="2" opacity={msg.role === 'system' ? 0.6 : 1}>
                                {msg.role === 'user' && (
                                    <Box w="5" h="5" borderRadius="full" bg="purple.100" _dark={{ bg: 'purple.900/40' }} display="flex" alignItems="center" justifyContent="center" flexShrink={0} mt="0.5">
                                        <LuUser size={12} />
                                    </Box>
                                )}
                                {msg.role === 'assistant' && (
                                    <Box w="5" h="5" borderRadius="full" bg={msg.isError ? 'red.100' : 'green.100'} _dark={{ bg: msg.isError ? 'red.900/40' : 'green.900/40' }} display="flex" alignItems="center" justifyContent="center" flexShrink={0} mt="0.5">
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
                                <Box w="5" h="5" borderRadius="full" bg="purple.100" _dark={{ bg: 'purple.900/40' }} display="flex" alignItems="center" justifyContent="center" flexShrink={0}>
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
                    placeholder={selectionInfo ? 'Что сделать с выделенным текстом...' : 'Опишите что нужно сделать с контентом...'}
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
