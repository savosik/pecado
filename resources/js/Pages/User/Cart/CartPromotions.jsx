import { useState } from 'react';
import { Box, Flex, Text, HStack, Image } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuGift, LuCheck } from 'react-icons/lu';
import { ProgressRoot, ProgressBar } from '@/components/ui/progress';

/**
 * Блок «Акции» над таблицей корзины.
 *
 * Волна 1 работает в режиме показа: промо-позиции не выдаются, поэтому здесь
 * только прогресс «доберите на X» и честное «позицию добавит менеджер».
 * Причины несрабатывания правил сюда не приходят вовсе — сервер их не отдаёт.
 *
 * Награда показывается карточкой товара, а не строкой: «× 1 за 0 ₽ (не более
 * 20 раз)» — это конфигурация правила из админки, клиент читает её ребусом.
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

                    <RewardList rewards={card.rewards} tone="purple" caption="Тогда вы получите" />

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

                    <RewardList
                        rewards={card.rewards}
                        tone="green"
                        caption={rewardsCaption(card.rewards)}
                    />

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

/**
 * Награды карточкой: миниатюра, название, сколько штук и «бесплатно».
 *
 * Количество приходит посчитанным для текущей корзины — в подсказке
 * «доберите на X» его ещё нет, там показываем только «что».
 */
function RewardList({ rewards, tone, caption }) {
    if (!rewards?.length) {
        return null;
    }

    return (
        <Box mt="2">
            <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.400' }} mb="1">
                {caption}
            </Text>

            <Flex direction="column" gap="1.5">
                {rewards.map((reward) => (
                    <RewardRow key={`${reward.product_id}-${reward.price}`} reward={reward} tone={tone} />
                ))}
            </Flex>
        </Box>
    );
}

function RewardRow({ reward, tone }) {
    const body = (
        <HStack
            gap="2.5"
            align="center"
            bg="white"
            _dark={{ bg: 'gray.900', borderColor: 'gray.700' }}
            border="1px solid"
            borderColor="gray.200"
            borderRadius="md"
            p="1.5"
        >
            <Box
                w="40px"
                h="40px"
                flexShrink="0"
                borderRadius="sm"
                overflow="hidden"
                bg="gray.100"
                _dark={{ bg: 'gray.800' }}
            >
                {reward.thumbnail_url ? (
                    <Image
                        src={reward.thumbnail_url}
                        alt={reward.name}
                        w="100%"
                        h="100%"
                        objectFit="cover"
                        loading="lazy"
                    />
                ) : (
                    <Flex w="100%" h="100%" align="center" justify="center" color="gray.400">
                        <LuGift size={18} />
                    </Flex>
                )}
            </Box>

            <Box flex="1" minW="0">
                <Text fontSize="xs" lineClamp="2" lineHeight="1.3">
                    {reward.name}
                </Text>

                <Text
                    fontSize="xs"
                    fontWeight="700"
                    mt="0.5"
                    color={reward.is_gift ? `${tone}.700` : 'gray.700'}
                    _dark={{ color: reward.is_gift ? `${tone}.300` : 'gray.300' }}
                >
                    {reward.amount_label}
                </Text>

                {/* Кнопки отказа в волне 1 нет: позицию заводит менеджер вручную,
                    поэтому и канал отказа — он же */}
                {reward.optional && (
                    <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.400' }} mt="0.5">
                        Необязательная позиция — скажите менеджеру, если не нужна
                    </Text>
                )}
            </Box>
        </HStack>
    );

    return reward.url ? <Link href={reward.url}>{body}</Link> : body;
}

/**
 * «Вам полагается» уместно для подарка. Для платной позиции это вводит
 * в заблуждение: 4 шт. по 100 ₽ — не подарок, а 400 ₽ к оплате.
 */
function rewardsCaption(rewards = []) {
    const hasPaid = rewards.some((reward) => !reward.is_gift);
    const hasGift = rewards.some((reward) => reward.is_gift);

    if (hasPaid && hasGift) return 'Вам полагается';
    if (hasPaid) return 'Доступно по промо-цене';

    return 'Вам полагается';
}

function pluralPromotions(count) {
    const mod10 = count % 10;
    const mod100 = count % 100;

    if (mod10 === 1 && mod100 !== 11) return 'акция';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'акции';

    return 'акций';
}
