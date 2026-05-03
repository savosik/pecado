import { Box, Text, HStack } from '@chakra-ui/react';
import { DadataSuggest } from './DadataSuggest';

/**
 * Подсказки по компаниям через DaData (по названию или по ИНН).
 *
 * Props:
 * - value: string — текущее значение «название»
 * - onChange: (val: string) => void — изменение текста
 * - onCompanySelected: (party) => void — выбран вариант, родитель раскладывает поля
 * - placeholder, invalid, disabled
 */
export function PartySuggest({ value, onChange, onCompanySelected, placeholder, invalid, disabled }) {
    return (
        <DadataSuggest
            value={value}
            onChange={onChange}
            endpoint="/api/dadata/suggest/party"
            paramsBuilder={(query) => ({ query, count: 10 })}
            getDisplayValue={(item) => item?.value ?? ''}
            getKey={(item, idx) => item?.data?.hid ?? item?.data?.inn ?? idx}
            renderItem={(item) => {
                const data = item?.data ?? {};
                const inn = data.inn || '—';
                const kpp = data.kpp ? ` / КПП ${data.kpp}` : '';
                const address = data.address?.value || '';
                return (
                    <Box>
                        <Text fontWeight="600" lineClamp={1}>{item?.value}</Text>
                        <HStack gap="2" color="fg.muted" fontSize="xs" mt="0.5">
                            <Text>ИНН {inn}{kpp}</Text>
                        </HStack>
                        {address && (
                            <Text color="fg.muted" fontSize="xs" lineClamp={1}>{address}</Text>
                        )}
                    </Box>
                );
            }}
            onSelect={(item) => onCompanySelected?.(item)}
            placeholder={placeholder || 'Начните вводить название или ИНН'}
            invalid={invalid}
            disabled={disabled}
            minChars={2}
        />
    );
}
