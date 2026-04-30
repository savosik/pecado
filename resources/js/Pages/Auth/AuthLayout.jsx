import { Box, Container, Heading, VStack, Text, Flex } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { Toaster } from '@/components/ui/toaster';

export default function AuthLayout({ children, title, subtitle, image = '/images/auth-hero-v2.png' }) {
    return (
        <>
            <Toaster />
            <Flex minH="100vh">
                {/* Left — Form */}
                <Flex
                    flex="1"
                    direction="column"
                    justify="center"
                    bg="bg"
                    px={{ base: 6, sm: 10, lg: 16 }}
                    py={10}
                    overflowY="auto"
                >
                    <Box maxW="420px" w="100%" mx="auto">
                        {/* Logo */}
                        <Box mb={8}>
                            <Link href="/">
                                <Box
                                    as="img"
                                    src="/images/logo.png"
                                    alt="Pecado"
                                    h="36px"
                                    objectFit="contain"
                                />
                            </Link>
                        </Box>

                        {/* Header */}
                        <VStack gap={1} align="start" mb={8}>
                            <Heading
                                size="2xl"
                                color="fg"
                                fontWeight="bold"
                                letterSpacing="-0.02em"
                            >
                                {title}
                            </Heading>
                            {subtitle && (
                                <Text color="fg.muted" fontSize="md" mt={1}>
                                    {subtitle}
                                </Text>
                            )}
                        </VStack>

                        {children}
                    </Box>
                </Flex>

                {/* Right — Image (hidden on mobile) */}
                <Box
                    display={{ base: 'none', lg: 'block' }}
                    flex="1"
                    position="relative"
                    overflow="hidden"
                >
                    <Box
                        as="img"
                        src={image}
                        alt=""
                        position="absolute"
                        inset="0"
                        w="100%"
                        h="100%"
                        objectFit="cover"
                    />
                    {/* Subtle brand overlay */}
                    <Box
                        position="absolute"
                        inset="0"
                        bg="linear-gradient(180deg, rgba(158,27,50,0.08) 0%, rgba(158,27,50,0.15) 100%)"
                    />
                </Box>
            </Flex>
        </>
    );
}
