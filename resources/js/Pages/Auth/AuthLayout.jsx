import { Box, Container, Heading, VStack, Text, Separator } from '@chakra-ui/react';
import { Toaster } from '@/components/ui/toaster';

export default function AuthLayout({ children, title, subtitle }) {
    return (
        <>
            <Toaster />

            <Box
                minH="100vh"
                display="flex"
                alignItems="center"
                justifyContent="center"
                position="relative"
                overflow="hidden"
                bg="linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%)"
            >
                {/* Animated gradient orbs */}
                <Box
                    position="absolute"
                    top="-20%"
                    left="-10%"
                    w="500px"
                    h="500px"
                    borderRadius="full"
                    bg="linear-gradient(135deg, rgba(139, 92, 246, 0.3), rgba(236, 72, 153, 0.2))"
                    filter="blur(80px)"
                    animation="float 8s ease-in-out infinite"
                />
                <Box
                    position="absolute"
                    bottom="-15%"
                    right="-10%"
                    w="400px"
                    h="400px"
                    borderRadius="full"
                    bg="linear-gradient(135deg, rgba(59, 130, 246, 0.3), rgba(16, 185, 129, 0.2))"
                    filter="blur(80px)"
                    animation="float 6s ease-in-out infinite reverse"
                />
                <Box
                    position="absolute"
                    top="40%"
                    left="60%"
                    w="300px"
                    h="300px"
                    borderRadius="full"
                    bg="linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(239, 68, 68, 0.15))"
                    filter="blur(60px)"
                    animation="float 10s ease-in-out infinite"
                />

                <Container maxW="md" position="relative" zIndex={1} py={8}>
                    {/* Glass card */}
                    <Box
                        bg="rgba(255, 255, 255, 0.08)"
                        backdropFilter="blur(20px) saturate(180%)"
                        borderRadius="2xl"
                        border="1px solid rgba(255, 255, 255, 0.15)"
                        p={{ base: 6, sm: 10 }}
                        boxShadow="0 8px 32px rgba(0, 0, 0, 0.37)"
                        transition="all 0.3s ease"
                        _hover={{
                            boxShadow: "0 12px 40px rgba(0, 0, 0, 0.45)",
                            border: "1px solid rgba(255, 255, 255, 0.2)",
                        }}
                    >
                        {/* Header */}
                        <VStack gap={2} align="start" mb={6}>
                            <Heading
                                size="2xl"
                                color="white"
                                fontWeight="bold"
                                letterSpacing="-0.02em"
                            >
                                {title}
                            </Heading>
                            {subtitle && (
                                <Text color="rgba(255, 255, 255, 0.7)" fontSize="md">
                                    {subtitle}
                                </Text>
                            )}
                        </VStack>

                        {children}
                    </Box>

                    {/* Brand footer */}
                    <Box mt={6} textAlign="center">
                        <Text fontSize="sm" color="rgba(255, 255, 255, 0.4)" fontWeight="medium">
                            Pecado
                        </Text>
                    </Box>
                </Container>
            </Box>

            {/* Keyframe animation */}
            <style>{`
                @keyframes float {
                    0%, 100% { transform: translate(0, 0) scale(1); }
                    33% { transform: translate(30px, -30px) scale(1.05); }
                    66% { transform: translate(-20px, 20px) scale(0.95); }
                }
            `}</style>
        </>
    );
}
