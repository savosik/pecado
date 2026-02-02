import { Drawer, Accordion, HStack, Text, Icon, VStack, Box } from "@chakra-ui/react";
import { Link, usePage } from "@inertiajs/react";
import { menuConfig } from "../config/menuConfig";

export const MobileSidebar = ({ isOpen, onClose }) => {
    const { url } = usePage();

    const isActive = (path) => {
        if (path === "/admin") {
            return url === "/admin" || url === "/admin/";
        }
        return url.startsWith(path);
    };

    // Определяем активную группу меню на основе текущего URL
    const getActiveGroups = () => {
        const activeGroups = [];
        for (const group of menuConfig) {
            for (const item of group.items) {
                if (isActive(item.path)) {
                    activeGroups.push(group.title);
                    break;
                }
            }
        }
        return activeGroups.length > 0 ? activeGroups : ["Главная"];
    };

    return (
        <Drawer.Root open={isOpen} onOpenChange={(e) => !e.open && onClose()} placement="start">
            <Drawer.Backdrop />
            <Drawer.Positioner>
                <Drawer.Content>
                    <Drawer.Header>
                        <Drawer.Title>
                            <Text fontSize="xl" fontWeight="bold" color="fg.default">
                                Pecado Admin
                            </Text>
                        </Drawer.Title>
                        <Drawer.CloseTrigger />
                    </Drawer.Header>
                    <Drawer.Body>
                        <Accordion.Root collapsible multiple defaultValue={getActiveGroups()}>
                            {menuConfig.map((group) => (
                                <Accordion.Item key={group.title} value={group.title}>
                                    <Accordion.ItemTrigger
                                        px={2}
                                        py={2}
                                        _hover={{ bg: "bg.muted" }}
                                        cursor="pointer"
                                    >
                                        <HStack flex="1" gap={3}>
                                            <Icon as={group.icon} boxSize={5} color="fg.muted" />
                                            <Text fontSize="sm" fontWeight="medium" color="fg.default">
                                                {group.title}
                                            </Text>
                                        </HStack>
                                        <Accordion.ItemIndicator />
                                    </Accordion.ItemTrigger>

                                    <Accordion.ItemContent>
                                        <Accordion.ItemBody>
                                            <VStack align="stretch" gap={0} pl={4}>
                                                {group.items.map((item) => (
                                                    <Link key={item.path} href={item.path} onClick={onClose}>
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
                                                            <Text fontSize="sm">{item.label}</Text>
                                                        </HStack>
                                                    </Link>
                                                ))}
                                            </VStack>
                                        </Accordion.ItemBody>
                                    </Accordion.ItemContent>
                                </Accordion.Item>
                            ))}
                        </Accordion.Root>
                    </Drawer.Body>
                </Drawer.Content>
            </Drawer.Positioner>
        </Drawer.Root>
    );
};
