import { Box, Text, HStack } from '@chakra-ui/react';
import { DadataSuggest } from './DadataSuggest';

/**
 * Подсказки по банкам через DaData (по названию или БИК).
 *
 * Props:
 * - value: string — текущее значение «название банка»
 * - onChange: (val: string) => void
 * - onBankSelected: (bank) => void — выбран вариант, родитель раскладывает поля
 * - placeholder, invalid, disabled
 */
export function BankSuggest({ value, onChange, onBankSelected, placeholder, invalid, disabled }) {
    return (
        <DadataSuggest
            value={value}
            onChange={onChange}
            endpoint="/api/dadata/suggest/bank"
            paramsBuilder={(query) => ({ query, count: 10 })}
            getDisplayValue={(item) => item?.value ?? ''}
            getKey={(item, idx) => item?.data?.bic ?? idx}
            renderItem={(item) => {
                const data = item?.data ?? {};
                const bic = data.bic || '—';
                const corr = data.correspondent_account || '';
                const address = data.address?.value || '';
                return (
                    <Box>
                        <Text fontWeight="600" lineClamp={1}>{item?.value}</Text>
                        <HStack gap="2" color="fg.muted" fontSize="xs" mt="0.5">
                            <Text>БИК {bic}</Text>
                            {corr && <Text>• Корр.счёт {corr}</Text>}
                        </HStack>
                        {address && (
                            <Text color="fg.muted" fontSize="xs" lineClamp={1}>{address}</Text>
                        )}
                    </Box>
                );
            }}
            onSelect={(item) => onBankSelected?.(item)}
            placeholder={placeholder || 'Начните вводить название или БИК'}
            invalid={invalid}
            disabled={disabled}
            minChars={2}
        />
    );
}
