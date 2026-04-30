import { useRef, useCallback, useState, useEffect } from 'react';
import { Box, Flex, IconButton } from '@chakra-ui/react';
import {
    LuBold, LuItalic, LuUnderline, LuStrikethrough,
    LuList, LuListOrdered, LuLink, LuUnlink,
    LuHeading1, LuHeading2, LuHeading3,
    LuAlignLeft, LuAlignCenter, LuAlignRight,
    LuRemoveFormatting, LuCode,
} from 'react-icons/lu';

/**
 * SimpleWysiwyg — простой WYSIWYG-редактор на contentEditable.
 *
 * Покрывает базовую типографику:
 * - жирный, курсив, подчёркивание, зачёркивание
 * - заголовки H2, H3, H4
 * - списки (маркированный/нумерованный)
 * - выравнивание текста
 * - ссылки
 * - очистка форматирования
 *
 * @param {{ value: string, onChange: (html: string) => void, placeholder?: string, minHeight?: string }} props
 */
export default function SimpleWysiwyg({ value, onChange, placeholder = 'Введите текст...', minHeight = '200px' }) {
    const editorRef = useRef(null);
    const [isEmpty, setIsEmpty] = useState(true);

    // Инициализация контента
    useEffect(() => {
        if (editorRef.current && value !== undefined) {
            // Устанавливаем только если реально отличается (избегаем потери курсора)
            const currentHtml = editorRef.current.innerHTML;
            if (currentHtml !== value) {
                editorRef.current.innerHTML = value || '';
            }
            setIsEmpty(!value || value.trim() === '' || value.trim() === '<br>');
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const handleInput = useCallback(() => {
        if (!editorRef.current) return;
        const html = editorRef.current.innerHTML;
        const clean = html === '<br>' ? '' : html;
        setIsEmpty(!clean || clean.trim() === '');
        onChange?.(clean);
    }, [onChange]);

    const exec = useCallback((command, value = null) => {
        editorRef.current?.focus();
        document.execCommand(command, false, value);
        handleInput();
    }, [handleInput]);

    const handleLink = useCallback(() => {
        const selection = window.getSelection();
        const selectedText = selection?.toString() || '';

        if (!selectedText) {
            return;
        }

        const url = prompt('Введите URL ссылки:', 'https://');
        if (url) {
            exec('createLink', url);
        }
    }, [exec]);

    const handleUnlink = useCallback(() => {
        exec('unlink');
    }, [exec]);

    const handleHeading = useCallback((tag) => {
        exec('formatBlock', tag);
    }, [exec]);

    const handlePaste = useCallback((e) => {
        e.preventDefault();
        // Вставляем только чистый текст с базовым форматированием
        const html = e.clipboardData.getData('text/html');
        const text = e.clipboardData.getData('text/plain');

        if (html) {
            // Очищаем опасные теги, оставляем базовые
            const temp = document.createElement('div');
            temp.innerHTML = html;

            // Удаляем скрипты, стили, мета-теги
            temp.querySelectorAll('script, style, meta, link').forEach(el => el.remove());

            // Убираем inline-стили кроме text-align
            temp.querySelectorAll('*').forEach(el => {
                const align = el.style.textAlign;
                el.removeAttribute('style');
                el.removeAttribute('class');
                el.removeAttribute('id');
                if (align) el.style.textAlign = align;
            });

            document.execCommand('insertHTML', false, temp.innerHTML);
        } else {
            document.execCommand('insertText', false, text);
        }
        handleInput();
    }, [handleInput]);

    const ToolButton = ({ icon: Icon, command, value, title, onClick }) => (
        <IconButton
            size="xs"
            variant="ghost"
            title={title}
            onClick={onClick || (() => value ? handleHeading(value) : exec(command))}
            color="gray.600"
            _hover={{ bg: 'gray.100', color: 'gray.900' }}
            _dark={{ color: 'gray.400', _hover: { bg: 'gray.700', color: 'gray.100' } }}
            minW="28px"
            h="28px"
        >
            <Icon size={14} />
        </IconButton>
    );

    return (
        <Box
            w="100%"
            borderWidth="1px"
            borderColor="border"

            borderRadius="md"
            overflow="hidden"
        >
            {/* Toolbar */}
            <Flex
                wrap="wrap"
                gap="0.5"
                px="2"
                py="1"
                bg="bg.subtle"
                _dark={{ bg: 'gray.800' }}
                borderBottomWidth="1px"
                borderColor="border"

                alignItems="center"
            >
                {/* Текст */}
                <ToolButton icon={LuBold} command="bold" title="Жирный (Ctrl+B)" />
                <ToolButton icon={LuItalic} command="italic" title="Курсив (Ctrl+I)" />
                <ToolButton icon={LuUnderline} command="underline" title="Подчёркивание (Ctrl+U)" />
                <ToolButton icon={LuStrikethrough} command="strikeThrough" title="Зачёркнутый" />

                <Box w="1px" h="20px" bg="gray.300" _dark={{ bg: 'gray.600' }} mx="1" />

                {/* Заголовки */}
                <ToolButton icon={LuHeading1} value="h2" title="Заголовок H2" />
                <ToolButton icon={LuHeading2} value="h3" title="Заголовок H3" />
                <ToolButton icon={LuHeading3} value="h4" title="Заголовок H4" />
                <ToolButton icon={LuCode} value="p" title="Обычный текст" />

                <Box w="1px" h="20px" bg="gray.300" _dark={{ bg: 'gray.600' }} mx="1" />

                {/* Списки */}
                <ToolButton icon={LuList} command="insertUnorderedList" title="Маркированный список" />
                <ToolButton icon={LuListOrdered} command="insertOrderedList" title="Нумерованный список" />

                <Box w="1px" h="20px" bg="gray.300" _dark={{ bg: 'gray.600' }} mx="1" />

                {/* Выравнивание */}
                <ToolButton icon={LuAlignLeft} command="justifyLeft" title="По левому краю" />
                <ToolButton icon={LuAlignCenter} command="justifyCenter" title="По центру" />
                <ToolButton icon={LuAlignRight} command="justifyRight" title="По правому краю" />

                <Box w="1px" h="20px" bg="gray.300" _dark={{ bg: 'gray.600' }} mx="1" />

                {/* Ссылки */}
                <ToolButton icon={LuLink} title="Вставить ссылку" onClick={handleLink} />
                <ToolButton icon={LuUnlink} title="Убрать ссылку" onClick={handleUnlink} />

                <Box w="1px" h="20px" bg="gray.300" _dark={{ bg: 'gray.600' }} mx="1" />

                {/* Очистка */}
                <ToolButton icon={LuRemoveFormatting} command="removeFormat" title="Очистить форматирование" />
            </Flex>

            {/* Editor area */}
            <Box position="relative">
                {isEmpty && (
                    <Box
                        position="absolute"
                        top="3"
                        left="3"
                        color="gray.400"
                        fontSize="sm"
                        pointerEvents="none"
                        userSelect="none"
                    >
                        {placeholder}
                    </Box>
                )}
                <Box
                    ref={editorRef}
                    contentEditable
                    onInput={handleInput}
                    onPaste={handlePaste}
                    minH={minHeight}
                    p="3"
                    fontSize="sm"
                    lineHeight="1.6"
                    outline="none"
                    css={{
                        '& h2': { fontSize: '1.25em', fontWeight: 700, margin: '0.75em 0 0.5em' },
                        '& h3': { fontSize: '1.1em', fontWeight: 600, margin: '0.75em 0 0.5em' },
                        '& h4': { fontSize: '1em', fontWeight: 600, margin: '0.5em 0 0.25em' },
                        '& p': { margin: '0.25em 0' },
                        '& ul, & ol': { paddingLeft: '1.5em', margin: '0.5em 0' },
                        '& li': { margin: '0.15em 0' },
                        '& a': { color: 'var(--chakra-colors-blue-500)', textDecoration: 'underline' },
                        '& blockquote': {
                            borderLeft: '3px solid var(--chakra-colors-gray-300)',
                            paddingLeft: '1em',
                            margin: '0.5em 0',
                            color: 'var(--chakra-colors-gray-600)',
                        },
                    }}
                />
            </Box>
        </Box>
    );
}
