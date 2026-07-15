import { Accordion, HStack, Text, Icon, VStack } from "@chakra-ui/react";
import { Link } from "@inertiajs/react";
import { menuConfig } from "../config/menuConfig";
import { useNavigation } from "../hooks/useNavigation";
import { usePermission } from "@/Admin/hooks/usePermission";

/**
 * NavigationMenu — навигация CRM. Фильтрует пункты по правам пользователя.
 *
 * usePermission переиспользуем из Admin: он доменно-нейтрален и читает
 * auth.user, который обе панели собирают через SharesPanelAuth.
 */
export const NavigationMenu = ({ onItemClick, isCollapsed = false }) => {
    const { isActive, getActiveGroups } = useNavigation();
    const { can } = usePermission();

    const filteredGroups = menuConfig
        .map((group) => ({
            ...group,
            items: group.items.filter(item => !item.permission || can(item.permission)),
        }))
        .filter((group) => group.items.length > 0);

    return (
        <Accordion.Root collapsible multiple defaultValue={getActiveGroups()}>
            {filteredGroups.map((group) => (
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
                                <Text fontSize="sm" fontWeight="medium" color="fg">
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
                                            color={isActive(item.path) ? "fg" : "fg.muted"}
                                            borderRadius="md"
                                            _hover={{ bg: "bg.muted", color: "fg" }}
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
