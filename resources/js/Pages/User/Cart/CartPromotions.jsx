import { useState } from 'react';
import { Box, Flex, Text, HStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuGift, LuCheck } from 'react-icons/lu';
import { ProgressRoot, ProgressBar } from '@/components/ui/progress';

/**
 * Блок «Акции» над таблицей корзины.
 *
 * Волна 1 работает в режиме показа: промо-позиции не выдаются, поэтому здесь
 * только прогресс «доберите на X» и честное «позицию добавит менеджер».
 * Причины несрабатывания правил сюда не приходят вовсе — сервер их не отдаёт.
 */
export default function CartPromotions({ promotions, loading = false }) {
    const [expanded, setExpanded] = useState(false);

    const nearMiss = promotions?.near_miss ?? [];
    const achieved = promotions?.achieved ?? [];
    const maxVisible = promotions?.max_visible ?? 3;

    if (nearMiss.length === 0 && achieved.length === 0) {
        return null;
    }

    const visibleNearMiss = expanded ? nearMiss : nearMiss.slice(0, maxVisible);
    const hiddenCount = nearMiss.length - visibleNearMiss.length;

    return (
        <Box
            mb="4"
            opacity={loading ? 0.6 : 1}
            transition="opacity 0.2s"
        >
            <Flex direction="column" gap="2">
                {achieved.map((card) => (
                    <AchievedCard key={`achieved-${card.rule_id}`} card={card} />
                ))}

                {visibleNearMiss.map((card) => (
                    <NearMissCard key={`near-${card.rule_id}`} card={card} />
                ))}
            </Flex>

            {hiddenCount > 0 && (
                <Box
                    as="button"
                    type="button"
                    mt="2"
                    fontSize="sm"
                    color="purple.600"
                    _dark={{ color: 'purple.300' }}
                    onClick={() => setExpanded(true)}
                >
                    Ещё {hiddenCount} {pluralPromotions(hiddenCount)} →
                </Box>
            )}
        </Box>
    );
}

function NearMissCard({ card }) {
    return (
        <Box
            border="1px solid"
            borderColor="purple.200"
            _dark={{ borderColor: 'purple.800', bg: 'purple.950/30' }}
            bg="purple.50"
            borderRadius="md"
            px={{ base: '3', md: '4' }}
            py={{ base: '2.5', md: '3' }}
        >
            <Flex align="start" gap="3">
                <Box color="purple.500" _dark={{ color: 'purple.300' }} mt="0.5" flexShrink="0">
                    <LuGift size={18} />
                </Box>

                <Box flex="1" minW="0">
                    <Text fontSize="sm" fontWeight="700" lineHeight="1.3">
                        {card.title}
                    </Text>
                    <Text fontSize="sm" color="gray.700" _dark={{ color: 'gray.300' }} mt="0.5">
                        {card.message}
                    </Text>

                    <ProgressRoot
                        value={Math.round((card.progress ?? 0) * 100)}
                        size="sm"
                        colorPalette="purple"
                        mt="2"
                    >
                        <ProgressBar />
                    </ProgressRoot>

                    <HStack justify="space-between" mt="1" gap="2" flexWrap="wrap">
                        <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.400' }}>
                            {card.current_label} из {card.target_label}
                        </Text>

                        {/* На мобиле ссылка живёт в той же строке, что и цифры */}
                        {card.promotion_url ? (
                            <Link href={card.promotion_url}>
                                <Text fontSize="xs" color="purple.600" _dark={{ color: 'purple.300' }}>
                                    Об акции →
                                </Text>
                            </Link>
                        ) : (
                            <Link href="/products?in_promotion=1">
                                <Text fontSize="xs" color="purple.600" _dark={{ color: 'purple.300' }}>
                                    Показать акционные товары →
                                </Text>
                            </Link>
                        )}
                    </HStack>
                </Box>
            </Flex>
        </Box>
    );
}

function AchievedCard({ card }) {
    return (
        <Box
            border="1px solid"
            borderColor="green.200"
            _dark={{ borderColor: 'green.800', bg: 'green.950/30' }}
            bg="green.50"
            borderRadius="md"
            px={{ base: '3', md: '4' }}
            py={{ base: '2.5', md: '3' }}
        >
            <Flex align="start" gap="3">
                <Box color="green.600" _dark={{ color: 'green.300' }} mt="0.5" flexShrink="0">
                    <LuCheck size={18} />
                </Box>

                <Box flex="1" minW="0">
                    <Text fontSize="sm" fontWeight="700" lineHeight="1.3">
                        {card.title}
                    </Text>
                    <Text fontSize="sm" color="gray.700" _dark={{ color: 'gray.300' }} mt="0.5">
                        {card.message}
                    </Text>

                    {card.promotion_url && (
                        <Link href={card.promotion_url}>
                            <Text fontSize="xs" color="green.700" _dark={{ color: 'green.300' }} mt="1">
                                Об акции →
                            </Text>
                        </Link>
                    )}
                </Box>
            </Flex>
        </Box>
    );
}

function pluralPromotions(count) {
    const mod10 = count % 10;
    const mod100 = count % 100;

    if (mod10 === 1 && mod100 !== 11) return 'акция';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'акции';

    return 'акций';
}
