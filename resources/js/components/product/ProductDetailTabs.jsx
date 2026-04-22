import { useState, useMemo } from 'react';
import DOMPurify from 'dompurify';
import { Box, Flex, Text, Button, Table } from '@chakra-ui/react';
import { Tabs } from '@chakra-ui/react';
import { LuDownload, LuFileText } from 'react-icons/lu';
import ContentRenderer from '@/components/content/ContentRenderer';

/**
 * ProductDetailTabs — табы с описанием, характеристиками, размерной сеткой, сертификатами и медиа.
 *
 * @param {{
 *   specifications: Object,
 *   description: string,
 *   media: Array<{url: string, type: string}>,
 *   certificates: Array<{id: number, name: string, url: string}>,
 *   sizeChart: {name: string, values: Array<Array<string>>} | null
 * }} props
 */
export default function ProductDetailTabs({ specifications = {}, description = '', media = [], certificates = [], sizeChart = null }) {
    const [expandedSpecs, setExpandedSpecs] = useState(new Set());

    const sanitizedDescription = useMemo(() => DOMPurify.sanitize(description ?? ''), [description]);
    
    // Фильтруем характеристики, убирая те, у которых значение "нет", и форматируем числа
    const validSpecifications = useMemo(() => {
        const specs = {};
        for (const [k, v] of Object.entries(specifications || {})) {
            let val = String(v ?? '').trim();
            if (val.toLowerCase() !== 'нет') {
                if (/^-?\d+\.\d+$/.test(val)) {
                    val = parseFloat(val).toString();
                }
                specs[k] = val;
            }
        }
        return specs;
    }, [specifications]);

    const hasSpecs = Object.keys(validSpecifications).length > 0;
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

    // Собираем доступные табы
    const tabs = [];
    if (hasSpecs) tabs.push({ key: 'specs', label: 'Характеристики' });
    if (hasDesc) tabs.push({ key: 'description', label: 'Описание' });
    if (hasSizeChart) tabs.push({ key: 'sizeChart', label: 'Размерная сетка' });
    if (hasCerts) tabs.push({ key: 'certificates', label: 'Сертификаты' });
    if (hasMedia) tabs.push({ key: 'media', label: 'Медиа' });

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
                    <Box
                        display="grid"
                        gridTemplateColumns={{ base: '1fr', md: '1fr 1fr' }}
                        gapX="8" gapY="2"
                    >
                        {Object.entries(validSpecifications).map(([key, value]) => {
                            const valStr = String(value ?? '');
                            const isExpanded = expandedSpecs.has(key);
                            const showToggle = valStr.length > 80;
                            const toggle = () => {
                                setExpandedSpecs(prev => {
                                    const next = new Set(prev);
                                    next.has(key) ? next.delete(key) : next.add(key);
                                    return next;
                                });
                            };

                            return (
                                <Flex key={key} align="baseline" gap="2" fontSize="sm" py="1" overflow="hidden">
                                    <Text color="gray.500" _dark={{ color: 'gray.400' }} flexShrink={0} truncate title={key}>
                                        {key}
                                    </Text>
                                    <Box flex="1" borderBottomWidth="1px" borderStyle="dotted" borderColor="gray.300" _dark={{ borderColor: 'gray.600' }} transform="translateY(2px)" flexShrink={1} minW="10px" />
                                    <Box flexShrink={1} maxW="55%" textAlign="right" overflow="hidden">
                                        <Text
                                            fontWeight="500"
                                            title={valStr}
                                            css={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', overflowWrap: 'break-word' }}
                                            lineClamp={isExpanded ? undefined : 2}
                                        >
                                            {valStr}
                                        </Text>
                                        {showToggle && (
                                            <Text
                                                as="button" onClick={toggle}
                                                mt="1" fontSize="xs" color="gray.500"
                                                _hover={{ color: 'gray.700' }}
                                                textDecoration="underline"
                                                textUnderlineOffset="2px"
                                            >
                                                {isExpanded ? 'скрыть' : 'ещё'}
                                            </Text>
                                        )}
                                    </Box>
                                </Flex>
                            );
                        })}
                    </Box>
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
        </Tabs.Root>
    );
}
