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
 *   size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl',
 *   fullWidth?: boolean,
 * }} props
 */
export default function QuantityControl({ value, onChange, min = 1, max = 999, size = 'md', fullWidth = false }) {
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
        xs: { btn: '2xs', inputW: '8', h: '6', fontSize: '2xs', iconSize: 12 },
        sm: { btn: 'xs', inputW: '10', h: '8', fontSize: 'xs', iconSize: 14 },
        md: { btn: 'sm', inputW: '12', h: '10', fontSize: 'sm', iconSize: 16 },
        lg: { btn: 'md', inputW: '14', h: '11', fontSize: 'md', iconSize: 16 },
        xl: { btn: 'lg', inputW: '16', h: '12', fontSize: 'lg', iconSize: 18 },
    };

    const s = sizeMap[size] || sizeMap.md;

    return (
        <HStack
            gap="0"
            borderWidth="1px"
            borderColor="border"
            rounded="md"
            overflow="hidden"
            display={fullWidth ? 'flex' : 'inline-flex'}
            w={fullWidth ? '100%' : undefined}
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
                <LuMinus size={s.iconSize || 14} />
            </IconButton>
            <Input
                value={String(value)}
                onChange={handleInputChange}
                textAlign="center"
                w={fullWidth ? undefined : s.inputW}
                flex={fullWidth ? '1' : undefined}
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
                <LuPlus size={s.iconSize || 14} />
            </IconButton>
        </HStack>
    );
}
