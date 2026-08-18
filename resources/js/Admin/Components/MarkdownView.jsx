import MDEditor from '@uiw/react-md-editor';
import { Box } from '@chakra-ui/react';
import { useColorMode } from '@/components/ui/color-mode';

/**
 * Read-only отображение markdown-текста (постановки задач агентов,
 * сообщения диалогов и т.п.). Тема наследуется от режима панели.
 */
export const MarkdownView = ({ source, fontSize = '14px' }) => {
    const { colorMode } = useColorMode();

    return (
        <Box
            data-color-mode={colorMode}
            css={{
                '& .wmde-markdown': {
                    background: 'transparent',
                    fontSize,
                    fontFamily: 'inherit',
                },
                '& .wmde-markdown pre': { fontSize: '12px' },
            }}
        >
            <MDEditor.Markdown source={source || ''} />
        </Box>
    );
};
