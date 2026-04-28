import { Box, HStack, Text } from '@chakra-ui/react';

/**
 * MatchBadge — подсказка «почему документ оказался в выдаче».
 *
 * Показывается рядом с номером документа в списках Orders/Returns/Shipments,
 * только когда есть активный поисковый запрос. Сам поисковый текст подсвечивается
 * в `snippet` через `<mark>`.
 *
 * Контракт см. docs/tasks/in-progress/2026-04-28_cabinet-search-match-source.md
 *
 * @param {{ source: string|null, snippet: string|null, search: string }} props
 */
const SOURCE_LABELS = {
    number: 'по номеру',
    composition: 'в составе',
    comment: 'в комментарии',
    company: 'в контрагенте',
    fuzzy: 'по похожему совпадению',
};

const SOURCE_COLORS = {
    number: 'blue',
    composition: 'green',
    comment: 'purple',
    company: 'orange',
    fuzzy: 'gray',
};

export default function MatchBadge({ source, snippet, search }) {
    if (!source) {
        return null;
    }

    const label = SOURCE_LABELS[source] ?? 'совпадение';
    const colorPalette = SOURCE_COLORS[source] ?? 'gray';

    return (
        <HStack gap="2" mt="1" align="center" flexWrap="wrap" fontSize="xs">
            <Box
                as="span"
                px="2"
                py="0.5"
                borderRadius="md"
                bg={`${colorPalette}.50`}
                color={`${colorPalette}.700`}
                fontWeight="600"
                _dark={{ bg: `${colorPalette}.900/30`, color: `${colorPalette}.200` }}
            >
                {label}
            </Box>
            {snippet && (
                <Text as="span" color="gray.600" _dark={{ color: 'gray.300' }}>
                    {renderHighlighted(snippet, search)}
                </Text>
            )}
        </HStack>
    );
}

/**
 * Подсветка совпадения case-insensitive — оборачиваем найденную подстроку в `<mark>`.
 * Если запрос пустой или не найден — возвращаем строку без подсветки.
 */
function renderHighlighted(text, search) {
    if (!search || search.trim() === '') {
        return text;
    }
    const needle = search.trim();
    const lowerText = text.toLowerCase();
    const lowerNeedle = needle.toLowerCase();
    const idx = lowerText.indexOf(lowerNeedle);
    if (idx === -1) {
        return text;
    }
    const before = text.slice(0, idx);
    const match = text.slice(idx, idx + needle.length);
    const after = text.slice(idx + needle.length);
    return (
        <>
            {before}
            <Box as="mark" bg="yellow.200" color="inherit" px="0.5" borderRadius="sm">{match}</Box>
            {after}
        </>
    );
}
