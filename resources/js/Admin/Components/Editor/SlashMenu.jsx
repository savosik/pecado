import React, { useState, useEffect, useCallback, useRef, forwardRef, useImperativeHandle } from 'react';
import { Box, Text, VStack, HStack, Input } from '@chakra-ui/react';
import {
    LuType, LuHeading2, LuHeading3, LuHeading4,
    LuList, LuListOrdered, LuListChecks,
    LuQuote, LuCode, LuMinus,
    LuImage, LuTable, LuCircleAlert, LuTriangleAlert, LuCircleCheck, LuCircleX,
} from 'react-icons/lu';

/**
 * Описание slash-блоков с русскими названиями.
 */
const SLASH_ITEMS = [
    {
        category: 'Текст',
        items: [
            { title: 'Параграф', description: 'Обычный текст', icon: <LuType />, command: (editor) => editor.chain().focus().setParagraph().run() },
            { title: 'Заголовок 2', description: 'Средний заголовок', icon: <LuHeading2 />, command: (editor) => editor.chain().focus().toggleHeading({ level: 2 }).run() },
            { title: 'Заголовок 3', description: 'Малый заголовок', icon: <LuHeading3 />, command: (editor) => editor.chain().focus().toggleHeading({ level: 3 }).run() },
            { title: 'Заголовок 4', description: 'Подзаголовок', icon: <LuHeading4 />, command: (editor) => editor.chain().focus().toggleHeading({ level: 4 }).run() },
        ],
    },
    {
        category: 'Списки',
        items: [
            { title: 'Маркированный список', description: 'Список с точками', icon: <LuList />, command: (editor) => editor.chain().focus().toggleBulletList().run() },
            { title: 'Нумерованный список', description: 'Список с цифрами', icon: <LuListOrdered />, command: (editor) => editor.chain().focus().toggleOrderedList().run() },
            { title: 'Чеклист', description: 'Список с флажками', icon: <LuListChecks />, command: (editor) => editor.chain().focus().toggleTaskList().run() },
        ],
    },
    {
        category: 'Форматирование',
        items: [
            { title: 'Цитата', description: 'Блок цитаты', icon: <LuQuote />, command: (editor) => editor.chain().focus().toggleBlockquote().run() },
            { title: 'Блок кода', description: 'Моноширинный код', icon: <LuCode />, command: (editor) => editor.chain().focus().toggleCodeBlock().run() },
            { title: 'Разделитель', description: 'Горизонтальная линия', icon: <LuMinus />, command: (editor) => editor.chain().focus().setHorizontalRule().run() },
        ],
    },
    {
        category: 'Блоки',
        items: [
            {
                title: 'Инфо-блок',
                description: 'Информационный callout',
                icon: <LuCircleAlert />,
                command: (editor) => editor.chain().focus().insertContent({
                    type: 'paragraph',
                    content: [{ type: 'text', text: '— Информационный блок —' }],
                }).run(),
                html: '<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #bfdbfe;background:#eff6ff;padding:20px;margin:1em 0"><div style="flex-shrink:0;font-size:1.5em">ℹ️</div><div><p style="font-weight:600;color:#1e3a5f;margin:0">Информация</p><p style="margin-top:6px;font-size:0.875em;color:#1e40af;margin-bottom:0">Текст информационного блока</p></div></div>',
            },
            {
                title: 'Предупреждение',
                description: 'Жёлтый callout',
                icon: <LuTriangleAlert />,
                command: (editor) => editor.chain().focus().insertContent({
                    type: 'paragraph',
                    content: [{ type: 'text', text: '— Предупреждение —' }],
                }).run(),
                html: '<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #fde68a;background:#fefce8;padding:20px;margin:1em 0"><div style="flex-shrink:0;font-size:1.5em">⚠️</div><div><p style="font-weight:600;color:#713f12;margin:0">Внимание</p><p style="margin-top:6px;font-size:0.875em;color:#854d0e;margin-bottom:0">Текст предупреждения</p></div></div>',
            },
            {
                title: 'Успех',
                description: 'Зелёный callout',
                icon: <LuCircleCheck />,
                command: (editor) => editor.chain().focus().insertContent({
                    type: 'paragraph',
                    content: [{ type: 'text', text: '— Блок успеха —' }],
                }).run(),
                html: '<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #bbf7d0;background:#f0fdf4;padding:20px;margin:1em 0"><div style="flex-shrink:0;font-size:1.5em">✅</div><div><p style="font-weight:600;color:#14532d;margin:0">Успешно</p><p style="margin-top:6px;font-size:0.875em;color:#166534;margin-bottom:0">Текст блока успеха</p></div></div>',
            },
            {
                title: 'Важно',
                description: 'Красный callout',
                icon: <LuCircleX />,
                command: (editor) => editor.chain().focus().insertContent({
                    type: 'paragraph',
                    content: [{ type: 'text', text: '— Важно —' }],
                }).run(),
                html: '<div style="display:flex;gap:16px;border-radius:12px;border:1px solid #fecaca;background:#fef2f2;padding:20px;margin:1em 0"><div style="flex-shrink:0;font-size:1.5em">🚫</div><div><p style="font-weight:600;color:#7f1d1d;margin:0">Важно</p><p style="margin-top:6px;font-size:0.875em;color:#991b1b;margin-bottom:0">Текст важного блока</p></div></div>',
            },
        ],
    },
    {
        category: 'Медиа',
        items: [
            {
                title: 'Изображение',
                description: 'Вставить картинку по URL',
                icon: <LuImage />,
                command: (editor) => {
                    const url = window.prompt('URL изображения:');
                    if (url) editor.chain().focus().setImage({ src: url }).run();
                },
            },
            {
                title: 'Таблица',
                description: 'Таблица 3×3',
                icon: <LuTable />,
                command: (editor) => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
            },
        ],
    },
    {
        category: 'Декоративные',
        items: [
            {
                title: 'Градиент-карточка',
                description: 'Акцентный блок с градиентом',
                icon: <LuCircleAlert />,
                html: '<div style="border-radius:16px;background:linear-gradient(135deg,#ec4899 0%,#8b5cf6 100%);padding:40px;color:white;margin:2em 0;box-shadow:0 20px 25px -5px rgba(0,0,0,0.15)"><h3 style="font-size:1.5em;font-weight:700;margin:0 0 12px 0;color:white">Заголовок блока</h3><p style="color:#fce7f3;font-size:1.125em;line-height:1.6;margin:0">Текст акцентного блока. Используйте для спецпредложений, промо-акций и важных анонсов.</p></div>',
            },
            {
                title: 'Статистика',
                description: 'Ряд из 4 карточек со значениями',
                icon: <LuCircleAlert />,
                html: '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:2em 0"><div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)"><div style="font-size:1.75em;font-weight:700;color:#ec4899">1 200+</div><div style="margin-top:4px;font-size:0.875em;color:#6b7280">Товаров</div></div><div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)"><div style="font-size:1.75em;font-weight:700;color:#ec4899">45</div><div style="margin-top:4px;font-size:0.875em;color:#6b7280">Брендов</div></div><div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)"><div style="font-size:1.75em;font-weight:700;color:#ec4899">98%</div><div style="margin-top:4px;font-size:0.875em;color:#6b7280">Довольных</div></div><div style="text-align:center;border-radius:12px;background:white;border:1px solid #e5e7eb;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)"><div style="font-size:1.75em;font-weight:700;color:#ec4899">24ч</div><div style="margin-top:4px;font-size:0.875em;color:#6b7280">Отгрузка</div></div></div>',
            },
            {
                title: 'Таймлайн',
                description: 'Пошаговый процесс',
                icon: <LuCircleAlert />,
                html: '<div style="margin:2em 0"><div style="display:flex;gap:16px;margin-bottom:0"><div style="display:flex;flex-direction:column;align-items:center"><div style="width:40px;height:40px;border-radius:50%;background:#ec4899;color:white;display:flex;align-items:center;justify-content:center;font-size:0.875em;font-weight:700;box-shadow:0 4px 6px rgba(236,72,153,0.3);flex-shrink:0">01</div><div style="width:2px;flex:1;background:#fbcfe8;min-height:32px"></div></div><div style="padding-bottom:32px"><h4 style="font-weight:600;margin:0 0 4px 0">Шаг первый</h4><p style="font-size:0.875em;color:#6b7280;margin:0">Описание первого шага процесса.</p></div></div><div style="display:flex;gap:16px;margin-bottom:0"><div style="display:flex;flex-direction:column;align-items:center"><div style="width:40px;height:40px;border-radius:50%;background:#ec4899;color:white;display:flex;align-items:center;justify-content:center;font-size:0.875em;font-weight:700;box-shadow:0 4px 6px rgba(236,72,153,0.3);flex-shrink:0">02</div><div style="width:2px;flex:1;background:#fbcfe8;min-height:32px"></div></div><div style="padding-bottom:32px"><h4 style="font-weight:600;margin:0 0 4px 0">Шаг второй</h4><p style="font-size:0.875em;color:#6b7280;margin:0">Описание второго шага процесса.</p></div></div><div style="display:flex;gap:16px"><div style="display:flex;flex-direction:column;align-items:center"><div style="width:40px;height:40px;border-radius:50%;background:#ec4899;color:white;display:flex;align-items:center;justify-content:center;font-size:0.875em;font-weight:700;box-shadow:0 4px 6px rgba(236,72,153,0.3);flex-shrink:0">03</div></div><div><h4 style="font-weight:600;margin:0 0 4px 0">Шаг третий</h4><p style="font-size:0.875em;color:#6b7280;margin:0">Описание третьего шага процесса.</p></div></div></div>',
            },
            {
                title: 'Сравнение',
                description: '«До / После»',
                icon: <LuCircleAlert />,
                html: '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:24px;margin:2em 0"><div style="border-radius:12px;border:2px solid #fecaca;padding:24px;background:#fef2f2"><div style="font-size:0.75em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#ef4444;margin-bottom:12px">❌ Было</div><p style="color:#4b5563;font-size:0.875em;line-height:1.6;margin:0">Описание состояния «до» изменений.</p></div><div style="border-radius:12px;border:2px solid #bbf7d0;padding:24px;background:#f0fdf4"><div style="font-size:0.75em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#22c55e;margin-bottom:12px">✅ Стало</div><p style="color:#4b5563;font-size:0.875em;line-height:1.6;margin:0">Описание состояния «после» изменений.</p></div></div>',
            },
            {
                title: 'Pull-цитата',
                description: 'Акцентная цитата с градиентом',
                icon: <LuQuote />,
                html: '<div style="border-left:4px solid #e94560;background:linear-gradient(135deg,#fdf2f8 0%,#fce7f3 100%);border-radius:0 12px 12px 0;padding:24px 32px;margin:2.5em 0"><p style="font-size:1.375em;font-weight:600;color:#1f2937;font-style:italic;line-height:1.4;margin:0">«Ваша цитата здесь.»</p><p style="margin-top:12px;font-size:0.875em;color:#6b7280;margin-bottom:0">— Автор</p></div>',
            },
            {
                title: 'Бейджи',
                description: 'Ряд цветных меток',
                icon: <LuCircleAlert />,
                html: '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:1em 0"><span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#dcfce7;color:#166534">Новинка</span><span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#fce7f3;color:#9d174d">Акция</span><span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#dbeafe;color:#1e40af">Популярный</span><span style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:9999px;font-size:0.75em;font-weight:500;background:#f3e8ff;color:#6b21a8">Эксклюзив</span></div>',
            },
        ],
    },
];

