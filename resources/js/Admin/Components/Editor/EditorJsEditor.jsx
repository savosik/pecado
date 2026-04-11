import { useEffect, useRef, useCallback, useState, memo } from 'react';
import { Box, Text } from '@chakra-ui/react';

/**
 * EditorJsEditor — компонент-обёртка для Editor.js.
 *
 * API идентичен MarkdownEditor:
 *   value     — JSON-строка или объект { blocks: [...] }
 *   onChange  — (jsonString) => void
 *
 * При инициализации принимает как JSON (новый контент), так и HTML (старый).
 * HTML-контент конвертируется в блок paragraph.
 */
function EditorJsEditor({ value, onChange, placeholder = 'Нажмите Tab или «/» для вставки блока...' }) {
    const editorRef = useRef(null);
    const containerRef = useRef(null);
    const isReady = useRef(false);
    const [error, setError] = useState(null);

    // Парсим входное значение
    const parseInitialData = useCallback((val) => {
        if (!val) return { blocks: [] };

        // Уже объект
        if (typeof val === 'object' && val !== null) {
            return Array.isArray(val.blocks) ? val : { blocks: [] };
        }

        // Строка — пытаемся JSON
        if (typeof val === 'string') {
            const trimmed = val.trim();
            if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
                try {
                    const parsed = JSON.parse(trimmed);
                    if (parsed && Array.isArray(parsed.blocks)) return parsed;
                } catch { /* не JSON */ }
            }

            // HTML-строка → оборачиваем в paragraph
            if (trimmed.length > 0) {
                return {
                    blocks: [{
                        type: 'paragraph',
                        data: { text: trimmed }
                    }]
                };
            }
        }

        return { blocks: [] };
    }, []);

    useEffect(() => {
        if (!containerRef.current || editorRef.current) return;

        let isMounted = true;

        const initEditor = async () => {
            try {
                // Динамический импорт — не грузим Editor.js пока не нужен
                const [
                    { default: EditorJS },
                    { default: Header },
                    { default: NestedList },
                    { default: ImageTool },
                    { default: Quote },
                    { default: Delimiter },
                    { default: Table },
                    { default: Warning },
                    { default: Embed },
                    { default: InlineCode },
                    { default: Marker },
                    // Кастомные инструменты
                    { default: ColumnsTool },
                    { default: ImageTextTool },
                    { default: CallToActionTool },
                    { default: GalleryTool },
                    { default: FaqTool },
                    { default: PullQuoteTool },
                    { default: OpinionBoxTool },
                    { default: ReviewsTool },
                    { default: CoverImageTool },
                    { default: PhotoMosaicTool },
                    { default: ProductCarouselTool },
                    { default: IconFeatureTool },
                    { default: CountdownTool },
                    { default: TabsTool },
                    { default: ImageCarouselTool },
                    { default: StatsTool },
                    { default: LogoWallTool },
                    { default: AlertBannerTool },
                    { default: SpacerTool },
                    { default: ButtonTool },
                    { default: StepsTool },
                    { default: TimelineTool },
                    { default: BeforeAfterTool },
                    { default: ComparisonTool },
                    { default: PricingTableTool },
                    { default: MapTool },
                ] = await Promise.all([
                    import('@editorjs/editorjs'),
                    import('@editorjs/header'),
                    import('@editorjs/nested-list'),
                    import('@editorjs/image'),
                    import('@editorjs/quote'),
                    import('@editorjs/delimiter'),
                    import('@editorjs/table'),
                    import('@editorjs/warning'),
                    import('@editorjs/embed'),
                    import('@editorjs/inline-code'),
                    import('@editorjs/marker'),
                    // Кастомные инструменты
                    import('./tools/ColumnsTool'),
                    import('./tools/ImageTextTool'),
                    import('./tools/CallToActionTool'),
                    import('./tools/GalleryTool'),
                    import('./tools/FaqTool'),
                    import('./tools/PullQuoteTool'),
                    import('./tools/OpinionBoxTool'),
                    import('./tools/ReviewsTool'),
                    import('./tools/CoverImageTool'),
                    import('./tools/PhotoMosaicTool'),
                    import('./tools/ProductCarouselTool'),
                    import('./tools/IconFeatureTool'),
                    import('./tools/CountdownTool'),
                    import('./tools/TabsTool'),
                    import('./tools/ImageCarouselTool'),
                    import('./tools/StatsTool'),
                    import('./tools/LogoWallTool'),
                    import('./tools/AlertBannerTool'),
                    import('./tools/SpacerTool'),
                    import('./tools/ButtonTool'),
                    import('./tools/StepsTool'),
                    import('./tools/TimelineTool'),
                    import('./tools/BeforeAfterTool'),
                    import('./tools/ComparisonTool'),
                    import('./tools/PricingTableTool'),
                    import('./tools/MapTool'),
                ]);

                if (!isMounted || !containerRef.current) return;

                const editor = new EditorJS({
                    holder: containerRef.current,
                    placeholder,
                    data: parseInitialData(value),

                    tools: {
                        header: {
                            class: Header,
                            config: {
                                levels: [2, 3, 4],
                                defaultLevel: 2,
                            },
                            inlineToolbar: true,
                        },
                        list: {
                            class: NestedList,
                            inlineToolbar: true,
                            config: {
                                defaultStyle: 'unordered',
                            },
                        },
                        image: {
                            class: ImageTool,
                            config: {
                                endpoints: {
                                    byFile: '/admin/api/upload-image',
                                    byUrl: '/admin/api/fetch-url',
                                },
                                additionalRequestHeaders: {
                                    'X-XSRF-TOKEN': getCsrfToken(),
                                },
                            },
                        },
                        quote: {
                            class: Quote,
                            inlineToolbar: true,
                            config: {
                                quotePlaceholder: 'Введите цитату',
                                captionPlaceholder: 'Автор',
                            },
                        },
                        delimiter: Delimiter,
                        table: {
                            class: Table,
                            inlineToolbar: true,
                            config: {
                                rows: 3,
                                cols: 3,
                                withHeadings: true,
                            },
                        },
                        warning: {
                            class: Warning,
                            inlineToolbar: true,
                            config: {
                                titlePlaceholder: 'Заголовок',
                                messagePlaceholder: 'Сообщение',
                            },
                        },
                        embed: {
                            class: Embed,
                            config: {
                                services: {
                                    youtube: true,
                                    vimeo: true,
                                },
                            },
                        },
                        inlineCode: { class: InlineCode },
                        marker: { class: Marker },

                        // ======= Кастомные инструменты =======
                        columns: { class: ColumnsTool },
                        imageText: { class: ImageTextTool },
                        callToAction: { class: CallToActionTool },
                        gallery: { class: GalleryTool },
                        faq: { class: FaqTool },
                        pullQuote: { class: PullQuoteTool },
                        opinionBox: { class: OpinionBoxTool },
                        reviews: { class: ReviewsTool },
                        coverImage: { class: CoverImageTool },
                        photoMosaic: { class: PhotoMosaicTool },
                        productCarousel: { class: ProductCarouselTool },
                        iconFeature: { class: IconFeatureTool },
                        countdown: { class: CountdownTool },
                        tabs: { class: TabsTool },
                        imageCarousel: { class: ImageCarouselTool },
                        stats: { class: StatsTool },
                        logoWall: { class: LogoWallTool },
                        alertBanner: { class: AlertBannerTool },
                        spacer: { class: SpacerTool },
                        button: { class: ButtonTool },
                        steps: { class: StepsTool },
                        timeline: { class: TimelineTool },
                        beforeAfter: { class: BeforeAfterTool },
                        comparison: { class: ComparisonTool },
                        pricingTable: { class: PricingTableTool },
                        map: { class: MapTool },
                    },

                    i18n: {
                        messages: {
                            ui: {
                                blockTunes: {
                                    toggler: { 'Click to tune': 'Настройки блока' },
                                },
                                inlineToolbar: {
                                    converter: { 'Convert to': 'Конвертировать в' },
                                },
                                toolbar: {
                                    toolbox: { Add: 'Добавить' },
                                },
                            },
                            toolNames: {
                                Text: 'Текст',
                                Heading: 'Заголовок',
                                List: 'Список',
                                Quote: 'Цитата',
                                Delimiter: 'Разделитель',
                                Table: 'Таблица',
                                Warning: 'Предупреждение',
                                Image: 'Изображение',
                                'Inline Code': 'Код',
                                Marker: 'Маркер',
                                Bold: 'Жирный',
                                Italic: 'Курсив',
                                Link: 'Ссылка',
                            },
                            tools: {
                                header: {
                                    'Heading 2': 'Заголовок 2',
                                    'Heading 3': 'Заголовок 3',
                                    'Heading 4': 'Заголовок 4',
                                },
                                list: {
                                    Unordered: 'Маркированный',
                                    Ordered: 'Нумерованный',
                                },
                                image: {
                                    Caption: 'Подпись',
                                    'Select an Image': 'Выберите изображение',
                                    'With border': 'С рамкой',
                                    'Stretch image': 'Растянуть',
                                    'With background': 'С фоном',
                                },
                                table: {
                                    'With headings': 'С заголовками',
                                    'Without headings': 'Без заголовков',
                                    'Add row above': 'Добавить строку выше',
                                    'Add row below': 'Добавить строку ниже',
                                    'Delete row': 'Удалить строку',
                                    'Add column to the left': 'Добавить столбец слева',
                                    'Add column to the right': 'Добавить столбец справа',
                                    'Delete column': 'Удалить столбец',
                                },
                                warning: {
                                    Title: 'Заголовок',
                                    Message: 'Сообщение',
                                },
                                quote: {
                                    'Align Left': 'По левому краю',
                                    'Align Center': 'По центру',
                                },
                            },
                            blockTunes: {
                                delete: { Delete: 'Удалить', 'Click to delete': 'Нажмите для удаления' },
                                moveUp: { 'Move up': 'Вверх' },
                                moveDown: { 'Move down': 'Вниз' },
                            },
                        },
                    },

                    onChange: async () => {
                        if (!editorRef.current || !isReady.current) return;
                        try {
                            const outputData = await editorRef.current.save();
                            const jsonString = JSON.stringify(outputData);
                            onChange?.(jsonString);
                        } catch (err) {
                            console.error('Editor.js save error:', err);
                        }
                    },

                    onReady: () => {
                        isReady.current = true;
                    },
                });

                editorRef.current = editor;
            } catch (err) {
                console.error('Failed to init Editor.js:', err);
                if (isMounted) setError(err.message);
            }
        };

        initEditor();

        return () => {
            isMounted = false;
            if (editorRef.current && typeof editorRef.current.destroy === 'function') {
                editorRef.current.destroy();
                editorRef.current = null;
                isReady.current = false;
            }
        };
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    if (error) {
        return (
            <Box p="4" borderRadius="md" bg="red.50" border="1px solid" borderColor="red.200">
                <Text color="red.600" fontSize="sm">
                    Ошибка инициализации редактора: {error}
                </Text>
            </Box>
        );
    }

    return (
        <Box
            ref={containerRef}
            minH="200px"
            border="1px solid"
            borderColor="border"
            borderRadius="md"
            p="4"
            bg="bg"
            css={{
                // Стили для Editor.js внутри Chakra
                '& .ce-block__content': {
                    maxWidth: '100%',
                },
                '& .ce-toolbar__content': {
                    maxWidth: '100%',
                },
                '& .codex-editor__redactor': {
                    paddingBottom: '100px',
                },
                '& .ce-paragraph': {
                    lineHeight: 1.7,
                },
                '& .ce-header': {
                    fontWeight: 700,
                },
            }}
        />
    );
}

/**
 * Получить CSRF-токен из cookie (для загрузки изображений)
 */
function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default memo(EditorJsEditor);
