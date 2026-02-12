import { useCallback } from 'react';
import { HStack, IconButton, Input } from '@chakra-ui/react';
import { LuMinus, LuPlus } from 'react-icons/lu';

/**
 * Контрол количества с кнопками +/- и числовым input.
 * Чистый controlled-компонент (без привязки к store).
 *
 * @param {{
 *   value: number,
 *   onChange: (value: number) => void,
 *   min?: number,
 *   max?: number,
 *   size?: 'sm' | 'md' | 'lg',
 * }} props
 */
export default function QuantityControl({ value, onChange, min = 1, max = 999, size = 'md' }) {
    const clamp = useCallback((v) => Math.max(min, Math.min(max, Math.floor(Number(v) || min))), [min, max]);

    const handleDecrement = useCallback(() => {
        onChange(clamp(value - 1));
    }, [value, onChange, clamp]);

    const handleIncrement = useCallback(() => {
        onChange(clamp(value + 1));
    }, [value, onChange, clamp]);

    const handleInputChange = useCallback((e) => {
        const raw = e.target.value.replace(/[^0-9]/g, '');
        if (raw === '') {
            onChange(min);
            return;
        }
        onChange(clamp(Number(raw)));
    }, [onChange, clamp, min]);

    const sizeMap = {
        sm: { btn: 'xs', inputW: '10', h: '7', fontSize: 'xs' },
        md: { btn: 'sm', inputW: '12', h: '9', fontSize: 'sm' },
        lg: { btn: 'md', inputW: '14', h: '10', fontSize: 'md' },
    };

    const s = sizeMap[size] || sizeMap.md;

    return (
        <HStack
            gap="0"
            borderWidth="1px"
            borderColor="border"
            rounded="md"
            overflow="hidden"
            display="inline-flex"
        >
            <IconButton
                aria-label="Уменьшить"
                variant="ghost"
                size={s.btn}
                rounded="none"
                onClick={handleDecrement}
                disabled={value <= min}
                minW={s.h}
                h={s.h}
            >
                <LuMinus size={14} />
            </IconButton>
            <Input
                value={String(value)}
                onChange={handleInputChange}
                textAlign="center"
                w={s.inputW}
                h={s.h}
                fontSize={s.fontSize}
                border="none"
                rounded="none"
                px="1"
                inputMode="numeric"
                pattern="[0-9]*"
                _focus={{ boxShadow: 'none' }}
            />
            <IconButton
                aria-label="Увеличить"
                variant="ghost"
                size={s.btn}
                rounded="none"
                onClick={handleIncrement}
                disabled={value >= max}
                minW={s.h}
                h={s.h}
            >
                <LuPlus size={14} />
            </IconButton>
        </HStack>
    );
}
