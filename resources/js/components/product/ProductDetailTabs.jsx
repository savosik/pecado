import { useState, useMemo } from 'react';
import DOMPurify from 'dompurify';
import { Box, Flex, Text, Button } from '@chakra-ui/react';
import { Tabs } from '@chakra-ui/react';
import { LuDownload, LuFileText } from 'react-icons/lu';

/**
 * ProductDetailTabs — табы с описанием, характеристиками, сертификатами и медиа.
 *
 * @param {{
 *   specifications: Object,
 *   description: string,
 *   media: Array<{url: string, type: string}>,
 *   certificates: Array<{id: number, name: string, url: string}>
 * }} props
 */
export default function ProductDetailTabs({ specifications = {}, description = '', media = [], certificates = [] }) {
    const [expandedSpecs, setExpandedSpecs] = useState(new Set());

    const sanitizedDescription = useMemo(() => DOMPurify.sanitize(description ?? ''), [description]);
    const hasSpecs = Object.keys(specifications || {}).length > 0;
    const hasDesc = sanitizedDescription.trim().length > 0;
    const hasMedia = Array.isArray(media) && media.filter(m => m.type === 'image' || m.type === 'video').length > 0;
    const hasCerts = Array.isArray(certificates) && certificates.length > 0;

    // Собираем доступные табы
    const tabs = [];
    if (hasSpecs) tabs.push({ key: 'specs', label: 'Характеристики' });
    if (hasDesc) tabs.push({ key: 'description', label: 'Описание' });
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
        <Tabs.Root defaultValue={tabs[0].key} variant="line">
            <Box overflowX="auto" css={{ scrollbarWidth: 'none', '&::-webkit-scrollbar': { display: 'none' } }}>
                <Tabs.List borderBottomWidth="1px" borderColor="gray.200" _dark={{ borderColor: 'gray.700' }}>
                    {tabs.map(tab => (
                        <Tabs.Trigger
                            key={tab.key}
                            value={tab.key}
                            fontSize="sm"
                            fontWeight="500"
                            px="4" py="3"
                            whiteSpace="nowrap"
                            _selected={{
                                color: 'pink.600',
                                borderBottomColor: 'pink.500',
                                _dark: { color: 'pink.400', borderBottomColor: 'pink.400' }
                            }}
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
                        {Object.entries(specifications).map(([key, value]) => {
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
                                <Flex key={key} align="baseline" gap="2" fontSize="sm" py="1">
                                    <Text color="gray.500" _dark={{ color: 'gray.400' }} flexShrink={0} truncate title={key}>
                                        {key}
                                    </Text>
                                    <Box flex="1" borderBottomWidth="1px" borderStyle="dotted" borderColor="gray.300" _dark={{ borderColor: 'gray.600' }} transform="translateY(2px)" />
                                    <Box flexShrink={0} maxW="55%" textAlign="right">
                                        <Text
                                            fontWeight="500"
                                            title={valStr}
                                            css={isExpanded ? { whiteSpace: 'pre-wrap', wordBreak: 'break-word' } : undefined}
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
                    <Box p={{ base: '3', md: '5' }} rounded="sm">
                        <Box
                            fontSize="md" lineHeight="relaxed"
                            dangerouslySetInnerHTML={{ __html: sanitizedDescription }}
                            css={{
                                '& p': { marginBottom: '0.75em' },
                                '& ul, & ol': { paddingLeft: '1.5em', marginBottom: '0.75em' },
                                '& h2, & h3': { fontWeight: '600', marginTop: '1em', marginBottom: '0.5em' },
                                '& a': { color: 'var(--chakra-colors-pink-500)', textDecoration: 'underline' },
                            }}
                        />
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
                                borderColor="gray.200" _dark={{ borderColor: 'gray.700' }}
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
                                            borderWidth="1px" borderColor="gray.200" _dark={{ borderColor: 'gray.700' }}
                                            _hover={{ shadow: 'sm' }}
                                            transition="box-shadow 0.15s"
                                        >
                                            <Box css={{ aspectRatio: '1' }} rounded="sm" overflow="hidden" bg="white" mb="2">
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
                                            borderWidth="1px" borderColor="gray.200" _dark={{ borderColor: 'gray.700' }}
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
