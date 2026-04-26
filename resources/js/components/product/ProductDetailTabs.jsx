import { useMemo } from 'react';
import DOMPurify from 'dompurify';
import { Box, Flex, Text, Button, Table } from '@chakra-ui/react';
import { Tabs } from '@chakra-ui/react';
import { LuDownload, LuFileText } from 'react-icons/lu';
import ContentRenderer from '@/components/content/ContentRenderer';
import SimilarProductItem from '@/components/product/SimilarProductItem';

const MONTHS_RU = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function formatSpecValue(raw) {
    const val = String(raw ?? '').trim();
    if (/^-?\d+\.\d+$/.test(val)) {
        return parseFloat(val).toString();
    }
    const dateMatch = val.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/);
    if (dateMatch) {
        const [, y, m, d, hh, mm] = dateMatch;
        const monthIdx = parseInt(m, 10) - 1;
        const day = parseInt(d, 10);
        if (monthIdx >= 0 && monthIdx < 12) {
            const base = `${day} ${MONTHS_RU[monthIdx]} ${y}`;
            return hh && !(hh === '00' && mm === '00') ? `${base}, ${hh}:${mm}` : base;
        }
    }
    return val;
}

function isValidSpecValue(val) {
    return val !== '' && val.toLowerCase() !== 'нет';
}

/**
 * ProductDetailTabs — табы с описанием, характеристиками, размерной сеткой, сертификатами и медиа.
 *
 * @param {{
 *   specifications: Object,
 *   specificationGroups: Array<{name: string, items: Array<{name: string, value: string}>}>,
 *   description: string,
 *   media: Array<{url: string, type: string}>,
 *   certificates: Array<{id: number, name: string, url: string}>,
 *   sizeChart: {name: string, values: Array<Array<string>>} | null,
 *   similarProducts: Array<Object>
 * }} props
 */
