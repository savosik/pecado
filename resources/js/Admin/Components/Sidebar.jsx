import { Box, Accordion, HStack, Text, Icon, VStack } from "@chakra-ui/react";
import { Link, usePage } from "@inertiajs/react";
import { menuConfig } from "../config/menuConfig";

export const Sidebar = ({ isCollapsed = false }) => {
    const { url } = usePage();

    const isActive = (path) => {
        if (path === "/admin") {
            return url === "/admin" || url === "/admin/";
        }
        return url.startsWith(path);
    };

    return (
        <Box
            as="nav"
            position="fixed"
            left={0}
            top={0}
            h="100vh"
            w={isCollapsed ? "16" : "64"}
            bg="bg.panel"
            borderRightWidth="1px"
            borderColor="border.muted"
            py={4}
            overflowY="auto"
            transition="width 0.2s"
            display={{ base: "none", md: "block" }}
            zIndex={10}
        >
            {/* Логотип */}
            <Box px={4} mb={6}>
                <Text fontSize="xl" fontWeight="bold" color="fg.default">
                    {isCollapsed ? "P" : "Pecado Admin"}
                </Text>
            </Box>

            {/* Навигация */}
            <Accordion.Root collapsible multiple defaultValue={["Каталог", "Контент"]}>
                {menuConfig.map((group) => (
                    <Accordion.Item key={group.title} value={group.title}>
                        <Accordion.ItemTrigger
                            px={4}
                            py={2}
                            _hover={{ bg: "bg.muted" }}
                            cursor="pointer"
                        >
                            <HStack flex="1" gap={3}>
                                <Icon as={group.icon} boxSize={5} color="fg.muted" />
                                {!isCollapsed && (
                                    <Text fontSize="sm" fontWeight="medium" color="fg.default">
                                        {group.title}
                                    </Text>
                                )}
                            </HStack>
                            {!isCollapsed && <Accordion.ItemIndicator />}
                        </Accordion.ItemTrigger>

                        <Accordion.ItemContent>
                            <Accordion.ItemBody>
                                <VStack align="stretch" gap={0} pl={isCollapsed ? 0 : 4}>
                                    {group.items.map((item) => (
                                        <Link key={item.path} href={item.path}>
                                            <HStack
                                                px={4}
                                                py={2}
                                                gap={3}
                                                bg={isActive(item.path) ? "bg.emphasized" : "transparent"}
                                                color={isActive(item.path) ? "fg.default" : "fg.muted"}
                                                borderRadius="md"
                                                _hover={{ bg: "bg.muted", color: "fg.default" }}
                                                transition="all 0.2s"
                                            >
                                                <Icon as={item.icon} boxSize={4} />
                                                {!isCollapsed && (
                                                    <Text fontSize="sm">{item.label}</Text>
                                                )}
                                            </HStack>
                                        </Link>
                                    ))}
                                </VStack>
                            </Accordion.ItemBody>
                        </Accordion.ItemContent>
                    </Accordion.Item>
                ))}
            </Accordion.Root>
        </Box>
    );
};
