import { useCallback, useRef } from 'react';
import { Input } from '@chakra-ui/react';

/**
 * Извлекает только цифры из строки
 */
function extractDigits(value) {
    return value.replace(/\D/g, '');
}

/**
 * Проверяет, является ли набор цифр (потенциально) белорусским номером.
 * Возвращает true если digits начинается с 3, 37 или 375.
 */
function isBelarus(digits) {
    return '375'.startsWith(digits.slice(0, 3)) && digits.startsWith('3');
}

/**
 * Форматирует набор цифр в красивый вид.
 *
 * Форматы:
 * +7(XXX)XXX-XX-XX    — Россия / Казахстан (11 цифр)
 * +375(XX)XXX-XX-XX   — Беларусь (12 цифр)
 */
function formatPhone(digits) {
    if (!digits) return '';

    // Замена 8 → 7 (российская конвенция)
    if (digits.charAt(0) === '8') {
        digits = '7' + digits.slice(1);
    }

    // Определяем тип номера
    if (digits.startsWith('375') || (digits.length < 3 && isBelarus(digits))) {
        // Беларусь: +375(XX)XXX-XX-XX
        const maxDigits = 12;
        digits = digits.slice(0, maxDigits);
        const prefix = '375';

        // Пока пользователь набирает код страны (3, 37, 375)
        if (digits.length <= prefix.length) {
            return '+' + digits;
        }

        const rest = digits.slice(prefix.length);
        return '+' + prefix + formatGroups(rest, [2, 3, 2, 2]);
    }

    // Россия / Казахстан: +7(XXX)XXX-XX-XX
    const maxDigits = 11;

    // Если первая цифра не 7, подставляем 7 (пользователь начал с цифры оператора)
    if (digits.charAt(0) !== '7') {
        digits = '7' + digits;
    }

    digits = digits.slice(0, maxDigits);
    const rest = digits.slice(1); // после "7"

    if (rest.length === 0) return '+7';

    return '+7' + formatGroups(rest, [3, 3, 2, 2]);
}

/**
 * Форматирует цифры по группам: (XX)XXX-XX-XX
 */
function formatGroups(digits, groups) {
    let result = '';
    let pos = 0;

    for (let i = 0; i < groups.length; i++) {
        const groupLen = groups[i];
        const chunk = digits.slice(pos, pos + groupLen);
        if (chunk.length === 0) break;

        if (i === 0) {
            // Первая группа оборачивается в скобки
            result += '(' + chunk;
            if (chunk.length === groupLen) result += ')';
        } else if (i === 1) {
            // Вторая группа без разделителя
            result += chunk;
        } else {
            // Остальные через дефис
            result += '-' + chunk;
        }
        pos += groupLen;
    }

    return result;
}

/**
 * Компонент ввода телефона с автоматической маской.
 * Поддерживает форматы:
 * - +7(XXX)XXX-XX-XX   (Россия, Казахстан)
 * - +375(XX)XXX-XX-XX  (Беларусь)
 *
 * Props:
 * - value: string — текущее значение (отформатированное)
 * - onChange: (formatted: string) => void — коллбэк с отформатированным значением
 * - placeholder: string — подсказка
 * - остальные пропсы передаются в <Input>
 */
export function PhoneInput({ value, onChange, placeholder, ...rest }) {
    const inputRef = useRef(null);

    const handleChange = useCallback((e) => {
        const rawInput = e.target.value;
        const digits = extractDigits(rawInput);

        if (digits.length === 0) {
            onChange('');
            return;
        }

        onChange(formatPhone(digits));
    }, [onChange]);

    const handlePaste = useCallback((e) => {
        e.preventDefault();
        const pasted = e.clipboardData.getData('text');
        const digits = extractDigits(pasted);

        if (digits.length === 0) return;

        onChange(formatPhone(digits));
    }, [onChange]);

    const handleKeyDown = useCallback((e) => {
        // При нажатии Backspace — удаляем последнюю цифру, а не символ форматирования
        if (e.key === 'Backspace' && value) {
            e.preventDefault();
            const digits = extractDigits(value);
            if (digits.length <= 1) {
                onChange('');
                return;
            }
            const newDigits = digits.slice(0, -1);
            onChange(formatPhone(newDigits));
        }
    }, [value, onChange]);

    return (
        <Input
            ref={inputRef}
            type="tel"
            value={value || ''}
            onChange={handleChange}
            onPaste={handlePaste}
            onKeyDown={handleKeyDown}
            placeholder={placeholder || 'Введите номер телефона'}
            {...rest}
        />
    );
}
