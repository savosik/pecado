import { useState, useMemo, useEffect, useRef, useCallback } from 'react';
import {
    Box, HStack, Stack, Text, Button, IconButton, Input, Badge,
} from '@chakra-ui/react';
import { LuPlus, LuTrash2, LuFolderPlus, LuGripVertical, LuChevronDown } from 'react-icons/lu';
import axios from 'axios';

const operatorLabels = {
    '=': 'равно',
    'contains': 'содержит',
    'not_contains': 'не содержит',
    'starts_with': 'начинается с',
    '>': 'больше',
    '<': 'меньше',
    '>=': '≥',
    '<=': '≤',
    'between': 'в диапазоне',
    'in': 'входит в',
    'not_in': 'не входит в',
};

// ─── Async search dropdown for relation fields ─────────────────────────────────
function RelationSelect({ searchUrl, value, onChange, placeholder = 'Поиск...' }) {
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [selectedItems, setSelectedItems] = useState([]);
    const ref = useRef(null);

    // Ensure value is always array of IDs
    const selectedIds = useMemo(() => {
        if (!value) return [];
        return (Array.isArray(value) ? value : [value]).map(Number).filter(Boolean);
    }, [value]);

    // Load initial labels for already-selected IDs
    useEffect(() => {
        if (selectedIds.length === 0) {
            setSelectedItems([]);
            return;
        }
        // Search with empty query to try to find labels
        const loadLabels = async () => {
            try {
                const promises = selectedIds.map(id =>
                    axios.get(searchUrl, { params: { query: '', id } }).catch(() => null)
                );
                // Alternative: just load all and match
                const resp = await axios.get(searchUrl, { params: { query: '' } });
                const all = resp.data || [];
                const found = all.filter(item => selectedIds.includes(item.id));
                setSelectedItems(prev => {
                    const existing = new Map(prev.map(i => [i.id, i]));
                    found.forEach(f => existing.set(f.id, f));
                    return Array.from(existing.values());
                });
            } catch { }
        };
        loadLabels();
    }, []);

    // Search when typing
    useEffect(() => {
        if (!open) return;
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const resp = await axios.get(searchUrl, { params: { query: search } });
                setOptions(resp.data || []);
            } catch {
                setOptions([]);
            }
            setLoading(false);
        }, 250);
        return () => clearTimeout(timer);
    }, [search, open, searchUrl]);

    // Close on outside click
    useEffect(() => {
        const handler = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const toggleItem = (item) => {
        const id = item.id;
        let newIds;
        if (selectedIds.includes(id)) {
            newIds = selectedIds.filter(x => x !== id);
            setSelectedItems(prev => prev.filter(i => i.id !== id));
        } else {
            newIds = [...selectedIds, id];
            setSelectedItems(prev => [...prev, item]);
        }
        onChange(newIds);
    };

    const displayName = (item) => item.full_name || item.name || item.title || `#${item.id}`;

    return (
        <Box position="relative" flex="1" minW="180px" ref={ref}>
            <Box
                border="1px solid"
                borderColor={open ? '#7c3aed' : '#e2e8f0'}
                borderRadius="md"
                px={2} py={1}
                cursor="pointer"
                minH="34px"
                display="flex"
                alignItems="center"
                flexWrap="wrap"
                gap={1}
                onClick={() => setOpen(!open)}
                bg="white"
                transition="border-color 0.15s"
            >
                {selectedIds.length === 0
                    ? <Text fontSize="13px" color="gray.400">{placeholder}</Text>
                    : selectedItems
                        .filter(i => selectedIds.includes(i.id))
                        .map(item => (
                            <Badge
                                key={item.id}
                                size="sm"
                                colorPalette="purple"
                                variant="subtle"
                                cursor="pointer"
                                onClick={(e) => { e.stopPropagation(); toggleItem(item); }}
                            >
                                {displayName(item)} ×
                            </Badge>
                        ))
                }
                <Box ml="auto" color="gray.400" flexShrink={0}>
                    <LuChevronDown size={14} />
                </Box>
            </Box>
            {open && (
                <Box
                    position="absolute"
                    top="100%"
                    left={0}
                    right={0}
                    mt={1}
                    bg="white"
                    border="1px solid #e2e8f0"
                    borderRadius="md"
                    zIndex={20}
                    boxShadow="lg"
                    overflow="hidden"
                >
                    <Box p={2} borderBottom="1px solid #f1f5f9">
                        <Input
                            size="xs"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Поиск..."
                            autoFocus
                        />
                    </Box>
                    <Box maxH="200px" overflowY="auto">
                        {loading && <Text px={3} py={2} fontSize="13px" color="gray.400">Загрузка...</Text>}
                        {!loading && options.map(item => {
                            const checked = selectedIds.includes(item.id);
                            return (
                                <HStack
                                    key={item.id}
                                    px={3} py={1.5}
                                    cursor="pointer"
                                    bg={checked ? 'purple.50' : 'white'}
                                    _hover={{ bg: checked ? 'purple.100' : 'gray.50' }}
                                    onClick={() => toggleItem(item)}
                                    gap={2}
                                >
                                    <input type="checkbox" checked={checked} readOnly style={{ accentColor: '#7c3aed' }} />
                                    <Text fontSize="13px">{displayName(item)}</Text>
                                </HStack>
                            );
                        })}
                        {!loading && options.length === 0 && (
                            <Text px={3} py={2} fontSize="13px" color="gray.400">Ничего не найдено</Text>
                        )}
                    </Box>
                </Box>
            )}
        </Box>
    );
}

// ─── Styled native select wrapper ──────────────────────────────────────────────
function StyledSelect({ value, onChange, children, minW = '140px', ...rest }) {
    return (
        <Box position="relative" minW={minW} {...rest}>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                style={{
                    width: '100%',
                    padding: '7px 28px 7px 10px',
                    borderRadius: '8px',
                    border: '1px solid #e2e8f0',
                    fontSize: '13px',
                    background: '#fff',
                    appearance: 'none',
                    cursor: 'pointer',
                }}
            >
                {children}
            </select>
            <Box
                position="absolute"
                right="8px"
                top="50%"
                transform="translateY(-50%)"
                pointerEvents="none"
                color="gray.400"
            >
                <LuChevronDown size={14} />
            </Box>
        </Box>
    );
}

// ─── Single Condition Row ──────────────────────────────────────────────────────
function ConditionRow({ condition, availableFilters, onChange, onRemove }) {
    const allFields = useMemo(
        () => availableFilters.flatMap(g => g.fields),
        [availableFilters]
    );
    const selectedField = allFields.find(f => f.key === condition.field);
    const operators = selectedField?.operators || ['='];
    const fieldType = selectedField?.type || 'text';
    const options = selectedField?.options || [];
    const searchUrl = selectedField?.search_url || null;

    const handleFieldChange = (key) => {
        const f = allFields.find(x => x.key === key);
        const op = f?.operators?.[0] || '=';
        onChange({ ...condition, field: key, operator: op, value: '' });
    };

    const handleOperatorChange = (op) => {
        const val = op === 'between' ? ['', ''] : '';
        onChange({ ...condition, operator: op, value: val });
    };

    const handleValueChange = (val) => {
        onChange({ ...condition, value: val });
    };

    // ─── Field search dropdown ──────────────────────────────────────
    const [search, setSearch] = useState('');
    const [dropdownOpen, setDropdownOpen] = useState(false);
    const [selectDropdownOpen, setSelectDropdownOpen] = useState(false);

    const filteredGroups = useMemo(() => {
        if (!search.trim()) return availableFilters;
        const q = search.toLowerCase();
        return availableFilters
            .map(g => ({
                ...g,
                fields: g.fields.filter(f => f.label.toLowerCase().includes(q)),
            }))
            .filter(g => g.fields.length > 0);
    }, [search, availableFilters]);

    // ─── Value input based on type ──────────────────────────────────
    const renderValueInput = () => {
        if (fieldType === 'boolean') {
            return (
                <StyledSelect
                    value={condition.value === true || condition.value === 'true' ? 'true' : 'false'}
                    onChange={(val) => handleValueChange(val === 'true')}
                    minW="100px"
                >
                    <option value="true">Да</option>
                    <option value="false">Нет</option>
                </StyledSelect>
            );
        }

        if (fieldType === 'select') {
            const selectedIds = Array.isArray(condition.value)
                ? condition.value.map(Number)
                : (condition.value ? [Number(condition.value)] : []);

            return (
                <Box position="relative" flex="1" minW="180px">
                    <Box
                        border="1px solid #e2e8f0"
                        borderRadius="8px"
                        px={2} py={1}
                        cursor="pointer"
                        minH="34px"
                        display="flex"
                        alignItems="center"
                        flexWrap="wrap"
                        gap={1}
                        onClick={() => setSelectDropdownOpen(!selectDropdownOpen)}
                        bg="white"
                    >
                        {selectedIds.length === 0
                            ? <Text fontSize="13px" color="gray.400">Выберите...</Text>
                            : selectedIds.map((id) => {
                                const opt = options.find(o => o.value === id);
                                return opt ? (
                                    <Badge key={id} size="sm" colorPalette="purple" variant="subtle"
                                        cursor="pointer"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            handleValueChange(selectedIds.filter(x => x !== id));
                                        }}
                                    >
                                        {opt.label} ×
                                    </Badge>
                                ) : null;
                            })
                        }
                        <Box ml="auto" color="gray.400" flexShrink={0}>
                            <LuChevronDown size={14} />
                        </Box>
                    </Box>
                    {selectDropdownOpen && (
                        <Box
                            position="absolute"
                            top="100%"
                            left={0}
                            right={0}
                            mt={1}
                            bg="white"
                            border="1px solid #e2e8f0"
                            borderRadius="8px"
                            zIndex={10}
                            maxH="200px"
                            overflowY="auto"
                            boxShadow="lg"
                        >
                            {options.map((opt) => {
                                const checked = selectedIds.includes(opt.value);
                                return (
                                    <HStack
                                        key={opt.value}
                                        px={3} py={1.5}
                                        cursor="pointer"
                                        bg={checked ? 'purple.50' : 'white'}
                                        _hover={{ bg: checked ? 'purple.100' : 'gray.50' }}
                                        onClick={() => {
                                            if (checked) {
                                                handleValueChange(selectedIds.filter(x => x !== opt.value));
                                            } else {
                                                handleValueChange([...selectedIds, opt.value]);
                                            }
                                        }}
                                    >
                                        <input type="checkbox" checked={checked} readOnly style={{ accentColor: '#7c3aed' }} />
                                        <Text fontSize="13px">{opt.label}</Text>
                                    </HStack>
                                );
                            })}
                            {options.length === 0 && (
                                <Text px={3} py={2} fontSize="13px" color="gray.400">Нет вариантов</Text>
                            )}
                        </Box>
                    )}
                </Box>
            );
        }

        if (condition.operator === 'between') {
            return (
                <HStack gap={1} flex="1" minW="180px">
                    <Input
                        size="xs"
                        borderRadius="md"
                        type={fieldType === 'numeric' ? 'number' : fieldType === 'date' ? 'date' : 'text'}
                        value={Array.isArray(condition.value) ? condition.value[0] || '' : ''}
                        onChange={(e) => {
                            const arr = Array.isArray(condition.value) ? [...condition.value] : ['', ''];
                            arr[0] = e.target.value;
                            handleValueChange(arr);
                        }}
                        placeholder="От"
                    />
                    <Input
                        size="xs"
                        borderRadius="md"
                        type={fieldType === 'numeric' ? 'number' : fieldType === 'date' ? 'date' : 'text'}
                        value={Array.isArray(condition.value) ? condition.value[1] || '' : ''}
                        onChange={(e) => {
                            const arr = Array.isArray(condition.value) ? [...condition.value] : ['', ''];
                            arr[1] = e.target.value;
                            handleValueChange(arr);
                        }}
                        placeholder="До"
                    />
                </HStack>
            );
        }

        // Relation fields use async search dropdown
        if (fieldType === 'relation' && searchUrl) {
            return (
                <RelationSelect
                    searchUrl={searchUrl}
                    value={condition.value}
                    onChange={handleValueChange}
                    placeholder="Выберите..."
                />
            );
        }

        return (
            <Input
                size="xs"
                flex="1"
                minW="140px"
                borderRadius="md"
                type={fieldType === 'numeric' ? 'number' : fieldType === 'date' ? 'date' : 'text'}
                value={condition.value || ''}
                onChange={(e) => handleValueChange(e.target.value)}
                placeholder="Значение"
            />
        );
    };

    return (
        <HStack
            bg="white"
            border="1px solid"
            borderColor="gray.200"
            borderRadius="lg"
            px={3}
            py={2.5}
            gap={2}
            align="center"
            flexWrap="wrap"
            boxShadow="0 1px 3px rgba(0,0,0,0.04)"
        >
            {/* Drag handle */}
            <Box color="gray.300" flexShrink={0} cursor="grab">
                <LuGripVertical size={16} />
            </Box>

            {/* Field selector */}
            <Box position="relative" minW="170px" flex="1">
                <Box
                    border="1px solid"
                    borderColor={dropdownOpen ? '#7c3aed' : '#e2e8f0'}
                    borderRadius="8px"
                    bg="white"
                    position="relative"
                    transition="border-color 0.15s"
                >
                    <Input
                        size="xs"
                        value={dropdownOpen ? search : (selectedField?.label || '')}
                        onChange={(e) => { setSearch(e.target.value); setDropdownOpen(true); }}
                        onFocus={() => { setSearch(''); setDropdownOpen(true); }}
                        onBlur={() => setTimeout(() => setDropdownOpen(false), 200)}
                        placeholder="Выберите поле..."
                        variant="plain"
                        px={2}
                    />
                    <Box
                        position="absolute"
                        right="8px"
                        top="50%"
                        transform="translateY(-50%)"
                        color="gray.400"
                        pointerEvents="none"
                    >
                        <LuChevronDown size={14} />
                    </Box>
                </Box>
                {dropdownOpen && (
                    <Box
                        position="absolute"
                        top="100%"
                        left={0}
                        right={0}
                        mt={1}
                        bg="white"
                        border="1px solid #e2e8f0"
                        borderRadius="8px"
                        zIndex={20}
                        maxH="300px"
                        overflowY="auto"
                        boxShadow="lg"
                        minW="250px"
                    >
                        {filteredGroups.map(group => (
                            <Box key={group.group}>
                                <Text px={3} py={1.5} fontSize="11px" fontWeight="bold" color="gray.500"
                                    bg="gray.50" textTransform="uppercase" letterSpacing="0.05em">
                                    {group.group}
                                </Text>
                                {group.fields.map(f => (
                                    <Box
                                        key={f.key}
                                        px={3} py={2}
                                        cursor="pointer"
                                        _hover={{ bg: 'purple.50' }}
                                        bg={condition.field === f.key ? 'purple.50' : undefined}
                                        onMouseDown={(e) => {
                                            e.preventDefault();
                                            handleFieldChange(f.key);
                                            setDropdownOpen(false);
                                            setSearch('');
                                        }}
                                    >
                                        <Text fontSize="13px">{f.label}</Text>
                                    </Box>
                                ))}
                            </Box>
                        ))}
                        {filteredGroups.length === 0 && (
                            <Text px={3} py={2} fontSize="13px" color="gray.400">Ничего не найдено</Text>
                        )}
                    </Box>
                )}
            </Box>

            {/* Operator */}
            <StyledSelect
                value={condition.operator || operators[0]}
                onChange={(val) => handleOperatorChange(val)}
                minW="130px"
                flexShrink={0}
            >
                {operators.map(op => (
                    <option key={op} value={op}>{operatorLabels[op] || op}</option>
                ))}
            </StyledSelect>

            {/* Value */}
            {renderValueInput()}

            {/* Remove button */}
            <IconButton
                size="xs"
                variant="ghost"
                color="gray.400"
                _hover={{ color: 'red.500', bg: 'red.50' }}
                onClick={onRemove}
                aria-label="Удалить"
                flexShrink={0}
            >
                <LuTrash2 />
            </IconButton>
        </HStack>
    );
}

