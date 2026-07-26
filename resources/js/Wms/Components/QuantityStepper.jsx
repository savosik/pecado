import { Box, HStack, IconButton, Input } from '@chakra-ui/react';
import { LuMinus, LuPlus } from 'react-icons/lu';

/**
 * Количество с кнопками [-] N [+] — крупные тач-цели под работу на складе с телефона.
 *
 * Значение хранит родитель (число). Ручной ввод разрешён: чистим всё, кроме
 * цифр, и не даём опуститься ниже min. Пустое поле трактуем как min, но во время
 * ввода оставляем пустым, чтобы не мешать стирать и печатать заново.
 *
 * @param {number} value
 * @param {(n: number) => void} onChange
 * @param {number} [min=1]
 * @param {number} [max=10000]
 * @param {string} [size] — 'md' (48px, по умолчанию) или 'lg' (56px)
 */
export function QuantityStepper({ value, onChange, min = 1, max = 10000, size = 'md' }) {
    const box = size === 'lg' ? '56px' : '48px';
    const current = Number(value) || min;

    const clamp = (n) => Math.min(max, Math.max(min, n));

    const step = (delta) => onChange(clamp(current + delta));

    const handleInput = (event) => {
        const raw = event.target.value.replace(/\D/g, '');
        if (raw === '') {
            // Разрешаем пустое поле в процессе ввода — нормализуем на blur.
            onChange('');
            return;
        }
        onChange(clamp(Number(raw)));
    };

    const handleBlur = () => {
        if (value === '' || Number(value) < min) {
            onChange(min);
        }
    };

    return (
        <HStack gap={2}>
            <IconButton
                aria-label="Уменьшить количество"
                variant="outline"
                boxSize={box}
                onClick={() => step(-1)}
                disabled={current <= min}
            >
                <LuMinus />
            </IconButton>

            <Box>
                <Input
                    type="text"
                    inputMode="numeric"
                    value={value}
                    onChange={handleInput}
                    onBlur={handleBlur}
                    onFocus={(event) => event.target.select()}
                    textAlign="center"
                    fontWeight="bold"
                    fontSize="lg"
                    w="72px"
                    h={box}
                />
            </Box>

            <IconButton
                aria-label="Увеличить количество"
                variant="outline"
                boxSize={box}
                onClick={() => step(1)}
                disabled={current >= max}
            >
                <LuPlus />
            </IconButton>
        </HStack>
    );
}
