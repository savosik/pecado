import { Accordion, HStack, Text, Icon, VStack } from "@chakra-ui/react";
import { Link } from "@inertiajs/react";
import { menuConfig } from "../config/menuConfig";
import { useNavigation } from "../hooks/useNavigation";

/**
 * NavigationMenu — переиспользуемый компонент навигации
 * Используется в Sidebar (десктоп) и MobileSidebar (мобильный Drawer)
 *
 * @param {Function} onItemClick - Callback при клике на пункт меню (для закрытия Drawer)
 * @param {boolean} isCollapsed - Свёрнут ли сайдбар (только для десктопа)
 */
export const NavigationMenu = ({ onItemClick, isCollapsed = false }) => {
    const { isActive, getActiveGroups } = useNavigation();

    return (
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
                                    <Link
                                        key={item.path}
                                        href={item.path}
                                        onClick={onItemClick}
                                    >
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
    );
};
