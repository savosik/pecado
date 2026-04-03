import React, { useEffect, useRef, useCallback, useState } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Image } from '@tiptap/extension-image';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import { Placeholder } from '@tiptap/extension-placeholder';
import { Highlight } from '@tiptap/extension-highlight';
import { Underline } from '@tiptap/extension-underline';
import { Link } from '@tiptap/extension-link';
import { TextAlign } from '@tiptap/extension-text-align';
import { Subscript } from '@tiptap/extension-subscript';
import { Superscript } from '@tiptap/extension-superscript';
import { TaskList } from '@tiptap/extension-task-list';
import { TaskItem } from '@tiptap/extension-task-item';
import { Color } from '@tiptap/extension-color';
import { TextStyle } from '@tiptap/extension-text-style';

import { Box, HStack, IconButton, Collapsible, Button } from '@chakra-ui/react';
import {
    LuBold, LuItalic, LuUnderline as LuUnderlineIcon, LuStrikethrough,
    LuLink, LuCode, LuHeading2, LuHeading3, LuList, LuListOrdered,
    LuQuote, LuImage, LuTable, LuMinus, LuEllipsis,
    LuAlignLeft, LuAlignCenter, LuHighlighter, LuListChecks,
} from 'react-icons/lu';

import { SlashMenu } from './SlashMenu';
import { AiChatPanel } from './AiChatPanel';

/**
 * Agent Editor — Tiptap + AI Chat Panel.
 *
 * Компактный тулбар + чат-панель внизу для AI-инструкций.
 * Тот же API: value, onChange, placeholder, context.
 */
