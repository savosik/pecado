import { useCallback, useEffect, useRef, useState } from 'react';
import { HStack, IconButton, Input } from '@chakra-ui/react';
import { LuMinus, LuPlus } from 'react-icons/lu';

const HOLD_INITIAL_DELAY = 320;
const HOLD_TICK_SLOW = 90;
const HOLD_TICK_FAST = 40;
const HOLD_ACCELERATE_AFTER = 1500;

/**
 * Контрол количества с кнопками +/- и числовым input.
 * Чистый controlled-компонент (без привязки к store).
 *
 * Long-press на кнопках +/- разгоняет счётчик: после 320 мс начинаются
 * автоматические тики каждые 90 мс, через 1.5 с — каждые 40 мс.
 * Когда достигнут min/max, вызывается onLimitReached('min'|'max').
 *
 * @param {{
 *   value: number,
 *   onChange: (value: number) => void,
 *   onLimitReached?: (which: 'min' | 'max') => void,
 *   min?: number,
 *   max?: number,
 *   size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl',
 *   fullWidth?: boolean,
 *   outerBorder?: boolean,
 * }} props
 */
export default function QuantityControl({
    value,
    onChange,
    onLimitReached,
    min = 1,
    max = 999,
    size = 'md',
    fullWidth = false,
    outerBorder = true,
}) {
    const clamp = useCallback((v) => Math.max(min, Math.min(max, Math.floor(Number(v) || min))), [min, max]);

    // Inputный clamp: если пользователь явно ввёл 0 (удаление через инпут),
    // пропускаем как есть — даже если min>0. Любое другое значение — обычный clamp.
    const inputClamp = useCallback((v) => {
        const n = Math.floor(Number(v));
        if (!Number.isFinite(n)) return min;
        if (n === 0) return 0;
        return Math.max(min, Math.min(max, n));
    }, [min, max]);

    // Актуальные ref-ы, чтобы long-press видел свежие значения внутри setTimeout.
    const valueRef = useRef(value);
    const minRef = useRef(min);
    const maxRef = useRef(max);
    useEffect(() => { valueRef.current = value; }, [value]);
    useEffect(() => { minRef.current = min; }, [min]);
    useEffect(() => { maxRef.current = max; }, [max]);

    const stepBy = useCallback((delta) => {
        const cur = valueRef.current;
        const next = Math.max(minRef.current, Math.min(maxRef.current, Math.floor(cur) + delta));
        if (next === cur) {
            onLimitReached?.(delta > 0 ? 'max' : 'min');
            return false;
        }
        onChange(next);
        return true;
    }, [onChange, onLimitReached]);

    // Hold-repeat: pointerdown сразу делает один шаг, дальше серия тиков.
    const holdTimerRef = useRef(null);
    const holdStartRef = useRef(0);

    const stopHold = useCallback(() => {
        if (holdTimerRef.current) {
            clearTimeout(holdTimerRef.current);
            holdTimerRef.current = null;
        }
    }, []);

    const startHold = useCallback((delta) => {
        stopHold();
        holdStartRef.current = Date.now();
        const schedule = (delay) => {
            holdTimerRef.current = setTimeout(() => {
                const ok = stepBy(delta);
                if (!ok) { stopHold(); return; }
                const elapsed = Date.now() - holdStartRef.current;
                schedule(elapsed > HOLD_ACCELERATE_AFTER ? HOLD_TICK_FAST : HOLD_TICK_SLOW);
            }, delay);
        };
        schedule(HOLD_INITIAL_DELAY);
    }, [stepBy, stopHold]);

    useEffect(() => stopHold, [stopHold]);

    const handlePointerDown = useCallback((sign) => (e) => {
        // Левая кнопка / основной палец — иначе не перехватываем
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        e.preventDefault();
        // Shift на клик — шаг ×10. Long-press остаётся одиночным шагом.
        const delta = sign * (e.shiftKey ? 10 : 1);
        stepBy(delta);
        startHold(sign);
        // capture, чтобы pointerup пришёл даже если палец уехал
        e.currentTarget.setPointerCapture?.(e.pointerId);
    }, [stepBy, startHold]);

    // Черновик ввода: пока поле в фокусе, можно стирать/печатать без
    // мгновенной отправки в стор. Onchange зовём только при blur с валидным
    // числом; пустой ввод откатывается к текущему value.
    const [draft, setDraft] = useState(null);
    const [focused, setFocused] = useState(false);

    const handleInputChange = useCallback((e) => {
        const raw = e.target.value.replace(/[^0-9]/g, '');
        setDraft(raw);
    }, []);

    const handleInputFocus = useCallback((e) => {
        setFocused(true);
        // Сразу выделяем текущее значение, чтобы можно было перепечатать одним движением.
        e.target.select?.();
    }, []);

    const handleInputBlur = useCallback(() => {
        setFocused(false);
        if (draft === null || draft === '') {
            setDraft(null);
            return;
        }
        const num = Number(draft);
        setDraft(null);
        if (Number.isFinite(num)) {
            const next = inputClamp(num);
            if (next !== valueRef.current) onChange(next);
        }
    }, [draft, inputClamp, onChange]);

    const handleInputKeyDown = useCallback((e) => {
        if (e.key === 'Enter') {
            e.currentTarget.blur();
        } else if (e.key === 'Escape') {
            setDraft(null);
            e.currentTarget.blur();
        }
    }, []);

    const displayValue = focused && draft !== null ? draft : String(value);

    const sizeMap = {
        xs: { btn: '2xs', inputW: '8', h: '6', fontSize: '2xs', iconSize: 12 },
        sm: { btn: 'xs', inputW: '10', h: '8', fontSize: 'xs', iconSize: 14 },
        md: { btn: 'sm', inputW: '12', h: '10', fontSize: 'sm', iconSize: 16 },
        lg: { btn: 'md', inputW: '14', h: '11', fontSize: 'md', iconSize: 16 },
        xl: { btn: 'lg', inputW: '16', h: '12', fontSize: 'lg', iconSize: 18 },
    };

    const s = sizeMap[size] || sizeMap.md;

    // Bump-анимация цифры при изменении значения.
    const prevValueRef = useRef(value);
    const [bumping, setBumping] = useState(false);
    useEffect(() => {
        if (prevValueRef.current === value) return;
        prevValueRef.current = value;
        setBumping(true);
        const t = setTimeout(() => setBumping(false), 90);
        return () => clearTimeout(t);
    }, [value]);

    return (
        <HStack
            gap="0"
            borderWidth={outerBorder ? '1px' : '0'}
            borderColor={outerBorder ? 'border' : 'transparent'}
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
                onPointerDown={handlePointerDown(-1)}
                onPointerUp={stopHold}
                onPointerLeave={stopHold}
                onPointerCancel={stopHold}
                aria-disabled={value <= min}
                opacity={value <= min ? 0.5 : 1}
                minW={s.h}
                h={s.h}
                style={{ touchAction: 'manipulation', userSelect: 'none' }}
            >
                <LuMinus size={s.iconSize || 14} />
            </IconButton>
            <Input
                value={displayValue}
                onChange={handleInputChange}
                onFocus={handleInputFocus}
                onBlur={handleInputBlur}
                onKeyDown={handleInputKeyDown}
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
                transform={bumping ? 'scale(1.18)' : 'scale(1)'}
                transition="transform 120ms ease-out"
                fontVariantNumeric="tabular-nums"
            />
            <IconButton
                aria-label="Увеличить"
                variant="ghost"
                size={s.btn}
                rounded="none"
                onPointerDown={handlePointerDown(1)}
                onPointerUp={stopHold}
                onPointerLeave={stopHold}
                onPointerCancel={stopHold}
                aria-disabled={value >= max}
                opacity={value >= max ? 0.5 : 1}
                minW={s.h}
                h={s.h}
                style={{ touchAction: 'manipulation', userSelect: 'none' }}
            >
                <LuPlus size={s.iconSize || 14} />
            </IconButton>
        </HStack>
    );
}
