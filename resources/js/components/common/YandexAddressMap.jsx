import { useEffect, useRef, useState } from 'react';
import { Box, Spinner, Text, VStack } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import { loadYmaps } from './ymapsLoader';

const DEFAULT_CENTER = [55.751244, 37.618423]; // Москва, центр (fallback если геолокация недоступна)
const DEFAULT_ZOOM = 11;
const POINT_ZOOM = 16;
const GEOLOCATION_ZOOM = 14;

// Пробуем определить координаты пользователя без блокировки рендера карты.
// Resolved-координата используется только для центровки — метка и reverse-geocode не ставятся.
function tryGetGeolocation() {
    return new Promise((resolve) => {
        if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
            resolve(null);
            return;
        }
        navigator.geolocation.getCurrentPosition(
            ({ coords }) => resolve([coords.latitude, coords.longitude]),
            () => resolve(null),
            { enableHighAccuracy: false, timeout: 5000, maximumAge: 5 * 60 * 1000 },
        );
    });
}

/**
 * Карта Яндекса с перетаскиваемой меткой. UI-обёртка для DaData:
 * клик/перетаскивание метки → onCoordsPicked([lat, lon]).
 *
 * Координаты хранятся снаружи; компонент только отображает и зовёт callback.
 */
export function YandexAddressMap({
    coords,
    onCoordsPicked,
    height = 320,
    disabled = false,
}) {
    const { props } = usePage();
    const apiKey = props?.config?.yandex_maps_api_key || '';

    const containerRef = useRef(null);
    const mapRef = useRef(null);
    const placemarkRef = useRef(null);
    const onCoordsPickedRef = useRef(onCoordsPicked);
    onCoordsPickedRef.current = onCoordsPicked;

    const [status, setStatus] = useState('loading'); // loading | ready | error | no-key
    const [errorMessage, setErrorMessage] = useState('');

    useEffect(() => {
        if (!apiKey) {
            setStatus('no-key');
            return;
        }
        let cancelled = false;
        loadYmaps(apiKey)
            .then((ymaps) => {
                if (cancelled || !containerRef.current) return;
                const hasCoords = Array.isArray(coords) && coords.length === 2;
                const initialCenter = hasCoords ? coords : DEFAULT_CENTER;
                const initialZoom = hasCoords ? POINT_ZOOM : DEFAULT_ZOOM;
                const map = new ymaps.Map(containerRef.current, {
                    center: initialCenter,
                    zoom: initialZoom,
                    controls: ['zoomControl', 'geolocationControl'],
                }, { suppressMapOpenBlock: true });

                const placemark = new ymaps.Placemark(initialCenter, {}, {
                    draggable: !disabled,
                    preset: 'islands#redDotIcon',
                });
                if (hasCoords) {
                    map.geoObjects.add(placemark);
                }

                // Если стартовых координат не было — пробуем центровать по геолокации.
                // Только перемещение центра, без метки и без reverse-geocode (в Москве
                // геолокация бывает сбита — пусть юзер сам поставит точку кликом).
                if (!hasCoords) {
                    tryGetGeolocation().then((geo) => {
                        if (cancelled || !geo || !mapRef.current) return;
                        // Если за это время уже поставили метку — не дёргаем центр.
                        if (mapRef.current.geoObjects.indexOf(placemark) !== -1) return;
                        mapRef.current.setCenter(geo, GEOLOCATION_ZOOM, { duration: 400 });
                    });
                }

                map.events.add('click', (e) => {
                    if (disabled) return;
                    const c = e.get('coords');
                    placemark.geometry.setCoordinates(c);
                    if (!map.geoObjects.indexOf || map.geoObjects.indexOf(placemark) === -1) {
                        map.geoObjects.add(placemark);
                    }
                    onCoordsPickedRef.current?.(c);
                });

                placemark.events.add('dragend', () => {
                    if (disabled) return;
                    const c = placemark.geometry.getCoordinates();
                    onCoordsPickedRef.current?.(c);
                });

                mapRef.current = map;
                placemarkRef.current = placemark;
                setStatus('ready');
            })
            .catch((err) => {
                if (cancelled) return;
                setErrorMessage(err?.message || 'Не удалось загрузить карту');
                setStatus('error');
            });

        return () => {
            cancelled = true;
            if (mapRef.current) {
                mapRef.current.destroy();
                mapRef.current = null;
                placemarkRef.current = null;
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [apiKey]);

    // Внешние координаты изменились (выбрали подсказку DaData) — обновляем метку.
    useEffect(() => {
        const map = mapRef.current;
        const placemark = placemarkRef.current;
        if (!map || !placemark) return;

        if (Array.isArray(coords) && coords.length === 2) {
            placemark.geometry.setCoordinates(coords);
            if (map.geoObjects.indexOf(placemark) === -1) {
                map.geoObjects.add(placemark);
            }
            map.setCenter(coords, POINT_ZOOM, { duration: 300 });
        } else if (map.geoObjects.indexOf(placemark) !== -1) {
            map.geoObjects.remove(placemark);
        }
    }, [coords?.[0], coords?.[1]]);

    if (status === 'no-key') {
        return (
            <Box
                h={`${height}px`}
                borderRadius="md"
                border="1px dashed"
                borderColor="border.muted"
                display="flex"
                alignItems="center"
                justifyContent="center"
                color="fg.muted"
                fontSize="sm"
                px="4"
                textAlign="center"
            >
                Карта временно недоступна: не задан ключ Яндекс.Карт.
            </Box>
        );
    }

    return (
        <Box position="relative" h={`${height}px`} borderRadius="md" overflow="hidden" border="1px solid" borderColor="border.muted">
            <Box ref={containerRef} w="full" h="full" />
            {status === 'loading' && (
                <Box position="absolute" inset="0" bg="blackAlpha.50" display="flex" alignItems="center" justifyContent="center">
                    <VStack gap="2">
                        <Spinner size="md" />
                        <Text fontSize="xs" color="fg.muted">Загрузка карты…</Text>
                    </VStack>
                </Box>
            )}
            {status === 'error' && (
                <Box position="absolute" inset="0" bg="bg" display="flex" alignItems="center" justifyContent="center" px="4" textAlign="center">
                    <Text fontSize="sm" color="fg.muted">{errorMessage}</Text>
                </Box>
            )}
        </Box>
    );
}