export const MarkdownEditor = ({
    value,
    onChange,
    placeholder = 'Начните писать или введите / для вставки блока...',
    minHeight = '300px',
    context = '',
}) => {
    const isInternalChange = useRef(false);
    const [showSlash, setShowSlash] = useState(false);
    const [slashQuery, setSlashQuery] = useState('');
    const [slashPos, setSlashPos] = useState(null);
    const slashMenuRef = useRef(null);
    const containerRef = useRef(null);
    const [toolbarExpanded, setToolbarExpanded] = useState(false);

    // Selection toolbar state
    const [selectionToolbar, setSelectionToolbar] = useState({ show: false, top: 0, left: 0 });

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3, 4, 5, 6] },
            }),
            Image.configure({
                inline: false,
                allowBase64: true,
                HTMLAttributes: { style: 'max-width:100%;border-radius:12px;margin:1em 0' },
            }),
            Table.configure({ resizable: true }),
            TableRow,
            TableCell,
            TableHeader,
            Placeholder.configure({ placeholder }),
            Highlight.configure({ multicolor: true }),
            Underline,
            Link.configure({
                openOnClick: false,
                HTMLAttributes: { rel: 'noopener noreferrer' },
            }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Subscript,
            Superscript,
            TaskList,
            TaskItem.configure({ nested: true }),
            Color,
            TextStyle,
        ],
        content: value || '',
        immediatelyRender: false,

        onUpdate: ({ editor }) => {
            isInternalChange.current = true;
            const html = editor.getHTML();
            onChange(html === '<p></p>' ? '' : html);
        },

        onSelectionUpdate: ({ editor }) => {
            const { from, to } = editor.state.selection;
            if (from === to) {
                setSelectionToolbar((prev) => ({ ...prev, show: false }));
                return;
            }

            const { view } = editor;
            const start = view.coordsAtPos(from);
            const end = view.coordsAtPos(to);
            const containerRect = containerRef.current?.getBoundingClientRect();

            if (containerRect) {
                setSelectionToolbar({
                    show: true,
                    top: start.top - containerRect.top - 44,
                    left: (start.left + end.left) / 2 - containerRect.left,
                });
            }
        },

        editorProps: {
            handleKeyDown: (view, event) => {
                if (event.key === '/' && !showSlash) {
                    const { $from } = view.state.selection;
                    const isEmptyLine = $from.parent.content.size === 0;
                    if (isEmptyLine) {
                        setTimeout(() => {
                            const containerRect = containerRef.current?.getBoundingClientRect();
                            const coords = view.coordsAtPos($from.pos);
                            if (containerRect) {
                                setSlashPos({
                                    top: coords.bottom - containerRect.top + 4,
                                    left: coords.left - containerRect.left,
                                });
                            }
                            setShowSlash(true);
                            setSlashQuery('');
                        }, 0);
                    }
                }

                if (showSlash) {
                    if (event.key === 'Escape') { setShowSlash(false); return true; }
                    if (event.key === 'Backspace' && slashQuery === '') { setShowSlash(false); return false; }
                    if (['ArrowUp', 'ArrowDown', 'Enter'].includes(event.key)) {
                        return slashMenuRef.current?.onKeyDown({ event }) ?? false;
                    }
                }
                return false;
            },
            handleTextInput: (view, from, to, text) => {
                if (showSlash) setSlashQuery((prev) => prev + text);
                return false;
            },
        },
    });

    // Sync external value
    useEffect(() => {
        if (!editor) return;
        if (isInternalChange.current) { isInternalChange.current = false; return; }
        const currentHtml = editor.getHTML();
        const isEmpty = currentHtml === '<p></p>';
        if ((isEmpty && value && value !== '') || (!isEmpty && currentHtml !== value)) {
            editor.commands.setContent(value || '', false);
        }
    }, [value, editor]);

    // Close slash menu on click outside
    useEffect(() => {
        if (!showSlash) return;
        const handleClick = () => setShowSlash(false);
        document.addEventListener('click', handleClick);
        return () => document.removeEventListener('click', handleClick);
    }, [showSlash]);

    const handleSlashClose = useCallback(() => {
        setShowSlash(false);
        setSlashQuery('');
        if (editor) {
            const { $from } = editor.state.selection;
            const textBefore = $from.parent.textContent;
            if (textBefore.startsWith('/')) {
                const start = $from.start();
                editor.chain().focus().deleteRange({ from: start, to: start + textBefore.length }).run();
            }
        }
    }, [editor]);

    const handleAiContentChange = useCallback((html) => {
        isInternalChange.current = true;
        onChange(html === '<p></p>' ? '' : html);
    }, [onChange]);

    // Compact toolbar button
    const TB = ({ icon, label, isActive, onClick, disabled }) => (
        <IconButton
            size="2xs"
            variant={isActive ? 'solid' : 'ghost'}
            colorPalette={isActive ? 'purple' : 'gray'}
            title={label}
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            _hover={{ bg: isActive ? undefined : 'gray.100' }}
        >
            {icon}
        </IconButton>
    );

    return (
        <Box
            ref={containerRef}
            borderWidth="1px"
            borderColor="border"
            borderRadius="md"
            overflow="hidden"
            bg="bg.panel"
            w="full"
            position="relative"
            css={{
                '& .tiptap': {
                    minHeight: minHeight,
                    maxHeight: '500px',
                    overflowY: 'auto',
                    padding: '12px 16px',
                    outline: 'none',
                    fontSize: '1rem',
                    lineHeight: '1.7',
                    fontFamily: 'inherit',
                },
                '& .tiptap p.is-editor-empty:first-child::before': {
                    content: 'attr(data-placeholder)',
                    float: 'left',
                    color: 'var(--chakra-colors-fg-muted)',
                    opacity: 0.5,
                    pointerEvents: 'none',
                    height: 0,
                },
                '& .tiptap h1': { fontSize: '2em', fontWeight: 700, letterSpacing: '-0.02em', marginTop: '0.5em', marginBottom: '0.5em' },
                '& .tiptap h2': { fontSize: '1.5em', fontWeight: 600, letterSpacing: '-0.02em', marginTop: '1.2em', marginBottom: '0.5em' },
                '& .tiptap h3': { fontSize: '1.25em', fontWeight: 600, marginTop: '1em', marginBottom: '0.4em' },
                '& .tiptap h4': { fontSize: '1.125em', fontWeight: 600, marginTop: '0.8em', marginBottom: '0.3em' },
                '& .tiptap blockquote': {
                    borderLeft: '4px solid var(--chakra-colors-purple-400)',
                    paddingLeft: '1em', marginLeft: 0,
                    color: 'var(--chakra-colors-fg-muted)', fontStyle: 'italic',
                },
                '& .tiptap pre': {
                    background: '#1e1e2e', color: '#cdd6f4', borderRadius: '8px',
                    padding: '12px 16px', fontSize: '0.875em', fontFamily: 'monospace', overflowX: 'auto',
                },
                '& .tiptap code': { background: 'var(--chakra-colors-bg-muted)', borderRadius: '4px', padding: '2px 6px', fontSize: '0.9em', fontFamily: 'monospace' },
                '& .tiptap pre code': { background: 'transparent', padding: 0 },
                '& .tiptap mark': { background: '#fef08a', borderRadius: '2px', padding: '0 2px' },
                '& .tiptap ul': { paddingLeft: '1.5em', listStyleType: 'disc' },
                '& .tiptap ol': { paddingLeft: '1.5em', listStyleType: 'decimal' },
                '& .tiptap li': { marginBottom: '0.25em' },
                '& .tiptap ul[data-type="taskList"]': { listStyleType: 'none', paddingLeft: '0.5em' },
                '& .tiptap ul[data-type="taskList"] li': { display: 'flex', alignItems: 'flex-start', gap: '8px' },
                '& .tiptap hr': { border: 'none', borderTop: '2px solid var(--chakra-colors-border)', margin: '1.5em 0' },
                '& .tiptap img': { maxWidth: '100%', borderRadius: '12px', margin: '1em 0' },
                '& .tiptap table': { borderCollapse: 'collapse', width: '100%', margin: '1em 0' },
                '& .tiptap th, & .tiptap td': { border: '1px solid var(--chakra-colors-border)', padding: '8px 12px', textAlign: 'left' },
                '& .tiptap th': { background: 'var(--chakra-colors-bg-muted)', fontWeight: 600 },
                '& .tiptap a': { color: 'var(--chakra-colors-purple-500)', textDecoration: 'underline', cursor: 'pointer' },
            }}
        >
            {/* Compact toolbar */}
            <Box bg="bg.muted" borderBottomWidth="1px" borderColor="border" px="2" py="1">
                <HStack gap="0.5" flexWrap="wrap">
                    {editor && (
                        <>
                            <TB icon={<LuBold />} label="Жирный" isActive={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()} />
                            <TB icon={<LuItalic />} label="Курсив" isActive={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()} />
                            <TB icon={<LuHeading2 />} label="Заголовок 2" isActive={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} />
                            <TB icon={<LuHeading3 />} label="Заголовок 3" isActive={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()} />
                            <TB icon={<LuList />} label="Список" isActive={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()} />
                            <TB icon={<LuLink />} label="Ссылка" isActive={editor.isActive('link')} onClick={() => {
                                const url = window.prompt('URL:', editor.getAttributes('link').href);
                                if (url === null) return;
                                if (url === '') { editor.chain().focus().extendMarkRange('link').unsetLink().run(); }
                                else { editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run(); }
                            }} />

                            {/* Expand button */}
                            <Button
                                size="2xs"
                                variant="ghost"
                                onClick={() => setToolbarExpanded(!toolbarExpanded)}
                                title={toolbarExpanded ? 'Свернуть' : 'Ещё инструменты'}
                                px="1"
                            >
                                <LuEllipsis />
                            </Button>

                            {/* Expanded toolbar */}
                            {toolbarExpanded && (
                                <>
                                    <TB icon={<LuUnderlineIcon />} label="Подчёркнутый" isActive={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()} />
                                    <TB icon={<LuStrikethrough />} label="Зачёркнутый" isActive={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()} />
                                    <TB icon={<LuHighlighter />} label="Маркер" isActive={editor.isActive('highlight')} onClick={() => editor.chain().focus().toggleHighlight().run()} />
                                    <TB icon={<LuAlignLeft />} label="По левому" isActive={editor.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()} />
                                    <TB icon={<LuAlignCenter />} label="По центру" isActive={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()} />
                                    <TB icon={<LuListOrdered />} label="Нумерованный" isActive={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()} />
                                    <TB icon={<LuListChecks />} label="Чеклист" isActive={editor.isActive('taskList')} onClick={() => editor.chain().focus().toggleTaskList().run()} />
                                    <TB icon={<LuQuote />} label="Цитата" isActive={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()} />
                                    <TB icon={<LuCode />} label="Код" isActive={editor.isActive('codeBlock')} onClick={() => editor.chain().focus().toggleCodeBlock().run()} />
                                    <TB icon={<LuMinus />} label="Разделитель" onClick={() => editor.chain().focus().setHorizontalRule().run()} />
                                    <TB icon={<LuImage />} label="Изображение" onClick={() => {
                                        const url = window.prompt('URL изображения:');
                                        if (url) editor.chain().focus().setImage({ src: url }).run();
                                    }} />
                                    <TB icon={<LuTable />} label="Таблица" onClick={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()} />
                                </>
                            )}
                        </>
                    )}
                </HStack>
            </Box>

            {/* Selection toolbar */}
            {selectionToolbar.show && editor && (
                <Box
                    position="absolute"
                    top={`${selectionToolbar.top}px`}
                    left={`${selectionToolbar.left}px`}
                    transform="translateX(-50%)"
                    zIndex="popover"
                >
                    <HStack bg="gray.900" borderRadius="lg" px="1.5" py="1" gap="0.5" boxShadow="lg">
                        <IconButton size="2xs" variant={editor.isActive('bold') ? 'solid' : 'ghost'} colorPalette="whiteAlpha" onClick={() => editor.chain().focus().toggleBold().run()} aria-label="Жирный" color="white"><LuBold /></IconButton>
                        <IconButton size="2xs" variant={editor.isActive('italic') ? 'solid' : 'ghost'} colorPalette="whiteAlpha" onClick={() => editor.chain().focus().toggleItalic().run()} aria-label="Курсив" color="white"><LuItalic /></IconButton>
                        <IconButton size="2xs" variant={editor.isActive('underline') ? 'solid' : 'ghost'} colorPalette="whiteAlpha" onClick={() => editor.chain().focus().toggleUnderline().run()} aria-label="Подчёркнутый" color="white"><LuUnderlineIcon /></IconButton>
                        <IconButton size="2xs" variant={editor.isActive('strike') ? 'solid' : 'ghost'} colorPalette="whiteAlpha" onClick={() => editor.chain().focus().toggleStrike().run()} aria-label="Зачёркнутый" color="white"><LuStrikethrough /></IconButton>
                        <IconButton size="2xs" variant={editor.isActive('code') ? 'solid' : 'ghost'} colorPalette="whiteAlpha" onClick={() => editor.chain().focus().toggleCode().run()} aria-label="Код" color="white"><LuCode /></IconButton>
                        <IconButton
                            size="2xs"
                            variant={editor.isActive('link') ? 'solid' : 'ghost'}
                            colorPalette="whiteAlpha"
                            onClick={() => {
                                const url = window.prompt('URL:', editor.getAttributes('link').href);
                                if (url === null) return;
                                if (url === '') { editor.chain().focus().extendMarkRange('link').unsetLink().run(); }
                                else { editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run(); }
                            }}
                            aria-label="Ссылка"
                            color="white"
                        ><LuLink /></IconButton>
                    </HStack>
                </Box>
            )}

            {/* Editor content */}
            <EditorContent editor={editor} />

            {/* Slash menu */}
            {showSlash && editor && (
                <SlashMenu
                    ref={slashMenuRef}
                    editor={editor}
                    query={slashQuery}
                    onClose={handleSlashClose}
                    position={slashPos}
                />
            )}

            {/* AI Chat Panel */}
            <AiChatPanel
                editor={editor}
                context={context}
                onContentChange={handleAiContentChange}
            />
        </Box>
    );
};