// ─── Connector with logic badge ────────────────────────────────────────────────
function LogicConnector({ logic, onClick }) {
    return (
        <Box position="relative" h="8px" ml="22px">
            {/* Vertical line segment */}
            <Box
                position="absolute"
                left="0"
                top="0"
                bottom="0"
                w="2px"
                bg={logic === 'and' ? '#c4b5fd' : '#fdba74'}
            />
        </Box>
    );
}

// ─── Filter Group (recursive) ──────────────────────────────────────────────────
function FilterGroup({ group, availableFilters, onChange, onRemove, depth = 0 }) {
    const logic = group.logic || 'and';
    const conditions = group.conditions || [];

    const updateCondition = (index, newCondition) => {
        const newConditions = [...conditions];
        newConditions[index] = newCondition;
        onChange({ ...group, conditions: newConditions });
    };

    const removeCondition = (index) => {
        const newConditions = [...conditions];
        newConditions.splice(index, 1);
        onChange({ ...group, conditions: newConditions });
    };

    const addCondition = () => {
        onChange({
            ...group,
            conditions: [
                ...conditions,
                { type: 'condition', field: '', operator: '=', value: '' },
            ],
        });
    };

    const addSubGroup = () => {
        onChange({
            ...group,
            conditions: [
                ...conditions,
                {
                    type: 'group',
                    logic: 'or',
                    conditions: [
                        { type: 'condition', field: '', operator: '=', value: '' },
                    ],
                },
            ],
        });
    };

    const toggleLogic = () => {
        onChange({ ...group, logic: logic === 'and' ? 'or' : 'and' });
    };

    const accentColor = logic === 'and' ? '#7c3aed' : '#f97316';
    const accentBg = logic === 'and' ? '#ede9fe' : '#fff7ed';
    const accentBorder = logic === 'and' ? '#c4b5fd' : '#fdba74';

    return (
        <Box position="relative">
            {/* Left bracket line for this group */}
            <Box
                position="absolute"
                left="22px"
                top="20px"
                bottom="52px"
                w="2px"
                bg={accentBorder}
                borderRadius="full"
            />

            <Stack gap={0}>
                {conditions.map((cond, idx) => (
                    <Box key={idx}>
                        {/* Logic badge between items */}
                        {idx > 0 && (
                            <Box position="relative" h="32px" display="flex" alignItems="center">
                                {/* Badge */}
                                <Box
                                    as="button"
                                    type="button"
                                    onClick={toggleLogic}
                                    position="absolute"
                                    left="8px"
                                    zIndex={2}
                                    display="flex"
                                    alignItems="center"
                                    gap={1}
                                    px={2}
                                    py={0.5}
                                    borderRadius="md"
                                    fontSize="12px"
                                    fontWeight="bold"
                                    cursor="pointer"
                                    border="1px solid"
                                    bg={accentBg}
                                    color={accentColor}
                                    borderColor={accentBorder}
                                    _hover={{ opacity: 0.8 }}
                                    transition="all 0.15s"
                                >
                                    {logic === 'and' ? 'И' : 'ИЛИ'}
                                    <LuChevronDown size={12} />
                                </Box>
                            </Box>
                        )}

                        {/* Row content */}
                        <Box position="relative" pl="44px">
                            {/* Horizontal arm */}
                            <Box
                                position="absolute"
                                left="22px"
                                top="50%"
                                w="20px"
                                h="2px"
                                bg={accentBorder}
                                transform="translateY(-50%)"
                            />

                            {cond.type === 'group' ? (
                                <Box>
                                    <HStack mb={1} gap={1}>
                                        <Badge
                                            size="sm"
                                            colorPalette={cond.logic === 'and' ? 'purple' : 'orange'}
                                            variant="subtle"
                                            cursor="pointer"
                                            onClick={() => {
                                                const updated = { ...cond, logic: cond.logic === 'and' ? 'or' : 'and' };
                                                updateCondition(idx, updated);
                                            }}
                                        >
                                            Группа: {cond.logic === 'and' ? 'И' : 'ИЛИ'}
                                        </Badge>
                                        <IconButton
                                            size="2xs"
                                            variant="ghost"
                                            color="gray.400"
                                            _hover={{ color: 'red.500' }}
                                            onClick={() => removeCondition(idx)}
                                            aria-label="Удалить группу"
                                        >
                                            <LuTrash2 />
                                        </IconButton>
                                    </HStack>
                                    <FilterGroup
                                        group={cond}
                                        availableFilters={availableFilters}
                                        onChange={(newGroup) => updateCondition(idx, newGroup)}
                                        onRemove={() => removeCondition(idx)}
                                        depth={depth + 1}
                                    />
                                </Box>
                            ) : (
                                <ConditionRow
                                    condition={cond}
                                    availableFilters={availableFilters}
                                    onChange={(newCond) => updateCondition(idx, { ...newCond, type: 'condition' })}
                                    onRemove={() => removeCondition(idx)}
                                />
                            )}
                        </Box>
                    </Box>
                ))}

                {conditions.length === 0 && (
                    <Text fontSize="sm" color="fg.muted" textAlign="center" py={3} pl="44px">
                        Нет условий в группе.
                    </Text>
                )}
            </Stack>

            {/* Bottom actions */}
            <HStack mt={2} pl="44px" gap={3}>
                <Button
                    size="xs"
                    variant="ghost"
                    color="#7c3aed"
                    _hover={{ bg: '#ede9fe' }}
                    onClick={addCondition}
                    fontWeight="medium"
                >
                    <LuPlus /> Добавить условие
                </Button>
                {depth < 3 && (
                    <Button
                        size="xs"
                        variant="ghost"
                        color="#7c3aed"
                        _hover={{ bg: '#ede9fe' }}
                        onClick={addSubGroup}
                        fontWeight="medium"
                    >
                        <LuFolderPlus /> Добавить группу
                    </Button>
                )}
            </HStack>
        </Box>
    );
}

