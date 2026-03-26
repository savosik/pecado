import { Head, Link } from '@inertiajs/react';
import {
    Box,
    VStack,
    Heading,
    Text,
    Container,
    Circle,
    Flex,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuHouse, LuChevronLeft } from 'react-icons/lu';

const titles = {
    503: 'Сервис недоступен',
    500: 'Ошибка сервера',
    404: 'Страница не найдена',
    403: 'Доступ запрещён',
};

const descriptions = {
    503: 'Мы проводим технические работы. Пожалуйста, попробуйте позже.',
    500: 'Что-то пошло не так на нашей стороне. Мы уже работаем над этим.',
    404: 'К сожалению, запрашиваемая страница не существует или была перемещена.',
    403: 'У вас нет прав для доступа к этой странице.',
};

const emojis = {
    503: '🔧',
    500: '💥',
    404: '🔍',
    403: '🔒',
};

export default function ErrorPage({ status }) {
    const title = titles[status] || 'Ошибка';
    const description = descriptions[status] || 'Произошла непредвиденная ошибка.';
    const emoji = emojis[status] || '⚠️';

    return (
        <>
            <Head title={`${status} — ${title}`} />

            {/* CSS keyframes defined via style tag */}
            <style>{`
                @keyframes error-float {
                    0%, 100% { transform: translateY(0px) rotate(0deg); }
                    25% { transform: translateY(-20px) rotate(2deg); }
                    50% { transform: translateY(-10px) rotate(-1deg); }
                    75% { transform: translateY(-25px) rotate(1deg); }
                }
                @keyframes error-pulse {
                    0%, 100% { opacity: 0.15; transform: scale(1); }
                    50% { opacity: 0.25; transform: scale(1.05); }
                }
                @keyframes error-fade-in-up {
                    from { opacity: 0; transform: translateY(30px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            `}</style>

            <Box
                minH="100vh"
                bg="bg"
                color="fg"
                position="relative"
                overflow="hidden"
                display="flex"
                alignItems="center"
                justifyContent="center"
            >
                {/* Background decorative circles */}
                <Circle
                    size="600px"
                    bg="pecado.500"
                    filter="blur(150px)"
                    opacity={{ base: '0.06', _dark: '0.12' }}
                    position="absolute"
                    top="-200px"
                    right="-150px"
                    css={{ animation: 'error-pulse 6s ease-in-out infinite' }}
                />
                <Circle
                    size="500px"
                    bg="purple.500"
                    filter="blur(130px)"
                    opacity={{ base: '0.04', _dark: '0.08' }}
                    position="absolute"
                    bottom="-150px"
                    left="-100px"
                    css={{ animation: 'error-pulse 8s ease-in-out infinite 2s' }}
                />
                <Circle
                    size="300px"
                    bg="champagne.400"
                    filter="blur(100px)"
                    opacity={{ base: '0.05', _dark: '0.06' }}
                    position="absolute"
                    top="40%"
                    left="60%"
                    css={{ animation: 'error-pulse 7s ease-in-out infinite 1s' }}
                />

                <Container maxW="lg" position="relative" zIndex="1" py="20">
                    <VStack gap="8" textAlign="center">
                        {/* Logo */}
                        <Box
                            as={Link}
                            href="/"
                            css={{ animation: 'error-fade-in-up 0.6s ease-out both' }}
                        >
                            <Box
                                as="img"
                                src="/images/logo.png"
                                alt="Pecado"
                                h="12"
                                objectFit="contain"
                                opacity="0.85"
                                _hover={{ opacity: 1 }}
                                transition="opacity 0.2s"
                            />
                        </Box>

                        {/* Floating emoji */}
                        <Box
                            fontSize="6xl"
                            css={{ animation: 'error-float 5s ease-in-out infinite' }}
                        >
                            {emoji}
                        </Box>

                        {/* Error code with gradient */}
                        <Box css={{ animation: 'error-fade-in-up 0.6s ease-out' }}>
                            <Heading
                                as="h1"
                                fontSize={{ base: '8xl', md: '9xl' }}
                                fontWeight="black"
                                letterSpacing="tight"
                                lineHeight="1"
                            >
                                <Text
                                    as="span"
                                    bgGradient="to-r"
                                    gradientFrom="pecado.400"
                                    gradientVia="pecado.600"
                                    gradientTo="champagne.500"
                                    bgClip="text"
                                >
                                    {status}
                                </Text>
                            </Heading>
                        </Box>

                        {/* Title */}
                        <Box css={{ animation: 'error-fade-in-up 0.6s ease-out 0.15s both' }}>
                            <Heading
                                as="h2"
                                fontSize={{ base: '2xl', md: '3xl' }}
                                fontWeight="bold"
                                color="fg"
                            >
                                {title}
                            </Heading>
                        </Box>

                        {/* Description */}
                        <Box css={{ animation: 'error-fade-in-up 0.6s ease-out 0.3s both' }}>
                            <Text
                                fontSize={{ base: 'md', md: 'lg' }}
                                color="fg.muted"
                                maxW="md"
                                mx="auto"
                                lineHeight="tall"
                            >
                                {description}
                            </Text>
                        </Box>

                        {/* Decorative divider */}
                        <Box
                            w="80px"
                            h="3px"
                            borderRadius="full"
                            bgGradient="to-r"
                            gradientFrom="pecado.400"
                            gradientTo="champagne.400"
                            css={{ animation: 'error-fade-in-up 0.6s ease-out 0.4s both' }}
                        />

                        {/* Action buttons */}
                        <Flex
                            gap="4"
                            flexWrap="wrap"
                            justify="center"
                            css={{ animation: 'error-fade-in-up 0.6s ease-out 0.5s both' }}
                        >
                            <Button
                                as={Link}
                                href="/"
                                size="lg"
                                bg="pecado.600"
                                color="white"
                                fontWeight="bold"
                                _hover={{ bg: 'pecado.700', transform: 'translateY(-2px)' }}
                                borderRadius="full"
                                px="8"
                                transition="all 0.2s"
                                shadow="md"
                            >
                                <LuHouse />
                                На главную
                            </Button>
                            <Button
                                size="lg"
                                variant="outline"
                                color="fg"
                                borderColor="border"
                                _hover={{
                                    bg: { base: 'sand.100', _dark: 'whiteAlpha.100' },
                                    transform: 'translateY(-2px)',
                                }}
                                borderRadius="full"
                                px="8"
                                transition="all 0.2s"
                                onClick={() => window.history.back()}
                            >
                                <LuChevronLeft />
                                Назад
                            </Button>
                        </Flex>
                    </VStack>
                </Container>
            </Box>
        </>
    );
}
