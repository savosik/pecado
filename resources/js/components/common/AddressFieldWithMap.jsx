import { useMemo, useState } from 'react';
import { Box, HStack, IconButton, VStack } from '@chakra-ui/react';
import { LuMap, LuX } from 'react-icons/lu';
import axios from 'axios';
import { AddressSuggest } from './AddressSuggest';
import { YandexAddressMap } from './YandexAddressMap';
import { toaster } from '@/components/ui/toaster';

const parseCoords = (data) => {
    if (!data) return null;
    const lat = parseFloat(data.geo_lat);
    const lon = parseFloat(data.geo_lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
    return [lat, lon];
};

/**
 * Поле адреса с DaData-автокомплитом и опциональной Яндекс.Картой под ним.
 *
 * DaData — источник истины: и автокомплит, и reverse-geocode по координатам
 * с карты идут через `/api/dadata/geolocate/address`. Yandex — только UI.
 *
 * По умолчанию карта скрыта; справа от поля иконка-переключатель «Уточнить
 * на карте». Большинству хватит подсказок DaData, карта нужна точечно.
 *
 * Props:
 *  - value, onChange — текст адреса
 *  - addressData, onAddressDataChange — DaData data (фиас/координаты), опц.
 *  - country: 'RU'|null
 *  - placeholder, invalid, disabled
 *  - mapHeight: number (default 320)
 *  - mapInitiallyOpen: bool (default false)
 *  - enableMap: bool (default true) — можно полностью отключить кнопку и карту
 */
export function AddressFieldWithMap({
    value,
    onChange,
    addressData,
    onAddressDataChange,
    country = 'RU',
    placeholder,
    invalid,
    disabled,
    mapHeight = 320,
    mapInitiallyOpen = false,
    enableMap = true,
}) {
    const [reverseLoading, setReverseLoading] = useState(false);
    const [mapOpen, setMapOpen] = useState(mapInitiallyOpen);
    const coords = useMemo(() => parseCoords(addressData), [addressData]);

    const handleAddressSelected = (suggestion) => {
        const text = suggestion?.unrestricted_value || suggestion?.value || '';
        if (text) onChange(text);
        onAddressDataChange?.(suggestion?.data ?? null);
    };

    const handleMapPick = async ([lat, lon]) => {
        if (reverseLoading || disabled) return;
        setReverseLoading(true);
        try {
            const { data } = await axios.post('/api/dadata/geolocate/address', {
                lat,
                lon,
                count: 1,
                radius_meters: 200,
            });
            const item = data?.suggestions?.[0];
            if (!item) {
                toaster.create({
                    title: 'Адрес рядом не найден',
                    description: 'Попробуйте кликнуть ближе к нужному дому.',
                    type: 'info',
                });
                return;
            }
            const text = item.unrestricted_value || item.value || '';
            if (text) onChange(text);
            onAddressDataChange?.(item.data ?? null);
        } catch (err) {
            const status = err?.response?.status;
            toaster.create({
                title: status === 503 ? 'Сервис подсказок временно недоступен' : 'Не удалось определить адрес',
                type: 'error',
            });
        } finally {
            setReverseLoading(false);
        }
    };

    return (
        <VStack align="stretch" gap="3" w="full">
            {enableMap ? (
                <HStack gap="2" align="stretch" w="full">
                    <Box flex="1" minW="0">
                        <AddressSuggest
                            value={value}
                            onChange={onChange}
                            onAddressSelected={handleAddressSelected}
                            country={country}
                            placeholder={placeholder}
                            invalid={invalid}
                            disabled={disabled}
                        />
                    </Box>
                    <IconButton
                        type="button"
                        variant={mapOpen ? 'solid' : 'outline'}
                        size="md"
                        aria-label={mapOpen ? 'Скрыть карту' : 'Уточнить на карте'}
                        title={mapOpen ? 'Скрыть карту' : 'Уточнить на карте'}
                        onClick={() => setMapOpen((v) => !v)}
                        disabled={disabled}
                    >
                        {mapOpen ? <LuX /> : <LuMap />}
                    </IconButton>
                </HStack>
            ) : (
                <AddressSuggest
                    value={value}
                    onChange={onChange}
                    onAddressSelected={handleAddressSelected}
                    country={country}
                    placeholder={placeholder}
                    invalid={invalid}
                    disabled={disabled}
                />
            )}

            {enableMap && mapOpen && (
                <Box w="full">
                    <YandexAddressMap
                        coords={coords}
                        onCoordsPicked={handleMapPick}
                        height={mapHeight}
                        disabled={disabled || reverseLoading}
                    />
                </Box>
            )}
        </VStack>
    );
}
