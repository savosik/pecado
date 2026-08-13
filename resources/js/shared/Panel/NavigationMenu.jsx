import { Accordion, HStack, Text, Icon, VStack } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { usePanel } from './PanelContext';
import { useNavigation } from './useNavigation';
import { usePermission } from './usePermission';

/**
 * NavigationMenu — навигация панели. Фильтрует пункты по правам пользователя:
 * пункт без права виден всем, группа без доступных пунктов скрывается целиком.
 *
 * Кроме права пункт может требовать включённой фичи (`feature`): ключ ищется
 * в `config` из общих пропсов Inertia. Право отвечает на вопрос «можно ли этому
 * сотруднику», фича — «работает ли раздел вообще»; смешивать их в одном признаке
 * значит выдавать право там, где нужен рубильник.
 */
export const NavigationMenu = ({ onItemClick, isCollapsed = false }) => {
    const { menuConfig } = usePanel();
    const { isActive, getActiveGroups } = useNavigation();
    const { can } = usePermission();
    const { config = {} } = usePage().props;

    const filteredGroups = menuConfig
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => (
                (!item.permission || can(item.permission))
                && (!item.feature || Boolean(config[item.feature]))
            )),
        }))
        .filter((group) => group.items.length > 0);

    return (
        <Accordion.Root collapsible multiple defaultValue={getActiveGroups()}>
            {filteredGroups.map((group) => (
                <Accordion.Item key={group.title} value={group.title}>
                    <Accordion.ItemTrigger
                        px={2}
                        // На телефоне пункты выше: в 32px пальцем не попасть.
                        py={{ base: 3, md: 2 }}
                        _hover={{ bg: 'bg.muted' }}
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
                                    <Link key={item.path} href={item.path} onClick={onItemClick}>
                                        <HStack
                                            px={4}
                                            py={{ base: 3, md: 2 }}
                                            gap={3}
                                            bg={isActive(item.path) ? 'bg.emphasized' : 'transparent'}
                                            color={isActive(item.path) ? 'fg' : 'fg.muted'}
                                            borderRadius="md"
                                            _hover={{ bg: 'bg.muted', color: 'fg' }}
                                            transition="all 0.2s"
                                        >
                                            <Icon as={item.icon} boxSize={4} />
                                            {!isCollapsed && <Text fontSize="sm">{item.label}</Text>}
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
