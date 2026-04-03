import React, { useState, useCallback } from 'react';
import { BubbleMenu } from '@tiptap/react/menus';
import { HStack, IconButton, Box, Text } from '@chakra-ui/react';
import { LuWand, LuScissors, LuPlus, LuLanguages, LuPalette, LuLoader } from 'react-icons/lu';
import axios from 'axios';

/**
 * AI BubbleMenu — плавающая панель с AI-кнопками при выделении текста.
 */
const AI_ACTIONS = [
    { id: 'rewrite', label: 'Перепиши', icon: <LuWand size={14} />, prompt: 'Перепиши этот текст, улучши стиль и читаемость. Сохрани смысл и примерную длину.' },
    { id: 'shorten', label: 'Сократи', icon: <LuScissors size={14} />, prompt: 'Сократи этот текст на 30-40%, оставь только ключевую информацию.' },
    { id: 'expand', label: 'Дополни', icon: <LuPlus size={14} />, prompt: 'Расширь и дополни этот текст, добавь подробности и примеры. Увеличь объём в 1.5-2 раза.' },
    { id: 'translate', label: 'Переведи', icon: <LuLanguages size={14} />, prompt: 'Если текст на русском — переведи на английский. Если на английском — переведи на русский. Сохрани форматирование.' },
];

export const AiBubbleMenu = ({ editor, context = '', onPendingChange }) => {
    const [loading, setLoading] = useState(false);
    const [activeAction, setActiveAction] = useState(null);

    const executeAction = useCallback(async (action) => {
        if (loading || !editor) return;

        const { from, to } = editor.state.selection;
        if (from === to) return;

        const selectedText = editor.state.doc.textBetween(from, to, '\n');
        if (!selectedText.trim()) return;

        setLoading(true);
        setActiveAction(action.id);

        // Сохраняем snapshot для rollback
        const previousContent = editor.getHTML();

        try {
            const response = await axios.post(route('admin.ai.generate'), {
                prompt: action.prompt,
                context,
                selected_text: selectedText,
                current_content: editor.getHTML(),
                mode: 'edit_selection',
            });

            let result = response.data.content
                .replace(/^```html\s*/i, '')
                .replace(/\s*```$/i, '')
                .replace(/^```\s*/i, '')
                .trim();

            // Заменяем выделенный фрагмент
            editor.chain()
                .focus()
                .deleteRange({ from, to })
                .insertContentAt(from, result, { parseOptions: { preserveWhitespace: false } })
                .run();

            // Сигнализируем AiChatPanel о pending изменении
            onPendingChange?.({
                previousContent,
                actionLabel: action.label,
            });

        } catch (error) {
            console.error('BubbleMenu AI error:', error);
        } finally {
            setLoading(false);
            setActiveAction(null);
        }
    }, [editor, context, loading, onPendingChange]);

    if (!editor) return null;

    return (
        <BubbleMenu
            editor={editor}
            tippyOptions={{
                duration: 150,
                placement: 'top',
                offset: [0, 12],
            }}
            shouldShow={({ editor, state }) => {
                const { from, to } = state.selection;
                // Показываем только при реальном выделении текста (>3 символов)
                return from !== to && (to - from) > 3 && !loading;
            }}
        >
            <HStack
                bg="gray.900"
                borderRadius="xl"
                px="1.5"
                py="1"
                gap="0.5"
                boxShadow="0 8px 32px rgba(0,0,0,0.3)"
                border="1px solid"
                borderColor="gray.700"
            >
                {/* Разделитель: AI Actions */}
                <Box
                    px="1.5"
                    py="0.5"
                    borderRadius="md"
                    bg="purple.500/20"
                >
                    <Text fontSize="2xs" fontWeight="bold" color="purple.300" letterSpacing="wider">
                        AI
                    </Text>
                </Box>

                {AI_ACTIONS.map((action) => (
                    <IconButton
                        key={action.id}
                        size="2xs"
                        variant="ghost"
                        color="white"
                        title={action.label}
                        aria-label={action.label}
                        onClick={() => executeAction(action)}
                        disabled={loading}
                        _hover={{ bg: 'whiteAlpha.200' }}
                    >
                        {loading && activeAction === action.id
                            ? <LuLoader size={14} className="animate-spin" />
                            : action.icon
                        }
                    </IconButton>
                ))}
            </HStack>
        </BubbleMenu>
    );
};
