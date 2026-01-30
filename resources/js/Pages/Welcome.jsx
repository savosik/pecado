import { Head, Link } from '@inertiajs/react';
import {
    Box,
    VStack,
    HStack,
    Heading,
    Text,
    Container,
    Flex,
    Circle,
    Icon,
    Tabs
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { ColorModeButton } from '@/components/ui/color-mode';
import { LuRocket, LuDatabase, LuBox, LuZap, LuShieldCheck, LuSettings } from 'react-icons/lu';

export default function Welcome({ laravelVersion, phpVersion }) {
    return (
        <>
            <Head title="Welcome to Pecado" />
            <Box
                minH="100vh"
                bg="bg.canvas"
                bgGradient="to-br"
                gradientFrom={{ base: "gray.50", _dark: "slate.900" }}
                gradientVia={{ base: "purple.50", _dark: "purple.900" }}
                gradientTo={{ base: "gray.50", _dark: "slate.900" }}
                color={{ base: "gray.800", _dark: "white" }}
                position="relative"
                overflow="hidden"
            >
                {/* Background Decoration */}
                <Circle
                    size="500px"
                    bg="purple.500"
                    filter="blur(120px)"
                    opacity={{ base: "0.05", _dark: "0.15" }}
                    position="absolute"
                    top="-100px"
                    right="-100px"
                />
                <Circle
                    size="400px"
                    bg="indigo.500"
                    filter="blur(100px)"
                    opacity={{ base: "0.05", _dark: "0.1" }}
                    position="absolute"
                    bottom="-50px"
                    left="-50px"
                />

                <Container maxW="3xl" pt="20" position="relative" zIndex="1">
                    <Flex justify="flex-end" mb="8">
                        <ColorModeButton />
                    </Flex>

                    <VStack gap="8" textAlign="center">
                        <VStack gap="2">
                            <Heading
                                as="h1"
                                size="7xl"
                                fontWeight="black"
                                letterSpacing="tight"
                            >
                                <Text
                                    as="span"
                                    bgGradient="to-r"
                                    gradientFrom="pink.400"
                                    gradientVia="purple.400"
                                    gradientTo="indigo.400"
                                    bgClip="text"
                                >
                                    Pecado
                                </Text>
                            </Heading>
                            <Text fontSize="xl" color="fg.muted" fontWeight="medium">
                                The Modern Laravel + React + Chakra UI Template
                            </Text>
                        </VStack>

                        <HStack gap="4" flexWrap="wrap" justify="center">
                            <Box
                                bg={{ base: "white", _dark: "whiteAlpha.100" }}
                                backdropBlur="md"
                                shadow={{ base: "md", _dark: "none" }}
                                p="6"
                                borderRadius="2xl"
                                border="1px solid"
                                borderColor={{ base: "gray.200", _dark: "whiteAlpha.200" }}
                                minW="140px"
                            >
                                <VStack gap="1">
                                    <Text fontWeight="bold" fontSize="2xl">Laravel</Text>
                                    <Text color="whiteAlpha.600">{laravelVersion}</Text>
                                </VStack>
                            </Box>
                            <Box
                                bg={{ base: "white", _dark: "whiteAlpha.100" }}
                                backdropBlur="md"
                                shadow={{ base: "md", _dark: "none" }}
                                p="6"
                                borderRadius="2xl"
                                border="1px solid"
                                borderColor={{ base: "gray.200", _dark: "whiteAlpha.200" }}
                                minW="140px"
                            >
                                <VStack gap="1">
                                    <Text fontWeight="bold" fontSize="2xl">PHP</Text>
                                    <Text color="fg.muted">{phpVersion}</Text>
                                </VStack>
                            </Box>
                            <Box
                                bg={{ base: "white", _dark: "whiteAlpha.100" }}
                                backdropBlur="md"
                                shadow={{ base: "md", _dark: "none" }}
                                p="6"
                                borderRadius="2xl"
                                border="1px solid"
                                borderColor={{ base: "gray.200", _dark: "whiteAlpha.200" }}
                                minW="140px"
                            >
                                <VStack gap="1">
                                    <Text fontWeight="bold" fontSize="2xl">React</Text>
                                    <Text color="whiteAlpha.600">19</Text>
                                </VStack>
                            </Box>
                        </HStack>

                        <Flex gap="3" flexWrap="wrap" justify="center">
                            <FeatureBadge icon={LuZap} color="pink.400">Chakra UI v3</FeatureBadge>
                            <FeatureBadge icon={LuRocket} color="cyan.400">Inertia.js</FeatureBadge>
                            <FeatureBadge icon={LuBox} color="purple.400">Tailwind CSS</FeatureBadge>
                            <FeatureBadge icon={LuDatabase} color="blue.400">Meilisearch</FeatureBadge>
                        </Flex>

                        <Box w="full" maxW="xl" mt="4">
                            <Tabs.Root defaultValue="features" variant="outline" colorPalette="purple">
                                <Tabs.List bg={{ base: "gray.100", _dark: "whiteAlpha.50" }} p="1" borderRadius="lg">
                                    <Tabs.Trigger value="features" gap="2" flex="1">
                                        <LuZap size={16} />
                                        Features
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="security" gap="2" flex="1">
                                        <LuShieldCheck size={16} />
                                        Security
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="config" gap="2" flex="1">
                                        <LuSettings size={16} />
                                        Configuration
                                    </Tabs.Trigger>
                                </Tabs.List>
                                <Tabs.Content value="features" pt="6">
                                    <Box textAlign="left" bg={{ base: "white", _dark: "whiteAlpha.100" }} p="6" borderRadius="2xl" border="1px solid" borderColor={{ base: "gray.200", _dark: "whiteAlpha.200" }}>
                                        <Text fontSize="lg" fontWeight="bold" mb="2">Rich Component Library</Text>
                                        <Text color="fg.muted">Access a comprehensive set of accessible, reusable, and composable React components designed for speed and beauty.</Text>
                                    </Box>
                                </Tabs.Content>
                                <Tabs.Content value="security" pt="6">
                                    <Box textAlign="left" bg="whiteAlpha.100" p="6" borderRadius="2xl" border="1px solid" borderColor="whiteAlpha.200">
                                        <Text fontSize="lg" fontWeight="bold" mb="2">Secure by Default</Text>
                                        <Text color="whiteAlpha.700">Built-in protections against common vulnerabilities, ensuring your admin panel stays safe and reliable.</Text>
                                    </Box>
                                </Tabs.Content>
                                <Tabs.Content value="config" pt="6">
                                    <Box textAlign="left" bg="whiteAlpha.100" p="6" borderRadius="2xl" border="1px solid" borderColor="whiteAlpha.200">
                                        <Text fontSize="lg" fontWeight="bold" mb="2">Flexible Configuration</Text>
                                        <Text color="whiteAlpha.700">Easily customize the theme, colors, and layout to match your brand's unique identity with powerful JSS tokens.</Text>
                                    </Box>
                                </Tabs.Content>
                            </Tabs.Root>
                        </Box>

                        <HStack gap="4" mt="4">
                            <Button
                                size="lg"
                                bg={{ base: "purple.600", _dark: "white" }}
                                color={{ base: "white", _dark: "purple.900" }}
                                fontWeight="bold"
                                _hover={{ bg: { base: "purple.700", _dark: "whiteAlpha.800" } }}
                                borderRadius="full"
                                px="8"
                            >
                                Get Started
                            </Button>
                            <Button
                                size="lg"
                                variant="outline"
                                color={{ base: "purple.600", _dark: "white" }}
                                borderColor={{ base: "purple.200", _dark: "whiteAlpha.400" }}
                                _hover={{ bg: { base: "purple.50", _dark: "whiteAlpha.100" } }}
                                borderRadius="full"
                                px="8"
                            >
                                Documentation
                            </Button>
                        </HStack>
                    </VStack>
                </Container>
            </Box>
        </>
    );
}

function FeatureBadge({ children, icon: IconComponent, color }) {
    return (
        <HStack
            bg={`${color}22`}
            color={color}
            px="4"
            py="2"
            borderRadius="full"
            fontSize="sm"
            fontWeight="bold"
            border="1px solid"
            borderColor={`${color}44`}
            gap="2"
        >
            <IconComponent size={14} />
            <Text>{children}</Text>
        </HStack>
    );
}
