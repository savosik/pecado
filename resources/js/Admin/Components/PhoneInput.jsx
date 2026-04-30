import { forwardRef } from 'react';
import { Input, Box } from '@chakra-ui/react';
import PhoneInputLib from 'react-phone-number-input';
import ru from 'react-phone-number-input/locale/ru.json';
import 'react-phone-number-input/style.css';

const CustomInput = forwardRef((props, ref) => (
    <Input
        ref={ref}
        color="gray.900"
        bg="white"
        borderColor="gray.300"
        _placeholder={{ color: 'gray.400' }}
        _dark={{
            color: 'gray.100',
            bg: 'gray.800',
            borderColor: 'gray.600',
            _placeholder: { color: 'gray.500' },
        }}
        {...props}
    />
));
CustomInput.displayName = 'CustomInput';

/**
 * Компонент ввода телефона с автоматической маской и выбором страны.
 * Использует 'react-phone-number-input' для форматирования.
 *
 * Props:
 * - value: string — текущее значение (отформатированное, e.g. +7999...)
 * - onChange: (value: string) => void — коллбэк
 * - placeholder: string — подсказка
 * - остальные пропсы передаются в <Input> (Chakra UI)
 */
export function PhoneInput({ value, onChange, placeholder, ...rest }) {
    return (
        <Box
            sx={{
                width: '100%',
                '.PhoneInput': { 
                    display: 'flex', 
                    alignItems: 'center',
                    width: '100%' 
                },
                '.PhoneInputCountry': { 
                    marginRight: '0.5rem' 
                },
            }}
        >
            <PhoneInputLib
                international
                defaultCountry="RU"
                labels={ru}
                value={value || ''}
                onChange={onChange}
                placeholder={placeholder || 'Введите номер телефона'}
                inputComponent={CustomInput}
                {...rest}
            />
        </Box>
    );
}
