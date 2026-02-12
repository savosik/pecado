import {
    Box, Flex, Grid, GridItem, HStack, Text, VStack, IconButton,
} from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuFacebook, LuInstagram, LuTwitter } from 'react-icons/lu';

const companyLinks = [
    { href: '/about', label: 'О компании' },
    { href: '/careers', label: 'Карьера' },
    { href: '/news', label: 'Новости' },
    { href: '/articles', label: 'Статьи' },
];

const buyerLinks = [
    { href: '/faq', label: 'FAQ' },
    { href: '/where-to-buy', label: 'Где купить' },
    { href: '/promotions', label: 'Акции' },
];

const categoryLinks = [
    { href: '/products?category=1', label: 'Вибраторы' },
    { href: '/products?category=2', label: 'Фаллоимитаторы' },
    { href: '/products?category=3', label: 'Белье' },
    { href: '/products?category=4', label: 'Косметика' },
    { href: '/products?category=5', label: 'Аксессуары' },
];

export default function UserFooter() {
    const year = new Date().getFullYear();

    return (
        <Box as="footer">
            {/* Золотая полоска сверху */}
            <Box
                h="3px"
                bg="linear-gradient(90deg, #8B6914 0%, #C5A028 20%, #FFD700 40%, #FAEBD7 50%, #FFD700 60%, #C5A028 80%, #8B6914 100%)"
                boxShadow="0 0 8px rgba(255, 215, 0, 0.4), 0 1px 3px rgba(0, 0, 0, 0.3)"
            />
            <Box
                bg="linear-gradient(135deg, #1a0508 0%, #4a0d19 50%, #2a0810 100%)"
                color="white"
            >
                <Box maxW="1360px" mx="auto" px={{ base: '4', md: '6' }} py="10">
                    {/* Four Columns */}
                    <Grid
                        templateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)' }}
                        gap="8"
                        mb="8"
                    >
                        {/* Logo & Description */}
                        <GridItem>
                            <Box as="img" src="/images/logo.png" alt="Pecado" h="16" objectFit="contain" mb="4" filter="brightness(0) invert(1)" />
                            <Text fontSize="sm" color="rgba(255,255,255,0.7)" lineHeight="relaxed">
                                Зарабатывать на удовольствии — это не грех, это Pecado. Мы отобрали товары, перед которыми не устоит ваш клиент, и создали условия, от которых невозможно отказаться партнеру.
                            </Text>
                        </GridItem>

                        {/* Company */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="700" mb="4" textTransform="uppercase" letterSpacing="0.05em">
                                О компании
                            </Text>
                            <VStack align="start" gap="2">
                                {companyLinks.map((item) => (
                                    <Link key={item.href} href={item.href}>
                                        <Text
                                            fontSize="sm"
                                            color="rgba(255,255,255,0.7)"
                                            _hover={{ color: 'white' }}
                                            transition="colors 0.2s"
                                        >
                                            {item.label}
                                        </Text>
                                    </Link>
                                ))}
                            </VStack>
                        </GridItem>

                        {/* For Buyers */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="700" mb="4" textTransform="uppercase" letterSpacing="0.05em">
                                Покупателям
                            </Text>
                            <VStack align="start" gap="2">
                                {buyerLinks.map((item) => (
                                    <Link key={item.href} href={item.href}>
                                        <Text
                                            fontSize="sm"
                                            color="gray.400"
                                            _hover={{ color: 'white' }}
                                            transition="colors 0.2s"
                                        >
                                            {item.label}
                                        </Text>
                                    </Link>
                                ))}
                            </VStack>
                        </GridItem>

                        {/* Categories */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="700" mb="4" textTransform="uppercase" letterSpacing="0.05em">
                                Категории
                            </Text>
                            <VStack align="start" gap="2">
                                {categoryLinks.map((item) => (
                                    <Link key={item.href} href={item.href}>
                                        <Text
                                            fontSize="sm"
                                            color="gray.400"
                                            _hover={{ color: 'white' }}
                                            transition="colors 0.2s"
                                        >
                                            {item.label}
                                        </Text>
                                    </Link>
                                ))}
                            </VStack>
                        </GridItem>
                    </Grid>

                    {/* Bottom Row */}
                    <Flex
                        pt="6"
                        borderTop="1px solid"
                        borderColor="rgba(255,255,255,0.15)"
                        direction={{ base: 'column', sm: 'row' }}
                        align="center"
                        justify="space-between"
                        gap="4"
                    >
                        <Text fontSize="sm" color="rgba(255,255,255,0.5)">
                            © {year} Pecado. Все права защищены.
                        </Text>
                        <HStack gap="2">
                            <IconButton
                                as="a"
                                href="#"
                                aria-label="Facebook"
                                variant="ghost"
                                colorPalette="gray"
                                color="#b0b0b0"
                                _hover={{ color: 'white' }}
                                size="sm"
                            >
                                <LuFacebook />
                            </IconButton>
                            <IconButton
                                as="a"
                                href="#"
                                aria-label="Instagram"
                                variant="ghost"
                                colorPalette="gray"
                                color="gray.400"
                                _hover={{ color: 'white' }}
                                size="sm"
                            >
                                <LuInstagram />
                            </IconButton>
                            <IconButton
                                as="a"
                                href="#"
                                aria-label="Twitter"
                                variant="ghost"
                                colorPalette="gray"
                                color="gray.400"
                                _hover={{ color: 'white' }}
                                size="sm"
                            >
                                <LuTwitter />
                            </IconButton>
                        </HStack>
                    </Flex>
                </Box>
            </Box>
        </Box>
    );
}
