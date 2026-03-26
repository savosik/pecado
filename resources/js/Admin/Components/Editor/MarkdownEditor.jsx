import React, { useEffect, useRef, useCallback } from 'react';
import Quill from 'quill';
import { Box, HStack } from '@chakra-ui/react';
import { AiAssistant } from './AiAssistant';

// Quill CSS
import 'quill/dist/quill.snow.css';

const toolbarOptions = [
    ['bold', 'italic', 'underline', 'strike'],
    ['blockquote', 'code-block'],
    [{ 'header': 1 }, { 'header': 2 }],
    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
    [{ 'indent': '-1' }, { 'indent': '+1' }],
    [{ 'size': ['small', false, 'large', 'huge'] }],
    [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
    [{ 'color': [] }, { 'background': [] }],
    [{ 'align': [] }],
    ['link', 'image'],
    ['clean'],
];

export const MarkdownEditor = ({
    value,
    onChange,
    placeholder = 'Начните писать...',
    minHeight = '300px',
    context = '',
}) => {
    const editorRef = useRef(null);
    const quillRef = useRef(null);
    const isInitializing = useRef(false);

    // Initialize Quill
    useEffect(() => {
        if (editorRef.current && !quillRef.current && !isInitializing.current) {
            isInitializing.current = true;

            const quill = new Quill(editorRef.current, {
                theme: 'snow',
                placeholder,
                modules: {
                    toolbar: toolbarOptions,
                },
            });

            // Set initial content
            if (value) {
                quill.root.innerHTML = value;
            }

            // Handle text changes
            quill.on('text-change', () => {
                const html = quill.root.innerHTML;
                // Avoid calling onChange with empty paragraph (Quill's default)
                if (html === '<p><br></p>') {
                    onChange('');
                } else {
                    onChange(html);
                }
            });

            quillRef.current = quill;
        }

        return () => {
            // Cleanup
            if (quillRef.current) {
                quillRef.current = null;
            }
        };
    }, [placeholder]);

    // Sync external value changes (for form resets, etc.)
    useEffect(() => {
        if (quillRef.current && value !== undefined) {
            const currentHtml = quillRef.current.root.innerHTML;
            const isEmpty = currentHtml === '<p><br></p>';

            // Only update if content actually differs
            if ((isEmpty && value && value !== '') ||
                (!isEmpty && currentHtml !== value)) {
                // Preserve cursor position when possible
                const selection = quillRef.current.getSelection();
                quillRef.current.root.innerHTML = value || '';
                if (selection) {
                    // Try to restore selection, but with bounds checking
                    const length = quillRef.current.getLength();
                    const safeIndex = Math.min(selection.index, length - 1);
                    quillRef.current.setSelection(safeIndex, 0);
                }
            }
        }
    }, [value]);

    const handleAiGenerate = useCallback((text) => {
        if (quillRef.current) {
            const range = quillRef.current.getSelection(true);
            // Strip outer <html> tags if present
            let cleanHtml = text.replace(/^<html>\s*/i, '').replace(/\s*<\/html>$/i, '').trim();
            // Use clipboard to paste HTML properly so it renders, not shows as raw text
            quillRef.current.clipboard.dangerouslyPasteHTML(range.index, cleanHtml);
        }
    }, []);

    return (
        <Box
            borderWidth="1px"
            borderColor="border"
            borderRadius="md"
            overflow="hidden"
            bg="bg.panel"
            w="full"
            className="quill-editor-wrapper"
            css={{
                '& .ql-container': {
                    minHeight: minHeight,
                    maxHeight: '600px',
                    overflowY: 'auto',
                    fontSize: '1rem',
                    fontFamily: 'inherit',
                    borderLeft: 'none',
                    borderRight: 'none',
                    borderBottom: 'none',
                },
                '& .ql-editor': {
                    minHeight: minHeight,
                },
                '& .ql-toolbar': {
                    borderTop: 'none',
                    borderLeft: 'none',
                    borderRight: 'none',
                    background: 'var(--chakra-colors-bg-muted)',
                },
            }}
        >
            {/* AI Assistant in a separate row */}
            <HStack
                justify="flex-end"
                bg="bg.muted"
                borderBottomWidth="1px"
                borderColor="border"
                p={2}
            >
                <AiAssistant onGenerate={handleAiGenerate} context={context} />
            </HStack>

            {/* Quill Editor Container */}
            <div ref={editorRef} />
        </Box>
    );
};
