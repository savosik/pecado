import {
    Box, Flex, Grid, GridItem, Text, VStack, HStack, Badge,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { ColorModeButton } from '@/components/ui/color-mode';
import EffectsToggleButton from '@/components/common/EffectsToggleButton';
import PwaInstallBanner from '@/components/PwaInstallBanner';

// Футер всегда чёрный вне зависимости от темы, поэтому цвета заданы явно, без _dark.
const HOVER_ACCENT = 'pecado.500';

export default function UserFooter() {
    const { footerCategories = [], footerMenuItems = [] } = usePage().props;
    const year = new Date().getFullYear();

    // Группируем пункты меню по footer_group
    const companyLinks = footerMenuItems.filter((item) => item.footer_group === 'company');
    const buyerLinks = footerMenuItems.filter((item) => item.footer_group === 'buyers');
    const legalLinks = footerMenuItems.filter((item) => item.footer_group === 'legal');

    const renderLink = (item) => (
        <Link
            key={`${item.id}-${item.url}`}
            href={item.url}
            {...(item.open_in_new_tab ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        >
            <HStack gap="1.5">
                <Text
                    fontSize="sm"
                    color="white"
                    _hover={{ color: HOVER_ACCENT }}
                    transition="color 0.2s"
                >
                    {item.title}
                </Text>
                {item.badge_text && (
                    <Badge
                        size="xs"
                        variant="solid"
                        bg={item.badge_color || '#e53e3e'}
                        color="white"
                        borderRadius="full"
                        px="1.5"
                        fontSize="2xs"
                    >
                        {item.badge_text}
                    </Badge>
                )}
            </HStack>
        </Link>
    );

    return (
        <Box as="footer" mt="auto">
            {/* Рваный край: чёрная кромка футера, наезжает на контент выше */}
            <Box
                aria-hidden="true"
                h={{ base: '8px', lg: '14px' }}
                mt={{ base: '-8px', lg: '-14px' }}
                transform="translateY(1px)"
                bgImage="url('/images/torn-border-top.svg')"
                bgRepeat="repeat-x"
                bgSize="auto 100%"
                bgPosition="bottom"
                pointerEvents="none"
                position="relative"
            />

            <Box bg="#000" color="white">
                <Box maxW="1360px" mx="auto" px={{ base: '3', md: '6' }} py={{ base: '8', md: '10' }}>
                    {/* Columns */}
                    <Grid
                        templateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)', lg: 'repeat(5, 1fr)' }}
                        gap={{ base: '6', md: '8' }}
                        mb="8"
                    >
                        {/* Logo & Description */}
                        <GridItem>
                            <Box as="img" src="/images/logo.png" alt="Pecado" h="12" objectFit="contain" mb="4" />
                            <Text fontSize="sm" color="white" lineHeight="relaxed">
                                Зарабатывать на удовольствии — это не грех, это Pecado. Мы отобрали товары, перед которыми не устоит ваш клиент, и создали условия, от которых невозможно отказаться партнеру.
                            </Text>
                        </GridItem>

                        {/* Company */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="600" mb="4" color="white">
                                О компании
                            </Text>
                            <VStack align="start" gap="2">
                                {companyLinks.map(renderLink)}
                            </VStack>
                        </GridItem>

                        {/* For Buyers */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="600" mb="4" color="white">
                                Покупателям
                            </Text>
                            <VStack align="start" gap="2">
                                {buyerLinks.map(renderLink)}
                            </VStack>
                        </GridItem>

                        {/* Categories */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="600" mb="4" color="white">
                                Категории
                            </Text>
                            <VStack align="start" gap="2">
                                {footerCategories.map((item) => (
                                    <Link key={item.id} href={`/categories/${item.slug}`}>
                                        <Text
                                            fontSize="sm"
                                            color="white"
                                            _hover={{ color: HOVER_ACCENT }}
                                            transition="color 0.2s"
                                        >
                                            {item.name}
                                        </Text>
                                    </Link>
                                ))}
                            </VStack>
                        </GridItem>

                        {/* Legal */}
                        {legalLinks.length > 0 && (
                            <GridItem>
                                <Text fontSize="sm" fontWeight="600" mb="4" color="white">
                                    Правовая информация
                                </Text>
                                <VStack align="start" gap="2">
                                    {legalLinks.map(renderLink)}
                                </VStack>
                            </GridItem>
                        )}
                    </Grid>

                    {/* PWA Install Banner — только мобилки */}
                    <Box display={{ base: 'block', md: 'none' }} mb="6">
                        <PwaInstallBanner />
                    </Box>

                    {/* Bottom Row */}
                    <Flex
                        pt="6"
                        borderTop="1px solid"
                        borderColor="whiteAlpha.300"
                        align="center"
                        justify={{ base: 'center', sm: 'space-between' }}
                        direction={{ base: 'column-reverse', sm: 'row' }}
                        gap="4"
                    >
                        <Text fontSize="sm" color="white">
                            © {year} Pecado. Все права защищены. v0.1.1
                        </Text>
                        <HStack gap="1">
                            <EffectsToggleButton
                                inactiveColor="white"
                                _hover={{ color: HOVER_ACCENT, bg: 'whiteAlpha.200', _dark: { color: HOVER_ACCENT } }}
                            />
                            <ColorModeButton
                                aria-label="Переключить тему"
                                color="white"
                                _hover={{ color: HOVER_ACCENT, bg: 'whiteAlpha.200' }}
                            />
                        </HStack>
                    </Flex>
                </Box>
            </Box>
        </Box>
    );
}
