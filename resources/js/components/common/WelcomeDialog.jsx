import { useState } from 'react';
import { Box, Flex, Text, Button, VStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

export default function WelcomeDialog({ userName }) {
    const [open, setOpen] = useState(true);

    if (!open) return null;

    const displayName = userName || 'Партнёр';

    return (
        <Box
            position="fixed"
            inset="0"
            zIndex="9999"
            display="flex"
            alignItems="center"
            justifyContent="center"
        >
            {/* Backdrop */}
            <Box
                position="absolute"
                inset="0"
                bg="blackAlpha.700"
                backdropFilter="blur(8px)"
                onClick={() => setOpen(false)}
            />

            {/* Dialog Card */}
            <Box
                position="relative"
                w={{ base: '92vw', sm: '480px' }}
                maxW="480px"
                borderRadius="2xl"
                overflow="hidden"
                boxShadow="0 25px 50px rgba(0,0,0,0.5)"
                animation="fadeInUp 0.5s ease"
            >
                {/* Hero Background Image */}
                <Box position="relative" h="260px" overflow="hidden">
                    <Box
                        as="img"
                        src="/images/welcome-bg.png"
                        alt=""
                        position="absolute"
                        inset="0"
                        w="100%"
                        h="100%"
                        objectFit="cover"
                    />
                    {/* Gradient overlay for text readability */}
                    <Box
                        position="absolute"
                        inset="0"
                        bg="linear-gradient(180deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.6) 100%)"
                    />
                </Box>

                {/* Content */}
                <Box
                    bg="white"
                    px={{ base: 6, sm: 8 }}
                    py={8}
                >
                    <VStack gap={3} align="center" textAlign="center">
                        <Text
                            fontSize="2xl"
                            fontWeight="bold"
                            color="gray.900"
                            letterSpacing="-0.02em"
                        >
                            Добро пожаловать, {displayName}! 🎉
                        </Text>
                        <Text
                            fontSize="md"
                            color="gray.500"
                            lineHeight="1.6"
                            maxW="360px"
                        >
                            Спасибо за регистрацию! Мы рады приветствовать Вас на нашем сайте.
                        </Text>
                        <Text
                            fontSize="sm"
                            color="gray.400"
                            lineHeight="1.5"
                        >
                            Изучите каталог, добавьте товары в корзину и оформите первый заказ.
                        </Text>

                        <Flex gap={3} mt={4} w="100%" direction={{ base: 'column', sm: 'row' }} justify="center">
                            <Link href="/catalog">
                                <Button
                                    bg="#9e1b32"
                                    color="white"
                                    size="lg"
                                    borderRadius="xl"
                                    fontWeight="semibold"
                                    fontSize="sm"
                                    px={8}
                                    w={{ base: '100%', sm: 'auto' }}
                                    _hover={{ bg: '#7a1527' }}
                                    transition="all 0.2s ease"
                                >
                                    Перейти в каталог
                                </Button>
                            </Link>
                            <Button
                                variant="outline"
                                borderColor="gray.300"
                                color="gray.600"
                                size="lg"
                                borderRadius="xl"
                                fontWeight="medium"
                                fontSize="sm"
                                px={6}
                                w={{ base: '100%', sm: 'auto' }}
                                _hover={{ bg: 'gray.50' }}
                                onClick={() => setOpen(false)}
                            >
                                Закрыть
                            </Button>
                        </Flex>
                    </VStack>
                </Box>
            </Box>

            {/* CSS Animation */}
            <style>{`
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(30px) scale(0.95);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }
            `}</style>
        </Box>
    );
}
