import { useEffect, useRef, useState } from 'react';
import { Box, Text } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import { LuLocateFixed } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { loadYmaps } from './ymapsLoader';

/**
 * Карта склада для курьера (pick-10): метка выдачи и кнопка «Где я» — своё положение относительно склада.
 * Без ключа Яндекс Карт или при ошибке загрузки карта молча не показывается: адрес и маршрут есть рядом.
 */
export default function WarehouseMap({ coords, title, height = 240 }) {
    const apiKey = usePage().props?.config?.yandex_maps_api_key || '';
    const containerRef = useRef(null);
    const mapRef = useRef(null);
    const meRef = useRef(null);
    const [status, setStatus] = useState('loading'); // loading | ready | hidden
    const [locating, setLocating] = useState(false);
    const [hint, setHint] = useState('');

    const hasCoords = Array.isArray(coords) && coords.length === 2;

    useEffect(() => {
        if (!apiKey || !hasCoords) { setStatus('hidden'); return undefined; }
        let cancelled = false;
        loadYmaps(apiKey).then((ymaps) => {
            if (cancelled || !containerRef.current) return;
            const map = new ymaps.Map(containerRef.current, {
                center: coords, zoom: 15, controls: ['zoomControl'],
            }, { suppressMapOpenBlock: true });
            map.geoObjects.add(new ymaps.Placemark(coords, { iconCaption: title || 'Склад' }, { preset: 'islands#redDotIconWithCaption' }));
            mapRef.current = map;
            setStatus('ready');
        }).catch(() => { if (!cancelled) setStatus('hidden'); });
        return () => { cancelled = true; mapRef.current?.destroy?.(); mapRef.current = null; };
    }, [apiKey, hasCoords, coords, title]);

    const locate = () => {
        if (!mapRef.current || !window.ymaps || !('geolocation' in navigator)) { setHint('Телефон не отдаёт местоположение'); return; }
        setLocating(true); setHint('');
        navigator.geolocation.getCurrentPosition(({ coords: c }) => {
            const me = [c.latitude, c.longitude];
            const ymaps = window.ymaps; const map = mapRef.current;
            if (meRef.current) map.geoObjects.remove(meRef.current);
            meRef.current = new ymaps.Placemark(me, { iconCaption: 'Вы здесь' }, { preset: 'islands#blueCircleDotIconWithCaption' });
            map.geoObjects.add(meRef.current);
            map.setBounds([[Math.min(me[0], coords[0]), Math.min(me[1], coords[1])], [Math.max(me[0], coords[0]), Math.max(me[1], coords[1])]], { checkZoomRange: true, zoomMargin: 40 });
            const km = distanceKm(me, coords);
            setHint(km < 1 ? `До склада около ${Math.round(km * 1000)} м` : `До склада около ${km.toFixed(1)} км по прямой`);
            setLocating(false);
        }, () => { setHint('Разрешите доступ к местоположению в браузере'); setLocating(false); }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
    };

    if (status === 'hidden') return null;

    return (
        <Box>
            <Box ref={containerRef} h={`${height}px`} borderRadius="lg" overflow="hidden" bg="bg.muted" />
            {status === 'ready' && (
                <Box mt="2">
                    <Button size="sm" variant="outline" onClick={locate} loading={locating}><LuLocateFixed /> Где я</Button>
                    {hint && <Text as="span" fontSize="sm" color="fg.muted" ml="3">{hint}</Text>}
                </Box>
            )}
        </Box>
    );
}

function distanceKm([lat1, lon1], [lat2, lon2]) {
    const r = 6371; const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1); const dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    return 2 * r * Math.asin(Math.sqrt(a));
}
