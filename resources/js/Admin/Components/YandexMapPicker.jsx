import { useEffect, useRef, useCallback, useState } from 'react';
import { Box, Input, SimpleGrid, Text } from '@chakra-ui/react';

const YANDEX_MAPS_SCRIPT_ID = 'yandex-maps-api';

function loadYandexMapsApi(apiKey) {
    return new Promise((resolve, reject) => {
        if (window.ymaps) {
            resolve(window.ymaps);
            return;
        }

        const existingScript = document.getElementById(YANDEX_MAPS_SCRIPT_ID);
        if (existingScript) {
            // Script is loading, wait for it
            const checkInterval = setInterval(() => {
                if (window.ymaps) {
                    clearInterval(checkInterval);
                    resolve(window.ymaps);
                }
            }, 100);
            return;
        }

        const script = document.createElement('script');
        script.id = YANDEX_MAPS_SCRIPT_ID;
        script.src = `https://api-maps.yandex.ru/2.1/?apikey=${apiKey}&lang=ru_RU`;
        script.async = true;
        script.onload = () => {
            window.ymaps.ready(() => {
                resolve(window.ymaps);
            });
        };
        script.onerror = () => reject(new Error('Не удалось загрузить Яндекс Карты'));
        document.head.appendChild(script);
    });
}

/**
 * Компонент для выбора точки на Яндекс Карте.
 *
 * @param {Object} props
 * @param {number|string|null} props.latitude - Широта
 * @param {number|string|null} props.longitude - Долгота
 * @param {function} props.onChange - Callback: onChange(lat, lng)
 * @param {string} props.apiKey - Яндекс Maps API ключ
 * @param {string} [props.height='400px'] - Высота карты
 */
export function YandexMapPicker({ latitude, longitude, onChange, apiKey, height = '400px' }) {
    const mapContainerRef = useRef(null);
    const mapInstanceRef = useRef(null);
    const placemarkRef = useRef(null);
    const [mapError, setMapError] = useState(null);
    const [isLoading, setIsLoading] = useState(true);

    const defaultCenter = [55.751244, 37.618423]; // Москва
    const defaultZoom = 10;

    const getCenter = useCallback(() => {
        const lat = parseFloat(latitude);
        const lng = parseFloat(longitude);
        if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
            return [lat, lng];
        }
        return defaultCenter;
    }, [latitude, longitude]);

    const handleMapClick = useCallback((coords) => {
        const [lat, lng] = coords;
        onChange(lat.toFixed(7), lng.toFixed(7));
    }, [onChange]);

    // Инициализация карты
    useEffect(() => {
        if (!apiKey) {
            setMapError('API ключ Яндекс Карт не задан');
            setIsLoading(false);
            return;
        }

        let isMounted = true;

        loadYandexMapsApi(apiKey)
            .then((ymaps) => {
                if (!isMounted || !mapContainerRef.current) return;

                const center = getCenter();

                const map = new ymaps.Map(mapContainerRef.current, {
                    center: center,
                    zoom: center === defaultCenter ? defaultZoom : 15,
                    controls: ['zoomControl', 'searchControl', 'typeSelector'],
                });

                mapInstanceRef.current = map;

                // Добавить маркер если есть координаты
                const lat = parseFloat(latitude);
                const lng = parseFloat(longitude);
                if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                    const placemark = new ymaps.Placemark([lat, lng], {}, {
                        draggable: true,
                        preset: 'islands#redDotIcon',
                    });

                    placemark.events.add('dragend', () => {
                        const coords = placemark.geometry.getCoordinates();
                        handleMapClick(coords);
                    });

                    map.geoObjects.add(placemark);
                    placemarkRef.current = placemark;
                }

                // Клик по карте — установка/перемещение маркера
                map.events.add('click', (e) => {
                    const coords = e.get('coords');

                    if (placemarkRef.current) {
                        placemarkRef.current.geometry.setCoordinates(coords);
                    } else {
                        const placemark = new ymaps.Placemark(coords, {}, {
                            draggable: true,
                            preset: 'islands#redDotIcon',
                        });

                        placemark.events.add('dragend', () => {
                            const coordinates = placemark.geometry.getCoordinates();
                            handleMapClick(coordinates);
                        });

                        map.geoObjects.add(placemark);
                        placemarkRef.current = placemark;
                    }

                    handleMapClick(coords);
                });

                setIsLoading(false);
            })
            .catch((err) => {
                if (isMounted) {
                    setMapError(err.message);
                    setIsLoading(false);
                }
            });

        return () => {
            isMounted = false;
            if (mapInstanceRef.current) {
                mapInstanceRef.current.destroy();
                mapInstanceRef.current = null;
                placemarkRef.current = null;
            }
        };
    }, [apiKey]);

    // Обновление позиции маркера при ручном изменении координат
    useEffect(() => {
        if (!mapInstanceRef.current || !window.ymaps) return;

        const lat = parseFloat(latitude);
        const lng = parseFloat(longitude);

        if (isNaN(lat) || isNaN(lng)) return;

        if (placemarkRef.current) {
            const currentCoords = placemarkRef.current.geometry.getCoordinates();
            if (Math.abs(currentCoords[0] - lat) > 0.0000001 || Math.abs(currentCoords[1] - lng) > 0.0000001) {
                placemarkRef.current.geometry.setCoordinates([lat, lng]);
                mapInstanceRef.current.setCenter([lat, lng]);
            }
        } else {
            const placemark = new window.ymaps.Placemark([lat, lng], {}, {
                draggable: true,
                preset: 'islands#redDotIcon',
            });

            placemark.events.add('dragend', () => {
                const coords = placemark.geometry.getCoordinates();
                handleMapClick(coords);
            });

            mapInstanceRef.current.geoObjects.add(placemark);
            placemarkRef.current = placemark;
            mapInstanceRef.current.setCenter([lat, lng], 15);
        }
    }, [latitude, longitude]);

    if (mapError) {
        return (
            <Box
                p={4}
                borderWidth="1px"
                borderRadius="md"
                borderStyle="dashed"
                textAlign="center"
                bg="red.50"
                color="red.600"
            >
                <Text>{mapError}</Text>
            </Box>
        );
    }

    return (
        <Box>
            <Box
                ref={mapContainerRef}
                height={height}
                borderRadius="md"
                overflow="hidden"
                borderWidth="1px"
                position="relative"
            >
                {isLoading && (
                    <Box
                        position="absolute"
                        top={0}
                        left={0}
                        right={0}
                        bottom={0}
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                        bg="bg.subtle"
                        zIndex={1}
                    >
                        <Text color="fg.muted">Загрузка карты...</Text>
                    </Box>
                )}
            </Box>
            <Text fontSize="xs" color="fg.muted" mt={1}>
                Кликните на карту для выбора точки или перетащите маркер
            </Text>
            <SimpleGrid columns={2} gap={2} mt={2}>
                <Input
                    size="sm"
                    placeholder="Широта"
                    value={latitude || ''}
                    onChange={(e) => onChange(e.target.value, longitude || '')}
                />
                <Input
                    size="sm"
                    placeholder="Долгота"
                    value={longitude || ''}
                    onChange={(e) => onChange(latitude || '', e.target.value)}
                />
            </SimpleGrid>
        </Box>
    );
}
