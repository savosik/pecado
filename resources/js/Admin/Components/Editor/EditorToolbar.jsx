import React from 'react';
import { Box, HStack, IconButton, Separator } from '@chakra-ui/react';
import {
    LuBold, LuItalic, LuUnderline, LuStrikethrough,
    LuHeading1, LuHeading2, LuHeading3,
    LuList, LuListOrdered, LuListChecks,
    LuAlignLeft, LuAlignCenter, LuAlignRight,
    LuQuote, LuCode, LuMinus, LuImage, LuLink, LuTable,
    LuHighlighter, LuUndo2, LuRedo2,
    LuSubscript, LuSuperscript,
} from 'react-icons/lu';

/**
 * Кнопка тулбара с подсказкой.
 */
const ToolbarButton = ({ icon, label, isActive, onClick, disabled }) => (
    <IconButton
        size="xs"
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

const ToolbarSep = () => (
    <Separator orientation="vertical" height="20px" mx="1" />
);

/**
 * Панель инструментов для Tiptap-редактора.
 *
 * @param {{ editor: import('@tiptap/react').Editor | null }} props
 */
export const EditorToolbar = ({ editor }) => {
    if (!editor) return null;

    const addImage = () => {
        const url = window.prompt('URL изображения:');
        if (url) {
            editor.chain().focus().setImage({ src: url }).run();
        }
    };

    const addLink = () => {
        const previousUrl = editor.getAttributes('link').href;
        const url = window.prompt('URL ссылки:', previousUrl);

        if (url === null) return; // cancelled
        if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    };

    const addTable = () => {
        editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
    };

    return (
        <Box
            bg="bg.muted"
            borderBottomWidth="1px"
            borderColor="border"
            px="2"
            py="1.5"
            overflowX="auto"
        >
            <HStack gap="0.5" flexWrap="wrap">
                {/* Undo/Redo */}
                <ToolbarButton icon={<LuUndo2 />} label="Отменить" onClick={() => editor.chain().focus().undo().run()} disabled={!editor.can().undo()} />
                <ToolbarButton icon={<LuRedo2 />} label="Повторить" onClick={() => editor.chain().focus().redo().run()} disabled={!editor.can().redo()} />

                <ToolbarSep />

                {/* Текст */}
                <ToolbarButton icon={<LuBold />} label="Жирный" isActive={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()} />
                <ToolbarButton icon={<LuItalic />} label="Курсив" isActive={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()} />
                <ToolbarButton icon={<LuUnderline />} label="Подчёркнутый" isActive={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()} />
                <ToolbarButton icon={<LuStrikethrough />} label="Зачёркнутый" isActive={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()} />
                <ToolbarButton icon={<LuHighlighter />} label="Маркер" isActive={editor.isActive('highlight')} onClick={() => editor.chain().focus().toggleHighlight().run()} />
                <ToolbarButton icon={<LuSubscript />} label="Подстрочный" isActive={editor.isActive('subscript')} onClick={() => editor.chain().focus().toggleSubscript().run()} />
                <ToolbarButton icon={<LuSuperscript />} label="Надстрочный" isActive={editor.isActive('superscript')} onClick={() => editor.chain().focus().toggleSuperscript().run()} />

                <ToolbarSep />

                {/* Заголовки */}
                <ToolbarButton icon={<LuHeading1 />} label="Заголовок 1" isActive={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()} />
                <ToolbarButton icon={<LuHeading2 />} label="Заголовок 2" isActive={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} />
                <ToolbarButton icon={<LuHeading3 />} label="Заголовок 3" isActive={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()} />

                <ToolbarSep />

                {/* Выравнивание */}
                <ToolbarButton icon={<LuAlignLeft />} label="По левому краю" isActive={editor.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()} />
                <ToolbarButton icon={<LuAlignCenter />} label="По центру" isActive={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()} />
                <ToolbarButton icon={<LuAlignRight />} label="По правому краю" isActive={editor.isActive({ textAlign: 'right' })} onClick={() => editor.chain().focus().setTextAlign('right').run()} />

                <ToolbarSep />

                {/* Списки */}
                <ToolbarButton icon={<LuList />} label="Маркированный список" isActive={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()} />
                <ToolbarButton icon={<LuListOrdered />} label="Нумерованный список" isActive={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()} />
                <ToolbarButton icon={<LuListChecks />} label="Чеклист" isActive={editor.isActive('taskList')} onClick={() => editor.chain().focus().toggleTaskList().run()} />

                <ToolbarSep />

                {/* Блоки */}
                <ToolbarButton icon={<LuQuote />} label="Цитата" isActive={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()} />
                <ToolbarButton icon={<LuCode />} label="Блок кода" isActive={editor.isActive('codeBlock')} onClick={() => editor.chain().focus().toggleCodeBlock().run()} />
                <ToolbarButton icon={<LuMinus />} label="Разделитель" onClick={() => editor.chain().focus().setHorizontalRule().run()} />

                <ToolbarSep />

                {/* Медиа и вставки */}
                <ToolbarButton icon={<LuImage />} label="Изображение" onClick={addImage} />
                <ToolbarButton icon={<LuLink />} label="Ссылка" isActive={editor.isActive('link')} onClick={addLink} />
                <ToolbarButton icon={<LuTable />} label="Таблица" onClick={addTable} />
            </HStack>
        </Box>
    );
};
