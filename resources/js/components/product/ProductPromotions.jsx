import { Box, Flex, Text, Image } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuTag, LuChevronRight } from 'react-icons/lu';

/**
 * ProductPromotions — компактный блок акций, в которых участвует товар.
 * Каждая акция — кликабельная строка-чип с переходом на детальную страницу акции.
 *
 * @param {{ promotions: Array<{ id: number, name: string, slug: string, image?: string|null }> }} props
 */
export default function ProductPromotions({ promotions = [] }) {
    if (!promotions || promotions.length === 0) return null;

    return (
        <Box>
            <Flex align="center" gap="1.5" mb="2" color="red.600" _dark={{ color: 'red.400' }}>
                <LuTag size={14} style={{ flexShrink: 0 }} />
                <Text fontSize="xs" fontWeight="600" textTransform="uppercase" letterSpacing="0.03em">
                    {promotions.length === 1 ? 'Участвует в акции' : 'Участвует в акциях'}
                </Text>
            </Flex>

            <Flex direction="column" gap="1.5">
                {promotions.map((promo) => (
                    <Flex
                        key={promo.id}
                        as={Link}
                        href={`/promotions/${promo.slug}`}
                        align="center"
                        gap="2.5"
                        px="2.5"
                        py="2"
                        rounded="lg"
                        borderWidth="1px"
                        borderColor="red.200"
                        bg="red.50"
                        _dark={{ borderColor: 'red.800/60', bg: 'red.900/20' }}
                        _hover={{
                            borderColor: 'red.300',
                            bg: 'red.100',
                            _dark: { borderColor: 'red.700', bg: 'red.900/40' },
                        }}
                        transition="all 0.15s"
                    >
                        {promo.image ? (
                            <Image
                                src={promo.image}
                                alt={promo.name}
                                boxSize="8"
                                rounded="md"
                                objectFit="cover"
                                flexShrink="0"
                            />
                        ) : (
                            <Flex
                                boxSize="8"
                                rounded="md"
                                align="center"
                                justify="center"
                                bg="red.100"
                                color="red.500"
                                _dark={{ bg: 'red.900/40', color: 'red.300' }}
                                flexShrink="0"
                            >
                                <LuTag size={16} />
                            </Flex>
                        )}

                        <Text
                            flex="1"
                            fontSize="sm"
                            fontWeight="500"
                            color="fg"
                            lineClamp={1}
                        >
                            {promo.name}
                        </Text>

                        <LuChevronRight size={16} style={{ flexShrink: 0, opacity: 0.5 }} />
                    </Flex>
                ))}
            </Flex>
        </Box>
    );
}