/**
 * Slash-меню для Tiptap-редактора.
 * Появляется в начале пустой строки при вводе «/».
 */
export const SlashMenu = forwardRef(({ editor, query, onClose, position }, ref) => {
    const [selectedIndex, setSelectedIndex] = useState(0);
    const menuRef = useRef(null);

    // Фильтруем элементы по введённому тексту после «/»
    const filteredCategories = SLASH_ITEMS.map((cat) => ({
        ...cat,
        items: cat.items.filter((item) =>
            item.title.toLowerCase().includes((query || '').toLowerCase()) ||
            item.description.toLowerCase().includes((query || '').toLowerCase())
        ),
    })).filter((cat) => cat.items.length > 0);

    const allItems = filteredCategories.flatMap((cat) => cat.items);

    const selectItem = useCallback((index) => {
        const item = allItems[index];
        if (!item) return;

        if (item.html) {
            // Для callout-блоков — вставляем готовый HTML
            editor.chain().focus().insertContent(item.html).run();
        } else {
            item.command(editor);
        }

        onClose?.();
    }, [allItems, editor, onClose]);

    useImperativeHandle(ref, () => ({
        onKeyDown: ({ event }) => {
            if (event.key === 'ArrowUp') {
                setSelectedIndex((prev) => (prev - 1 + allItems.length) % allItems.length);
                return true;
            }
            if (event.key === 'ArrowDown') {
                setSelectedIndex((prev) => (prev + 1) % allItems.length);
                return true;
            }
            if (event.key === 'Enter') {
                selectItem(selectedIndex);
                return true;
            }
            return false;
        },
    }));

    useEffect(() => {
        setSelectedIndex(0);
    }, [query]);

    if (allItems.length === 0) {
        return (
            <Box
                position="absolute"
                zIndex="dropdown"
                bg="bg.panel"
                border="1px solid"
                borderColor="border"
                borderRadius="lg"
                boxShadow="lg"
                p="3"
                minW="200px"
                style={position ? { top: position.top, left: position.left } : undefined}
            >
                <Text fontSize="sm" color="fg.muted">Ничего не найдено</Text>
            </Box>
        );
    }

    let flatIndex = 0;

    return (
        <Box
            ref={menuRef}
            position="absolute"
            zIndex="dropdown"
            bg="bg.panel"
            border="1px solid"
            borderColor="border"
            borderRadius="lg"
            boxShadow="lg"
            py="2"
            minW="280px"
            maxH="350px"
            overflowY="auto"
            style={position ? { top: position.top, left: position.left } : undefined}
        >
            {filteredCategories.map((cat) => (
                <Box key={cat.category}>
                    <Text
                        px="3"
                        py="1"
                        fontSize="2xs"
                        fontWeight="bold"
                        color="fg.muted"
                        textTransform="uppercase"
                        letterSpacing="wider"
                    >
                        {cat.category}
                    </Text>
                    {cat.items.map((item) => {
                        const currentIndex = flatIndex++;
                        const isSelected = currentIndex === selectedIndex;
                        return (
                            <HStack
                                key={item.title}
                                px="3"
                                py="1.5"
                                cursor="pointer"
                                bg={isSelected ? 'purple.50' : 'transparent'}
                                _dark={{ bg: isSelected ? 'purple.950/40' : 'transparent' }}
                                _hover={{ bg: isSelected ? undefined : 'gray.50', _dark: { bg: isSelected ? undefined : 'gray.800' } }}
                                onClick={() => selectItem(currentIndex)}
                                gap="3"
                                borderRadius="md"
                                mx="1"
                            >
                                <Box
                                    w="8"
                                    h="8"
                                    borderRadius="md"
                                    bg={isSelected ? 'purple.100' : 'gray.100'}
                                    _dark={{ bg: isSelected ? 'purple.900/40' : 'gray.700' }}
                                    display="flex"
                                    alignItems="center"
                                    justifyContent="center"
                                    fontSize="md"
                                    color={isSelected ? 'purple.600' : 'fg.muted'}
                                    flexShrink={0}
                                >
                                    {item.icon}
                                </Box>
                                <VStack align="start" gap="0">
                                    <Text fontSize="sm" fontWeight="medium" color="fg">
                                        {item.title}
                                    </Text>
                                    <Text fontSize="2xs" color="fg.muted" lineHeight="1.2">
                                        {item.description}
                                    </Text>
                                </VStack>
                            </HStack>
                        );
                    })}
                </Box>
            ))}
        </Box>
    );
});

SlashMenu.displayName = 'SlashMenu';
