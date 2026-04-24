import { useMemo } from 'react';
import { Box } from '@chakra-ui/react';
import MDEditor, { commands } from '@uiw/react-md-editor';

/**
 * Текстовый markdown-редактор на базе @uiw/react-md-editor.
 * В отличие от MarkdownEditor (Tiptap → HTML), хранит сам markdown как есть.
 *
 * Используется для полей, где в базе лежит именно markdown (products.description и т.п.).
 */
export const MarkdownTextEditor = ({
    value,
    onChange,
    placeholder = 'Введите текст в формате Markdown...',
    minHeight = 300,
    preview = 'live',
}) => {
    const toolbar = useMemo(() => [
        commands.bold,
        commands.italic,
        commands.strikethrough,
        commands.divider,
        commands.title2,
        commands.title3,
        commands.divider,
        commands.unorderedListCommand,
        commands.orderedListCommand,
        commands.checkedListCommand,
        commands.divider,
        commands.link,
        commands.quote,
        commands.code,
        commands.codeBlock,
        commands.hr,
        commands.table,
        commands.divider,
        commands.help,
    ], []);

    return (
        <Box
            data-color-mode="light"
            w="full"
            borderWidth="1px"
            borderColor="border"
            borderRadius="md"
            overflow="hidden"
            css={{
                '& .w-md-editor': { boxShadow: 'none', borderRadius: 0 },
                '& .w-md-editor-text-input, & .w-md-editor-text': {
                    fontSize: '0.95rem',
                    lineHeight: 1.6,
                    fontFamily: 'inherit',
                },
            }}
        >
            <MDEditor
                value={value || ''}
                onChange={(val) => onChange(val ?? '')}
                textareaProps={{ placeholder }}
                height={minHeight}
                preview={preview}
                commands={toolbar}
                extraCommands={[commands.codeEdit, commands.codeLive, commands.codePreview, commands.fullscreen]}
                visibleDragbar={false}
            />
        </Box>
    );
};

export default MarkdownTextEditor;
