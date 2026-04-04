import { Box, Text, HStack, VStack, Button, Card, SimpleGrid, Badge } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * PermissionMatrix — матрица прав доступа (ресурс × действие).
 * @param {Array} permissionGroups - группы из бэкенда [{group, items: [{resource, label, actions}]}]
 * @param {Object} actionLabels - {view: 'Просмотр', ...}
 * @param {Array} selected - массив выбранных permission names
 * @param {Function} onChange - callback(newSelected)
 */
export const PermissionMatrix = ({ permissionGroups, actionLabels, selected = [], onChange }) => {
    const allActions = ['view', 'create', 'edit', 'delete'];
    const selectedSet = new Set(selected);

    const toggle = (perm) => {
        const next = new Set(selectedSet);
        next.has(perm) ? next.delete(perm) : next.add(perm);
        onChange([...next]);
    };

    const toggleResource = (resource, actions) => {
        const perms = actions.map(a => `${resource}.${a}`);
        const allChecked = perms.every(p => selectedSet.has(p));
        const next = new Set(selectedSet);
        perms.forEach(p => allChecked ? next.delete(p) : next.add(p));
        onChange([...next]);
    };

    const toggleGroup = (items) => {
        const perms = items.flatMap(i => i.actions.map(a => `${i.resource}.${a}`));
        const allChecked = perms.every(p => selectedSet.has(p));
        const next = new Set(selectedSet);
        perms.forEach(p => allChecked ? next.delete(p) : next.add(p));
        onChange([...next]);
    };

    const toggleAll = () => {
        const all = permissionGroups.flatMap(g =>
            g.items.flatMap(i => i.actions.map(a => `${i.resource}.${a}`))
        );
        const allChecked = all.every(p => selectedSet.has(p));
        onChange(allChecked ? [] : all);
    };

    const totalCount = permissionGroups.reduce((s, g) =>
        s + g.items.reduce((s2, i) => s2 + i.actions.length, 0), 0);

    return (
        <VStack align="stretch" gap={4}>
            <HStack justify="space-between">
                <Text fontWeight="bold" fontSize="lg">Права доступа</Text>
                <HStack gap={2}>
                    <Badge colorPalette="blue" fontSize="sm" px={2} py={1}>
                        {selected.length} / {totalCount}
                    </Badge>
                    <Button size="xs" variant="outline" onClick={toggleAll}>
                        {selected.length === totalCount ? 'Снять все' : 'Выбрать все'}
                    </Button>
                </HStack>
            </HStack>

            {permissionGroups.map((group) => (
                <Card.Root key={group.group} variant="outline">
                    <Card.Body p={3}>
                        <HStack justify="space-between" mb={3}>
                            <Text fontWeight="semibold" fontSize="md">{group.group}</Text>
                            <Button
                                size="xs"
                                variant="ghost"
                                colorPalette="blue"
                                onClick={() => toggleGroup(group.items)}
                            >
                                Выбрать группу
                            </Button>
                        </HStack>

                        {/* Заголовки колонок */}
                        <SimpleGrid columns={5} gap={2} mb={2}>
                            <Box />
                            {allActions.map(action => (
                                <Text key={action} fontSize="xs" fontWeight="medium" color="fg.muted" textAlign="center">
                                    {actionLabels[action] || action}
                                </Text>
                            ))}
                        </SimpleGrid>

                        {/* Строки ресурсов */}
                        {group.items.map((item) => (
                            <SimpleGrid key={item.resource} columns={5} gap={2} py={1}
                                _hover={{ bg: 'bg.muted' }} borderRadius="md" px={1}
                            >
                                <HStack gap={1} cursor="pointer"
                                    onClick={() => toggleResource(item.resource, item.actions)}
                                >
                                    <Text fontSize="sm">{item.label}</Text>
                                </HStack>
                                {allActions.map(action => {
                                    const perm = `${item.resource}.${action}`;
                                    const available = item.actions.includes(action);
                                    return (
                                        <Box key={action} textAlign="center">
                                            {available ? (
                                                <Checkbox
                                                    checked={selectedSet.has(perm)}
                                                    onCheckedChange={() => toggle(perm)}
                                                    size="sm"
                                                />
                                            ) : (
                                                <Text fontSize="xs" color="fg.subtle">—</Text>
                                            )}
                                        </Box>
                                    );
                                })}
                            </SimpleGrid>
                        ))}
                    </Card.Body>
                </Card.Root>
            ))}
        </VStack>
    );
};