// ─── Main FilterBuilder Component ──────────────────────────────────────────────
export default function FilterBuilder({ filters, availableFilters, onChange }) {
    const rootGroup = (filters && filters.logic)
        ? filters
        : { logic: 'and', conditions: [] };

    const handleRootChange = (newGroup) => {
        onChange(newGroup);
    };

    const isEmpty = !rootGroup.conditions || rootGroup.conditions.length === 0;

    if (isEmpty) {
        return (
            <Box
                py={8}
                textAlign="center"
                border="1px dashed"
                borderColor="gray.300"
                borderRadius="lg"
                color="fg.muted"
                bg="gray.50"
            >
                <Text fontWeight="medium">Фильтры не заданы — будут выгружены все товары.</Text>
                <Text fontSize="sm" mt={1} color="gray.500">Добавьте условия для фильтрации.</Text>
                <HStack mt={4} justify="center" gap={3}>
                    <Button
                        size="sm"
                        bg="#7c3aed"
                        color="white"
                        _hover={{ bg: '#6d28d9' }}
                        onClick={() => onChange({
                            logic: 'and',
                            conditions: [
                                { type: 'condition', field: '', operator: '=', value: '' },
                            ],
                        })}
                    >
                        <LuPlus /> Добавить условие
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        borderColor="#c4b5fd"
                        color="#7c3aed"
                        _hover={{ bg: '#ede9fe' }}
                        onClick={() => onChange({
                            logic: 'and',
                            conditions: [
                                {
                                    type: 'group',
                                    logic: 'or',
                                    conditions: [
                                        { type: 'condition', field: '', operator: '=', value: '' },
                                    ],
                                },
                            ],
                        })}
                    >
                        <LuFolderPlus /> Добавить группу
                    </Button>
                </HStack>
            </Box>
        );
    }

    return (
        <Box>
            <FilterGroup
                group={rootGroup}
                availableFilters={availableFilters}
                onChange={handleRootChange}
                onRemove={null}
                depth={0}
            />
        </Box>
    );
}
