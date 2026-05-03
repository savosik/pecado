import { useState } from 'react';
import { Box, Text, HStack, IconButton, Spinner } from '@chakra-ui/react';
import { LuLocateFixed } from 'react-icons/lu';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';
import { DadataSuggest } from './DadataSuggest';

/**
 * Подсказки адресов через DaData (привязка к ФИАС/КЛАДР, индексу, координатам).
 *
 * Props:
 * - value: string — текущее значение поля адреса
 * - onChange: (val: string) => void — пользователь напечатал текст
 * - onAddressSelected: (suggestion) => void — пользователь выбрал вариант
 *   (передаётся объект suggestion целиком: value, unrestricted_value, data{...})
 * - country: string|null — фильтр по стране (по умолчанию RU; null = без фильтра)
 * - enableGeolocation: bool — показать кнопку «Определить мой адрес» (geolocation API)
 * - placeholder, invalid, disabled
 */
export function AddressSuggest({
    value,
    onChange,
    onAddressSelected,
    country = 'RU',
    enableGeolocation = false,
    placeholder,
    invalid,
    disabled,
    ...rest
}) {
    const [locating, setLocating] = useState(false);

    const handleLocate = () => {
        if (!('geolocation' in navigator)) {
            toaster.create({
                title: 'Геолокация не поддерживается браузером',
                type: 'error',
            });
            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            async ({ coords }) => {
                try {
                    const { data } = await axios.post('/api/dadata/geolocate/address', {
                        lat: coords.latitude,
                        lon: coords.longitude,
                        count: 1,
                        radius_meters: 200,
                    });
                    const item = data?.suggestions?.[0];
                    if (!item) {
                        toaster.create({
                            title: 'Адрес рядом не найден',
                            description: 'Введите адрес вручную.',
                            type: 'info',
                        });
                        return;
                    }
                    onChange(item.unrestricted_value || item.value || '');
                    onAddressSelected?.(item);
                    toaster.create({ title: 'Адрес определён', type: 'success' });
                } catch (err) {
                    const status = err?.response?.status;
                    if (status === 503) {
                        toaster.create({ title: 'Сервис подсказок временно недоступен', type: 'error' });
                    } else {
                        toaster.create({ title: 'Не удалось определить адрес', type: 'error' });
                    }
                } finally {
                    setLocating(false);
                }
            },
            (err) => {
                setLocating(false);
                if (err.code === err.PERMISSION_DENIED) {
                    toaster.create({
                        title: 'Доступ к геолокации запрещён',
                        description: 'Разрешите доступ в настройках браузера.',
                        type: 'warning',
                    });
                } else {
                    toaster.create({ title: 'Не удалось получить координаты', type: 'error' });
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
        );
    };

    const suggest = (
        <DadataSuggest
            value={value}
            onChange={onChange}
            endpoint="/api/dadata/suggest/address"
            paramsBuilder={(query) => {
                const payload = { query, count: 10 };
                if (country) {
                    payload.locations = [{ country_iso_code: country }];
                }
                return payload;
            }}
            getDisplayValue={(item) => item?.value ?? ''}
            getKey={(item, idx) => item?.data?.fias_id ?? item?.value ?? idx}
            renderItem={(item) => {
                const data = item?.data ?? {};
                const postal = data.postal_code ? `${data.postal_code}, ` : '';
                return (
                    <Box>
                        <Text lineClamp={2}>{postal}{item?.value}</Text>
                        {data.geo_lat && data.geo_lon && (
                            <HStack gap="2" color="fg.muted" fontSize="xs" mt="0.5">
                                <Text>{data.geo_lat}, {data.geo_lon}</Text>
                            </HStack>
                        )}
                    </Box>
                );
            }}
            onSelect={(item) => {
                onChange(item?.unrestricted_value || item?.value || '');
                onAddressSelected?.(item);
            }}
            placeholder={placeholder || 'Начните вводить адрес'}
            invalid={invalid}
            disabled={disabled}
            minChars={2}
            autoComplete="street-address"
            {...rest}
        />
    );

    if (!enableGeolocation) return suggest;

    return (
        <HStack gap="2" align="stretch" w="full">
            <Box flex="1">{suggest}</Box>
            <IconButton
                type="button"
                variant="outline"
                size="md"
                aria-label="Определить мой адрес"
                title="Определить мой адрес"
                onClick={handleLocate}
                disabled={disabled || locating}
            >
                {locating ? <Spinner size="xs" /> : <LuLocateFixed />}
            </IconButton>
        </HStack>
    );
}
