import { useEffect, useState, useCallback } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Box, HStack, Text } from '@chakra-ui/react';
import { LuBookmark, LuChevronDown, LuX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { MenuRoot, MenuTrigger, MenuContent, MenuItem } from '@/components/ui/menu';

/**
 * SavedSearches — сохранённые наборы фильтров для текущего раздела.
 *
 * Появляется только при включённом флаге `search-cabinet.presets`. Список
 * пресетов лежит в `user_search_presets`, контракт см. PR 5.1
 * (docs/tasks/in-progress/2026-04-28_cabinet-search-presets.md).
 *
 * @param {{ section: string, current: object, basePath: string }} props
 *   - section — ключ раздела ('orders' | 'returns' | ...).
 *   - current — текущие фильтры (то, что сейчас в URL/state).
 *   - basePath — путь страницы для router.get при применении пресета
 *     (например, '/cabinet/orders').
 */
export default function SavedSearches({ section, current, basePath }) {
    const [presets, setPresets] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);

    const reload = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(`/cabinet/search-presets/${section}`);
            setPresets(data.data || []);
        } catch (error) {
            console.error('Не удалось загрузить пресеты:', error);
            setPresets([]);
        } finally {
            setLoading(false);
        }
    }, [section]);

    useEffect(() => {
        reload();
    }, [reload]);

    const save = async () => {
        const name = window.prompt('Название сохранённого поиска:');
        if (!name || !name.trim()) return;
        setSaving(true);
        try {
            const filters = sanitizeFilters(current);
            await axios.post('/cabinet/search-presets', {
                section,
                name: name.trim(),
                filters,
            });
            await reload();
        } catch (error) {
            console.error('Не удалось сохранить пресет:', error);
            window.alert('Не удалось сохранить пресет.');
        } finally {
            setSaving(false);
        }
    };

    const apply = (preset) => {
        router.get(basePath, preset.filters || {}, {
            preserveState: false,
            preserveScroll: true,
        });
    };

    const remove = async (preset, event) => {
        event.stopPropagation();
        if (!window.confirm(`Удалить пресет «${preset.name}»?`)) return;
        try {
            await axios.delete(`/cabinet/search-presets/${preset.id}`);
            setPresets((prev) => prev.filter((p) => p.id !== preset.id));
        } catch (error) {
            console.error('Не удалось удалить пресет:', error);
        }
    };

    return (
        <HStack gap="2" align="center">
            <Button size="sm" variant="outline" onClick={save} disabled={saving}>
                <LuBookmark size={14} />
                <Text ml="2">Сохранить поиск</Text>
            </Button>
            <MenuRoot>
                <MenuTrigger asChild>
                    <Button size="sm" variant="ghost" disabled={loading}>
                        <Text>Мои поиски ({presets.length})</Text>
                        <Box ml="1.5"><LuChevronDown size={14} /></Box>
                    </Button>
                </MenuTrigger>
                <MenuContent minW="280px">
                    {presets.length === 0 ? (
                        <Box px="3" py="2" fontSize="sm" color="gray.500">
                            Сохранённых поисков пока нет
                        </Box>
                    ) : (
                        presets.map((preset) => (
                            <MenuItem
                                key={preset.id}
                                value={String(preset.id)}
                                onClick={() => apply(preset)}
                                cursor="pointer"
                            >
                                <HStack justify="space-between" w="full">
                                    <Text truncate>{preset.name}</Text>
                                    <Box
                                        as="button"
                                        type="button"
                                        onClick={(e) => remove(preset, e)}
                                        color="gray.400"
                                        _hover={{ color: 'red.500' }}
                                        aria-label="Удалить пресет"
                                    >
                                        <LuX size={14} />
                                    </Box>
                                </HStack>
                            </MenuItem>
                        ))
                    )}
                </MenuContent>
            </MenuRoot>
        </HStack>
    );
}

/**
 * Чистим текущие фильтры от пустых строк/массивов перед сохранением,
 * чтобы пресет не «заморозил» дефолтное состояние.
 */
function sanitizeFilters(current) {
    if (!current || typeof current !== 'object') return {};
    const out = {};
    for (const [key, value] of Object.entries(current)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        if (Array.isArray(value) && value.length === 0) continue;
        out[key] = value;
    }
    return out;
}
