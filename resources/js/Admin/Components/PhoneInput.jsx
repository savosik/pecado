import { useState, useCallback, useRef } from 'react';
import { Input } from '@chakra-ui/react';

/**
 * Извлекает только цифры из строки
 */
function extractDigits(value) {
    return value.replace(/\D/g, '');
}

/**
 * Определяет формат маски по набранным цифрам.
 * Возвращает объект { prefix, groups, maxDigits }
 *
 * +375(XX)XXX-XX-XX  — Беларусь (12 цифр: 375 + 9)
 * +7(XXX)XXX-XX-XX   — Россия / Казахстан (11 цифр: 7 + 10)
 */
function detectMask(digits) {
    // Если начинается с 375 — Беларусь
    if (digits.startsWith('375')) {
        return {
            prefix: '375',
            // после +375: (XX) XXX-XX-XX  → 2 + 3 + 2 + 2 = 9
            groups: [2, 3, 2, 2],
            maxDigits: 12, // 375 + 9
        };
    }

    // По умолчанию — Россия/Казахстан (+7)
    return {
        prefix: '7',
        // после +7: (XXX) XXX-XX-XX  → 3 + 3 + 2 + 2 = 10
        groups: [3, 3, 2, 2],
        maxDigits: 11, // 7 + 10
    };
}

/**
 * Форматирует набор цифр в красивый вид: +7(XXX)XXX-XX-XX
 */
function formatPhone(digits) {
    if (!digits) return '';

    // Убираем ведущий 8 → заменяем на 7
    if (digits.startsWith('8') && !digits.startsWith('375')) {
        digits = '7' + digits.slice(1);
    }

    const mask = detectMask(digits);
    const prefixLen = mask.prefix.length;

    // Ограничиваем длину
    digits = digits.slice(0, mask.maxDigits);

    let result = '+' + mask.prefix;
    const rest = digits.slice(prefixLen);

    if (rest.length === 0) return result;

    let pos = 0;
    for (let i = 0; i < mask.groups.length; i++) {
        const groupLen = mask.groups[i];
        const chunk = rest.slice(pos, pos + groupLen);
        if (chunk.length === 0) break;

        if (i === 0) {
            result += '(' + chunk;
            if (chunk.length === groupLen) result += ')';
        } else if (i === 1) {
            result += chunk;
        } else {
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
 * - value: string — текущее значение
 * - onChange: (formatted: string) => void — коллбэк с отформатированным значением
 * - placeholder: string — подсказка (по умолчанию "+7(___) ___-__-__")
 * - остальные пропсы передаются в <Input>
 */
export function PhoneInput({ value, onChange, placeholder, ...rest }) {
    const inputRef = useRef(null);

    const handleChange = useCallback((e) => {
        const rawInput = e.target.value;
        let digits = extractDigits(rawInput);

        // Если пользователь начал с 8, заменяем на 7
        if (digits.startsWith('8') && !digits.startsWith('375')) {
            digits = '7' + digits.slice(1);
        }

        // Если ничего нет — очищаем
        if (digits.length === 0) {
            onChange('');
            return;
        }

        const formatted = formatPhone(digits);
        onChange(formatted);
    }, [onChange]);

    const handlePaste = useCallback((e) => {
        e.preventDefault();
        const pasted = e.clipboardData.getData('text');
        let digits = extractDigits(pasted);

        if (digits.startsWith('8') && !digits.startsWith('375')) {
            digits = '7' + digits.slice(1);
        }

        if (digits.length === 0) return;

        const formatted = formatPhone(digits);
        onChange(formatted);
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
            const formatted = formatPhone(newDigits);
            onChange(formatted);
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
            placeholder={placeholder || '+7(___) ___-__-__'}
            {...rest}
        />
    );
}
