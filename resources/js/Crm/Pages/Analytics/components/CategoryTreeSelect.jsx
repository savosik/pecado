import { useMemo, useState } from 'react';
import {
    Box, VStack, HStack, Text, Button, Input, Popover, Portal,
} from '@chakra-ui/react';
import { LuChevronDown, LuSearch } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';

// Все id узла и его потомков (для выбора «ветки целиком»).
function collectIds(node) {
    let ids = [node.id];
    for (const c of node.children || []) ids = ids.concat(collectIds(c));
    return ids;
}

function filterTree(nodes, ql) {
    if (!ql) return nodes;
    const res = [];
    for (const n of nodes) {
        const kids = filterTree(n.children || [], ql);
        if (n.name.toLowerCase().includes(ql) || kids.length) {
            res.push({ ...n, children: kids });
        }
    }
    return res;
}

function countLeaves(nodes) {
    let n = 0;
    for (const node of nodes) {
        n += 1;
        n += countLeaves(node.children || []);
    }
    return n;
}

function TreeNode({ node, level, selectedSet, onToggle }) {
    const desc = useMemo(() => collectIds(node), [node]);
    const selectedCount = desc.filter((id) => selectedSet.has(id)).length;
    const checked = selectedCount === desc.length
        ? true
        : (selectedCount > 0 ? 'indeterminate' : false);

    return (
        <Box>
            <HStack py={1} pl={`${level * 18}px`} _hover={{ bg: 'bg.subtle' }} borderRadius="sm">
                <Checkbox
                    checked={checked}
                    onCheckedChange={() => onToggle(node)}
                    size="sm"
                >
                    <Text fontSize="sm" lineClamp={1}>{node.name}</Text>
                </Checkbox>
            </HStack>
            {(node.children || []).map((c) => (
                <TreeNode key={c.id} node={c} level={level + 1} selectedSet={selectedSet} onToggle={onToggle} />
            ))}
        </Box>
    );
}

export default function CategoryTreeSelect({ tree = [], selectedIds = [], onChange }) {
    const [query, setQuery] = useState('');
    const selectedSet = useMemo(() => new Set(selectedIds), [selectedIds]);
    const filtered = useMemo(() => filterTree(tree, query.trim().toLowerCase()), [tree, query]);

    const summary = selectedIds.length === 0 ? 'Все' : `${selectedIds.length} выбрано`;

    const toggle = (node) => {
        const desc = collectIds(node);
        const allSelected = desc.every((id) => selectedSet.has(id));
        const next = allSelected
            ? selectedIds.filter((id) => !desc.includes(id))
            : Array.from(new Set([...selectedIds, ...desc]));
        onChange(next);
    };

    return (
        <VStack align="stretch" gap={1} flex="1" minW="200px">
            <Text fontSize="xs" color="fg.muted" fontWeight="500">Категория</Text>
            <Popover.Root positioning={{ sameWidth: true, placement: 'bottom-start' }}>
                <Popover.Trigger asChild>
                    <Button variant="outline" size="sm" justifyContent="space-between" fontWeight="500" bg="bg">
                        <Text lineClamp={1}>{summary}</Text>
                        <LuChevronDown />
                    </Button>
                </Popover.Trigger>
                <Portal>
                    <Popover.Positioner>
                        <Popover.Content>
                            <Popover.Body p={2}>
                                {tree.length === 0 ? (
                                    <Text fontSize="sm" color="fg.muted" p={2}>Нет вариантов</Text>
                                ) : (
                                    <VStack align="stretch" gap={2}>
                                        <HStack gap={2} px={1}>
                                            <LuSearch size={14} />
                                            <Input
                                                size="xs"
                                                variant="flushed"
                                                placeholder="Поиск категории…"
                                                value={query}
                                                onChange={(e) => setQuery(e.target.value)}
                                            />
                                        </HStack>
                                        {selectedIds.length > 0 && (
                                            <Button size="xs" variant="ghost" onClick={() => onChange([])} justifyContent="flex-start">
                                                Сбросить выбор
                                            </Button>
                                        )}
                                        <Box maxH="300px" overflowY="auto">
                                            {filtered.length === 0 ? (
                                                <Text fontSize="sm" color="fg.muted" p={2}>Ничего не найдено</Text>
                                            ) : (
                                                filtered.map((node) => (
                                                    <TreeNode
                                                        key={node.id}
                                                        node={node}
                                                        level={0}
                                                        selectedSet={selectedSet}
                                                        onToggle={toggle}
                                                    />
                                                ))
                                            )}
                                        </Box>
                                    </VStack>
                                )}
                            </Popover.Body>
                        </Popover.Content>
                    </Popover.Positioner>
                </Portal>
            </Popover.Root>
        </VStack>
    );
}
