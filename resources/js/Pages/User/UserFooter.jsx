import {
    Box, Flex, Grid, GridItem, Text, VStack, HStack, Badge,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { ColorModeButton } from '@/components/ui/color-mode';

export default function UserFooter() {
    const { footerCategories = [], footerMenuItems = [] } = usePage().props;
    const year = new Date().getFullYear();

    // Группируем пункты меню по footer_group
    const companyLinks = footerMenuItems.filter((item) => item.footer_group === 'company');
    const buyerLinks = footerMenuItems.filter((item) => item.footer_group === 'buyers');

    const renderLink = (item) => (
        <Link
            key={`${item.id}-${item.url}`}
            href={item.url}
            {...(item.open_in_new_tab ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        >
            <HStack gap="1.5">
                <Text
                    fontSize="sm"
                    color="gray.600"
                    _dark={{ color: 'gray.400' }}
                    _hover={{ color: 'pecado.600', _dark: { color: 'pecado.400' } }}
                    transition="colors 0.2s"
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
            <Box
                bg="bg"
                borderTop="1px solid"
                borderColor="border"
                color="gray.700"
                _dark={{ bg: 'gray.900', borderColor: 'gray.800', color: 'gray.300' }}
            >
                <Box maxW="1360px" mx="auto" px={{ base: '4', md: '6' }} py={{ base: '8', md: '10' }}>
                    {/* Four Columns */}
                    <Grid
                        templateColumns={{ base: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)' }}
                        gap={{ base: '6', md: '8' }}
                        mb="8"
                    >
                        {/* Logo & Description */}
                        <GridItem>
                            <Box as="img" src="/images/logo.png" alt="Pecado" h="12" objectFit="contain" mb="4" />
                            <Text fontSize="sm" color="gray.500" _dark={{ color: 'gray.400' }} lineHeight="relaxed">
                                Зарабатывать на удовольствии — это не грех, это Pecado. Мы отобрали товары, перед которыми не устоит ваш клиент, и создали условия, от которых невозможно отказаться партнеру.
                            </Text>
                        </GridItem>

                        {/* Company */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="600" mb="4" color="gray.900" _dark={{ color: 'white' }}>
                                О компании
                            </Text>
                            <VStack align="start" gap="2">
                                {companyLinks.map(renderLink)}
                            </VStack>
                        </GridItem>

                        {/* For Buyers */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="600" mb="4" color="gray.900" _dark={{ color: 'white' }}>
                                Покупателям
                            </Text>
                            <VStack align="start" gap="2">
                                {buyerLinks.map(renderLink)}
                            </VStack>
                        </GridItem>

                        {/* Categories */}
                        <GridItem>
                            <Text fontSize="sm" fontWeight="600" mb="4" color="gray.900" _dark={{ color: 'white' }}>
                                Категории
                            </Text>
                            <VStack align="start" gap="2">
                                {footerCategories.map((item) => (
                                    <Link key={item.id} href={`/categories/${item.slug}`}>
                                        <Text
                                            fontSize="sm"
                                            color="gray.600"
                                            _dark={{ color: 'gray.400' }}
                                            _hover={{ color: 'pecado.600', _dark: { color: 'pecado.400' } }}
                                            transition="colors 0.2s"
                                        >
                                            {item.name}
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
                        borderColor="border"

                        align="center"
                        justify={{ base: 'center', sm: 'space-between' }}
                        direction={{ base: 'column-reverse', sm: 'row' }}
                        gap="4"
                    >
                        <Text fontSize="sm" color="gray.500" _dark={{ color: 'gray.400' }}>
                            © {year} Pecado. Все права защищены.
                        </Text>
                        <ColorModeButton aria-label="Переключить тему" />
                    </Flex>
                </Box>
            </Box>
        </Box>
    );
}
