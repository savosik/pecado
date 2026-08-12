import { useEffect, useMemo, useRef, useState } from 'react';
import { Box, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import { createListCollection } from '@chakra-ui/react';
import { LuList, LuMap } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import {
    ComboboxContent,
    ComboboxControl,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemText,
    ComboboxRoot,
} from '@/components/ui/combobox';
import { loadYmaps } from '@/components/common/ymapsLoader';

const MAP_ZOOM = 11;
const POINT_ZOOM = 15;

/** Строка для поиска: по названию, адресу и телефону сразу. */
const haystack = (point) => `${point.name} ${point.address} ${point.phone || ''}`.toLowerCase();

/**
 * Выбор пункта выдачи: список с поиском и та же выборка на карте.
 *
 * Пунктов у перевозчика бывают тысячи, и выпадающий список без поиска — это
 * прокрутка вслепую. Карта нужна по другой причине: кладовщик знает город хуже
 * получателя, и «какой из них ближе» по адресной строке не понять.
 */
export function PickupPointPicker({ points, value, onChange, loading = false }) {
    const { props } = usePage();
    const apiKey = props?.config?.yandex_maps_api_key || '';

    const [mode, setMode] = useState('list');
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? points
            : points.filter((point) => haystack(point).includes(needle));
    }, [points, query]);

    const collection = useMemo(() => createListCollection({
        items: filtered,
        itemToString: (point) => `${point.name} — ${point.address}`,
        itemToValue: (point) => point.id,
    }), [filtered]);

    const selected = points.find((point) => point.id === value) || null;
    const mappable = useMemo(() => points.filter((point) => point.lat && point.lng), [points]);

    return (
        <VStack gap={2} align="stretch" width="100%">
            <HStack gap={2} justify="space-between" flexWrap="wrap">
                <Text fontSize="sm" color="fg.muted">
                    {loading
                        ? 'Загружаем пункты выдачи…'
                        : `Пунктов выдачи: ${points.length}${filtered.length !== points.length ? `, найдено ${filtered.length}` : ''}`}
                </Text>
                <HStack gap={1}>
                    <Button
                        size="xs"
                        variant={mode === 'list' ? 'solid' : 'outline'}
                        onClick={() => setMode('list')}
                    >
                        <LuList /> Списком
                    </Button>
                    <Button
                        size="xs"
                        variant={mode === 'map' ? 'solid' : 'outline'}
                        onClick={() => setMode('map')}
                        disabled={mappable.length === 0}
                    >
                        <LuMap /> На карте
                    </Button>
                </HStack>
            </HStack>

            {mode === 'list' ? (
                <ComboboxRoot
                    collection={collection}
                    value={value ? [value] : []}
                    inputValue={query}
                    onInputValueChange={(details) => setQuery(details.inputValue)}
                    onValueChange={(details) => onChange(details.value[0] || '')}
                    openOnClick
                    selectionBehavior="preserve"
                    size="sm"
                    disabled={loading}
                >
                    <ComboboxControl clearable>
                        <ComboboxInput placeholder="Название, адрес или телефон пункта" />
                    </ComboboxControl>
                    <ComboboxContent maxH="320px" overflowY="auto">
                        <ComboboxEmpty>Ничего не нашлось — попробуйте часть адреса</ComboboxEmpty>
                        {collection.items.map((point) => (
                            <ComboboxItem key={point.id} item={point}>
                                <VStack align="start" gap={0}>
                                    <ComboboxItemText>{point.name}</ComboboxItemText>
                                    <Text fontSize="xs" color="fg.muted">{point.address}</Text>
                                </VStack>
                            </ComboboxItem>
                        ))}
                    </ComboboxContent>
                </ComboboxRoot>
            ) : (
                <PointsMap
                    apiKey={apiKey}
                    points={mappable}
                    value={value}
                    onChange={onChange}
                />
            )}

            {selected && (
                <Box borderWidth="1px" borderRadius="md" p={2}>
                    <Text fontSize="sm" fontWeight="medium">{selected.name}</Text>
                    <Text fontSize="xs" color="fg.muted">{selected.address}</Text>
                    {selected.timetable && (
                        <Text fontSize="xs" color="fg.muted" mt={1}>{selected.timetable}</Text>
                    )}
                </Box>
            )}
        </VStack>
    );
}

/**
 * Метки пунктов выдачи на Яндекс.Карте. Клик по метке выбирает пункт.
 */
function PointsMap({ apiKey, points, value, onChange }) {
    const containerRef = useRef(null);
    const mapRef = useRef(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    const [status, setStatus] = useState(apiKey ? 'loading' : 'no-key');

    useEffect(() => {
        if (!apiKey || points.length === 0) {
            return undefined;
        }

        let cancelled = false;

        loadYmaps(apiKey)
            .then((ymaps) => {
                if (cancelled || !containerRef.current) return;

                const map = new ymaps.Map(containerRef.current, {
                    center: [points[0].lat, points[0].lng],
                    zoom: MAP_ZOOM,
                    controls: ['zoomControl', 'geolocationControl'],
                }, { suppressMapOpenBlock: true });

                points.forEach((point) => {
                    const placemark = new ymaps.Placemark(
                        [point.lat, point.lng],
                        { balloonContentHeader: point.name, balloonContentBody: point.address },
                        { preset: point.id === value ? 'islands#redDotIcon' : 'islands#blueDotIcon' },
                    );

                    placemark.events.add('click', () => onChangeRef.current?.(point.id));
                    map.geoObjects.add(placemark);
                });

                // Показываем все точки разом: кладовщик выбирает «что ближе»,
                // а не разглядывает одну.
                map.setBounds(map.geoObjects.getBounds(), { checkZoomRange: true, zoomMargin: 30 })
                    .then(() => {
                        if (map.getZoom() > POINT_ZOOM) map.setZoom(POINT_ZOOM);
                    })
                    .catch(() => {});

                mapRef.current = map;
                setStatus('ready');
            })
            .catch(() => {
                if (!cancelled) setStatus('error');
            });

        return () => {
            cancelled = true;
            mapRef.current?.destroy();
            mapRef.current = null;
        };
    }, [apiKey, points, value]);

    if (status === 'no-key') {
        return (
            <Box borderWidth="1px" borderRadius="md" p={3}>
                <Text fontSize="sm" color="fg.muted">
                    Карта недоступна: не задан ключ Яндекс.Карт. Выбирайте пункт списком.
                </Text>
            </Box>
        );
    }

    if (status === 'error') {
        return (
            <Box borderWidth="1px" borderRadius="md" p={3}>
                <Text fontSize="sm" color="fg.muted">
                    Не удалось загрузить карту. Выбирайте пункт списком.
                </Text>
            </Box>
        );
    }

    return (
        <Box position="relative" borderWidth="1px" borderRadius="md" overflow="hidden">
            <Box ref={containerRef} height="360px" width="100%" />
            {status === 'loading' && (
                <HStack position="absolute" inset={0} justify="center" bg="bg/80">
                    <Spinner size="sm" />
                    <Text fontSize="sm" color="fg.muted">Загружаем карту…</Text>
                </HStack>
            )}
        </Box>
    );
}