export default function ProductDetailTabs({ specifications = {}, specificationGroups = [], description = '', media = [], certificates = [], sizeChart = null, similarProducts = [] }) {
    const sanitizedDescription = useMemo(() => DOMPurify.sanitize(description ?? ''), [description]);

    const validSpecifications = useMemo(() => {
        const specs = {};
        for (const [k, v] of Object.entries(specifications || {})) {
            const val = formatSpecValue(v);
            if (isValidSpecValue(val)) {
                specs[k] = val;
            }
        }
        return specs;
    }, [specifications]);

    const validSpecGroups = useMemo(() => {
        if (!Array.isArray(specificationGroups)) return [];
        return specificationGroups
            .map((g) => ({
                name: g?.name ?? '',
                items: (g?.items ?? [])
                    .map((it) => ({ name: it?.name ?? '', value: formatSpecValue(it?.value) }))
                    .filter((it) => it.name && isValidSpecValue(it.value)),
            }))
            .filter((g) => g.items.length > 0);
    }, [specificationGroups]);

    const hasGroups = validSpecGroups.length > 0;
    const hasSpecs = hasGroups || Object.keys(validSpecifications).length > 0;
    const hasDesc = sanitizedDescription.trim().length > 0;
    const hasMedia = Array.isArray(media) && media.filter(m => m.type === 'image' || m.type === 'video').length > 0;
    const hasCerts = Array.isArray(certificates) && certificates.length > 0;

    // Перевод ключей размерной сетки
    const sizeChartKeyLabels = {
        size: 'Размер', bust: 'Обхват груди', underbust: 'Под грудью',
        waist: 'Обхват талии', hips: 'Обхват бёдер',
        height: 'Рост', weight: 'Вес', length: 'Длина',
        chest: 'Грудь', shoulder: 'Плечо', sleeve: 'Рукав',
        inseam: 'Шаговый шов', thigh: 'Бедро', knee: 'Колено',
        foot: 'Стопа', palm: 'Ладонь', finger: 'Палец',
    };

    // Нормализация values: если [{key: val, ...}, ...] → [["header", ...], ["val", ...]]
    // Также убираем колонки, где все значения пусты
    const normalizedSizeChart = useMemo(() => {
        if (!sizeChart || !Array.isArray(sizeChart.values) || sizeChart.values.length === 0) return null;

        const vals = sizeChart.values;

        // Убрать пустые колонки из 2D-массива
        const filterEmptyColumns = (table) => {
            if (table.length < 2) return table;
            const dataRows = table.slice(1);
            const keepIdx = table[0].map((_, colIdx) =>
                dataRows.some(row => row[colIdx] !== undefined && row[colIdx] !== null && String(row[colIdx]).trim() !== '')
            );
            return table.map(row => row.filter((_, colIdx) => keepIdx[colIdx]));
        };

        // Если уже 2D-массив — фильтруем пустые колонки
        if (Array.isArray(vals[0])) {
            const filtered = filterEmptyColumns(vals);
            return filtered.length > 1 && filtered[0].length > 0
                ? { name: sizeChart.name, values: filtered }
                : null;
        }

        // Формат объектов — конвертируем
        if (typeof vals[0] === 'object' && vals[0] !== null) {
            const keys = Object.keys(vals[0]);
            if (keys.length === 0) return null;
            // Ставим 'size' первым, если есть
            const allKeys = keys.includes('size')
                ? ['size', ...keys.filter(k => k !== 'size')]
                : keys;
            // Убираем ключи, где у всех строк значение пусто
            const orderedKeys = allKeys.filter(k =>
                vals.some(row => row[k] !== undefined && row[k] !== null && String(row[k]).trim() !== '')
            );
            if (orderedKeys.length === 0) return null;
            const headers = orderedKeys.map(k => sizeChartKeyLabels[k] || k);
            const rows = vals.map(row => orderedKeys.map(k => row[k] ?? ''));
            return { name: sizeChart.name, values: [headers, ...rows] };
        }

        return null;
    }, [sizeChart]);

    const hasSizeChart = normalizedSizeChart && normalizedSizeChart.values.length > 1;
    const hasSimilar = Array.isArray(similarProducts) && similarProducts.length > 0;

    // Собираем доступные табы
    const tabs = [];
    if (hasSpecs) tabs.push({ key: 'specs', label: 'Характеристики' });
    if (hasDesc) tabs.push({ key: 'description', label: 'Описание' });
    if (hasSizeChart) tabs.push({ key: 'sizeChart', label: 'Размерная сетка' });
    if (hasCerts) tabs.push({ key: 'certificates', label: 'Сертификаты' });
    if (hasMedia) tabs.push({ key: 'media', label: 'Медиа' });
    if (hasSimilar) tabs.push({ key: 'similar', label: 'Похожие' });

    if (tabs.length === 0) return null;

    const handleDownload = (url, filename) => {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    // Дополнительные медиа (только изображения и видео из media prop)
    const additionalImages = media.filter(m => m.type === 'image');
    const additionalVideos = media.filter(m => m.type === 'video');

    return (
        <Tabs.Root defaultValue={tabs[0].key} variant="line" colorPalette="pecado">
            <Box overflowX="auto" css={{ scrollbarWidth: 'none', '&::-webkit-scrollbar': { display: 'none' } }}>
                <Tabs.List>
                    {tabs.map(tab => (
                        <Tabs.Trigger
                            key={tab.key}
                            value={tab.key}
                            fontSize="sm"
                            fontWeight="500"
                            px="4" py="3"
                            whiteSpace="nowrap"
                        >
                            {tab.label}
                        </Tabs.Trigger>
                    ))}
                </Tabs.List>
            </Box>

            {/* Характеристики */}
            {hasSpecs && (
                <Tabs.Content value="specs" pt="4">
                    {hasGroups ? (
                        <Box
                            css={{
                                columnGap: '2.5rem',
                                '@media (min-width: 768px)': { columnCount: 2 },
                            }}
                        >
                            {validSpecGroups.map((group) => (
                                <Box
                                    key={group.name}
                                    mb="5"
                                    css={{ breakInside: 'avoid', WebkitColumnBreakInside: 'avoid', pageBreakInside: 'avoid' }}
                                >
                                    <Text
                                        as="h3"
                                        fontSize="xs"
                                        fontWeight="600"
                                        color="gray.500"
                                        _dark={{ color: 'gray.400' }}
                                        textTransform="uppercase"
                                        letterSpacing="0.04em"
                                        mb="1.5"
                                    >
                                        {group.name}
                                    </Text>
                                    <Flex direction="column" gap="0.5">
                                        {group.items.map((item) => (
                                            <Text
                                                key={item.name}
                                                fontSize="sm"
                                                lineHeight="1.5"
                                                css={{ wordBreak: 'break-word', overflowWrap: 'break-word' }}
                                            >
                                                <Text as="span" color="gray.500" _dark={{ color: 'gray.400' }}>
                                                    {item.name}:{' '}
                                                </Text>
                                                <Text as="span" fontWeight="500" color="gray.800" _dark={{ color: 'gray.100' }}>
                                                    {item.value}
                                                </Text>
                                            </Text>
                                        ))}
                                    </Flex>
                                </Box>
                            ))}
                        </Box>
                    ) : (
                        <Box
                            display="grid"
                            gridTemplateColumns={{ base: '1fr', md: '1fr 1fr' }}
                            columnGap={{ base: '6', md: '10' }}
                            rowGap="2"
                        >
                            {Object.entries(validSpecifications).map(([key, value]) => (
                                <Text
                                    key={key}
                                    fontSize="sm"
                                    lineHeight="1.5"
                                    css={{ wordBreak: 'break-word', overflowWrap: 'break-word' }}
                                >
                                    <Text as="span" color="gray.500" _dark={{ color: 'gray.400' }}>
                                        {key}:{' '}
                                    </Text>
                                    <Text as="span" fontWeight="500" color="gray.800" _dark={{ color: 'gray.100' }}>
                                        {String(value ?? '')}
                                    </Text>
                                </Text>
                            ))}
                        </Box>
                    )}
                </Tabs.Content>
            )}

            {/* Описание */}
            {hasDesc && (
                <Tabs.Content value="description" pt="4">
                    <Box
                        p={{ base: '3', md: '5' }}
                        rounded="sm"
                        css={{
                            '& > div': { lineHeight: '1.5em' },
                            '& p': { marginTop: '0.5em', marginBottom: '0.5em' },
                        }}
                    >
                        <ContentRenderer content={description} proseSize="md" />
                    </Box>
                </Tabs.Content>
            )}

            {/* Размерная сетка */}
            {hasSizeChart && (
                <Tabs.Content value="sizeChart" pt="4">
                    <Box spaceY="3">
                        {normalizedSizeChart.name && (
                            <Text fontSize="sm" fontWeight="600" color="gray.600" _dark={{ color: 'gray.300' }}>
                                {normalizedSizeChart.name}
                            </Text>
                        )}
                        <Box overflowX="auto">
                            <Table.Root size="sm" variant="outline">
                                <Table.Header>
                                    <Table.Row>
                                        {normalizedSizeChart.values[0].map((header, i) => (
                                            <Table.ColumnHeader
                                                key={i}
                                                fontSize="xs"
                                                fontWeight="600"
                                                textAlign="center"
                                                whiteSpace="nowrap"
                                                bg="gray.50"
                                                _dark={{ bg: 'gray.700' }}
                                            >
                                                {header}
                                            </Table.ColumnHeader>
                                        ))}
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {normalizedSizeChart.values.slice(1).map((row, rowIdx) => (
                                        <Table.Row key={rowIdx}>
                                            {row.map((cell, cellIdx) => (
                                                <Table.Cell
                                                    key={cellIdx}
                                                    fontSize="sm"
                                                    textAlign="center"
                                                    whiteSpace="nowrap"
                                                >
                                                    {cell}
                                                </Table.Cell>
                                            ))}
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Box>
                </Tabs.Content>
            )}

            {/* Сертификаты */}
            {hasCerts && (
                <Tabs.Content value="certificates" pt="4">
                    <Box spaceY="3">
                        {certificates.map((cert, index) => (
                            <Flex
                                key={cert.id || index}
                                direction={{ base: 'column', sm: 'row' }}
                                align={{ sm: 'center' }}
                                justify={{ sm: 'space-between' }}
                                gap="3"
                                p="4"
                                borderWidth="1px"
                                borderColor={{ base: 'gray.200', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}
                                rounded="sm"
                                _hover={{ bg: 'gray.50', _dark: { bg: 'gray.800' } }}
                                transition="background 0.15s"
                            >
                                <Flex align="center" gap="3" minW="0" flex="1">
                                    <Box flexShrink={0} color="gray.500">
                                        <LuFileText size={20} />
                                    </Box>
                                    <Text css={{ wordBreak: 'break-word' }}>{cert.name}</Text>
                                </Flex>
                                {cert.url && (
                                    <Button
                                        size="sm" variant="outline" w={{ base: '100%', sm: 'auto' }}
                                        onClick={() => handleDownload(cert.url, cert.name)}
                                    >
                                        <LuDownload size={16} />
                                        Скачать
                                    </Button>
                                )}
                            </Flex>
                        ))}
                    </Box>
                </Tabs.Content>
            )}

            {/* Медиа */}
            {hasMedia && (
                <Tabs.Content value="media" pt="4">
                    <Box spaceY="6">
                        {additionalImages.length > 0 && (
                            <Box spaceY="3">
                                <Text fontSize="sm" fontWeight="600" textTransform="uppercase" letterSpacing="wide" color="gray.500">
                                    Изображения — {additionalImages.length}
                                </Text>
                                <Box
                                    display="grid"
                                    gridTemplateColumns={{ base: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', md: 'repeat(4, 1fr)', lg: 'repeat(5, 1fr)' }}
                                    gap="4"
                                >
                                    {additionalImages.map((item, idx) => (
                                        <Box
                                            key={idx}
                                            rounded="sm" p="2"
                                            borderWidth="1px" borderColor={{ base: 'gray.200', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}
                                            _hover={{ shadow: 'sm' }}
                                            transition="box-shadow 0.15s"
                                        >
                                            <Box css={{ aspectRatio: '1' }} rounded="sm" overflow="hidden" bg={{ base: 'white', _dark: 'gray.800' }} mb="2">
                                                <Box as="img" src={item.url} alt={`Изображение ${idx + 1}`} w="100%" h="100%" objectFit="cover" loading="lazy" />
                                            </Box>
                                            <Button
                                                size="xs" variant="outline" w="100%" fontSize="xs"
                                                onClick={() => handleDownload(item.url, `image-${idx + 1}.jpg`)}
                                            >
                                                Скачать
                                            </Button>
                                        </Box>
                                    ))}
                                </Box>
                            </Box>
                        )}

                        {additionalVideos.length > 0 && (
                            <Box spaceY="3">
                                <Text fontSize="sm" fontWeight="600" textTransform="uppercase" letterSpacing="wide" color="gray.500">
                                    Видео — {additionalVideos.length}
                                </Text>
                                <Box
                                    display="grid"
                                    gridTemplateColumns={{ base: 'repeat(1, 1fr)', sm: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)' }}
                                    gap="4"
                                >
                                    {additionalVideos.map((item, idx) => (
                                        <Box
                                            key={idx}
                                            rounded="sm" p="2"
                                            borderWidth="1px" borderColor={{ base: 'gray.200', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}
                                        >
                                            <Box css={{ aspectRatio: '16 / 9' }} rounded="sm" overflow="hidden" bg="black" mb="2">
                                                <Box as="video" src={item.url} w="100%" h="100%" objectFit="cover" controls />
                                            </Box>
                                        </Box>
                                    ))}
                                </Box>
                            </Box>
                        )}
                    </Box>
                </Tabs.Content>
            )}

            {/* Похожие */}
            {hasSimilar && (
                <Tabs.Content value="similar" pt="4">
                    <Box
                        borderWidth="1px"
                        borderColor={{ base: 'gray.200', _dark: 'gray.700' }}
                        _dark={{ borderColor: 'gray.700' }}
                        rounded="md"
                        overflow="hidden"
                    >
                        {similarProducts.map((p) => (
                            <SimilarProductItem key={p.id} product={p} />
                        ))}
                    </Box>
                </Tabs.Content>
            )}
        </Tabs.Root>
    );
}
