import { Box, Text, HStack } from '@chakra-ui/react';
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
 * - placeholder, invalid, disabled
 * - rows — число строк (по умолчанию 1; передавайте >1 для мультистрочного режима)
 */
export function AddressSuggest({
    value,
    onChange,
    onAddressSelected,
    country = 'RU',
    placeholder,
    invalid,
    disabled,
    ...rest
}) {
    return (
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
}
