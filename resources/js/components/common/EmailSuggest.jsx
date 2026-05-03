import { Text } from '@chakra-ui/react';
import { DadataSuggest } from './DadataSuggest';

/**
 * Подсказки по email через DaData. Подбирает популярные домены и исправляет
 * опечатки (gmial → gmail).
 *
 * Props:
 * - value: string
 * - onChange: (val: string) => void
 * - placeholder, invalid, disabled
 * - все остальные пропсы пробрасываются в Input (autoFocus, type, и т.п.)
 */
export function EmailSuggest({ value, onChange, placeholder, invalid, disabled, ...rest }) {
    return (
        <DadataSuggest
            value={value}
            onChange={onChange}
            endpoint="/api/dadata/suggest/email"
            paramsBuilder={(query) => ({ query, count: 6 })}
            getDisplayValue={(item) => item?.value ?? ''}
            getKey={(item, idx) => item?.value ?? idx}
            renderItem={(item) => (
                <Text fontSize="sm">{item?.value}</Text>
            )}
            onSelect={(item) => onChange(item?.value ?? '')}
            placeholder={placeholder || 'your@email.com'}
            invalid={invalid}
            disabled={disabled}
            minChars={1}
            type="email"
            autoComplete="email"
            {...rest}
        />
    );
}
