import { Badge, Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import MetricHint from '@/Crm/Components/MetricHint';
import { Button } from '@/components/ui/button';
import { fmtRub0 } from '../../../Salary/components/format';

const inputStyle = {
    padding: '0.4rem 0.55rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
    width: '100%',
    fontVariantNumeric: 'tabular-nums',
};

const MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

/**
 * Значение в человеческом виде — для истории и сравнения.
 */
export function formatValue(type, value) {
    if (value === null || value === undefined) return '—';
    switch (type) {
        case 'money': return fmtRub0(value);
        case 'share': return `${(Number(value) * 100).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} %`;
        case 'percent': return `${(Number(value) * 100).toLocaleString('ru-RU', { maximumFractionDigits: 3 })} %`;
        case 'percent_per_day': return `${(Number(value) * 100).toLocaleString('ru-RU', { maximumFractionDigits: 4 })} % в день`;
        case 'steps': return (value ?? []).map((s) => `${s.count} → ${fmtRub0(s.amount)}`).join('; ');
        case 'seasonal': return Object.entries(value ?? {}).map(([m, k]) => `${MONTHS[Number(m) - 1] ?? m} ${Number(k).toLocaleString('ru-RU')}`).join(', ');
        default: return String(value);
    }
}

/**
 * Поле одного параметра приказа: подпись, пункт Положения, подсказка, ввод.
 *
 * Доли и проценты вводятся в процентах, хранятся долей — пересчёт здесь,
 * чтобы руководитель не думал, сколько нулей в 0,0005.
 */
export default function ParamField({ param, value, original, disabled, onChange }) {
    const changed = JSON.stringify(value) !== JSON.stringify(original);

    return (
        <HStack align="start" gap={3} flexWrap={{ base: 'wrap', md: 'nowrap' }}>
            <Box flex="1" minW="220px">
                <HStack gap={1}>
                    <Text fontSize="sm" fontWeight="600">{param.label}</Text>
                    {param.hint && <MetricHint text={param.hint} />}
                    {changed && <Badge size="xs" colorPalette="blue" variant="subtle">изменено</Badge>}
                </HStack>
                <Text fontSize="xs" color="fg.subtle">п. {param.clause}</Text>
            </Box>
            <Box w={{ base: '100%', md: param.type === 'steps' || param.type === 'seasonal' ? '420px' : '200px' }}>
                <Input type={param.type} value={value} disabled={disabled} onChange={onChange} />
            </Box>
        </HStack>
    );
}

function Input({ type, value, disabled, onChange }) {
    if (type === 'steps') return <StepsEditor value={value ?? []} disabled={disabled} onChange={onChange} />;
    if (type === 'seasonal') return <SeasonalEditor value={value ?? {}} disabled={disabled} onChange={onChange} />;

    const scaled = ['share', 'percent', 'percent_per_day'].includes(type);
    const shown = value === null || value === undefined ? '' : (scaled ? round(Number(value) * 100, 4) : value);
    const unit = { money: '₽', share: '%', percent: '%', percent_per_day: '% в день', days: 'дн.', months: 'мес.', count: 'шт', day: 'число' }[type] ?? '';

    return (
        <HStack gap={2}>
            <input
                type="number"
                step={scaled ? 'any' : 1}
                style={inputStyle}
                value={shown}
                disabled={disabled}
                aria-label={unit}
                onChange={(e) => {
                    const raw = e.target.value;
                    if (raw === '') { onChange(null); return; }
                    const n = Number(raw);
                    onChange(scaled ? round(n / 100, 6) : n);
                }}
            />
            <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">{unit}</Text>
        </HStack>
    );
}

function StepsEditor({ value, disabled, onChange }) {
    const set = (i, key, v) => onChange(value.map((s, idx) => (idx === i ? { ...s, [key]: v === '' ? null : Number(v) } : s)));

    return (
        <VStack align="stretch" gap={1.5}>
            {value.map((step, i) => (
                <HStack key={i} gap={2}>
                    <Text fontSize="xs" color="fg.muted" w="64px">Ступень {i + 1}</Text>
                    <input type="number" style={{ ...inputStyle, width: '90px' }} value={step.count ?? ''} disabled={disabled} aria-label="Партнёров" onChange={(e) => set(i, 'count', e.target.value)} />
                    <Text fontSize="xs" color="fg.muted">партнёров →</Text>
                    <input type="number" style={{ ...inputStyle, width: '130px' }} value={step.amount ?? ''} disabled={disabled} aria-label="Премия, ₽" onChange={(e) => set(i, 'amount', e.target.value)} />
                    <Text fontSize="xs" color="fg.muted">₽</Text>
                    {!disabled && value.length > 1 && (
                        <Button size="xs" variant="ghost" onClick={() => onChange(value.filter((_, idx) => idx !== i))} aria-label="Убрать ступень">×</Button>
                    )}
                </HStack>
            ))}
            {!disabled && (
                <Button size="xs" variant="outline" alignSelf="start" onClick={() => onChange([...value, { count: null, amount: null }])}>Добавить ступень</Button>
            )}
        </VStack>
    );
}

function SeasonalEditor({ value, disabled, onChange }) {
    return (
        <SimpleGrid columns={{ base: 3, sm: 6 }} gap={1.5}>
            {MONTHS.map((label, i) => {
                const m = i + 1;
                return (
                    <VStack key={m} gap={0.5} align="stretch">
                        <Text fontSize="10px" color="fg.subtle" textAlign="center">{label}</Text>
                        <input
                            type="number"
                            step="0.05"
                            style={{ ...inputStyle, padding: '0.25rem 0.3rem', textAlign: 'center' }}
                            value={value[m] ?? value[String(m)] ?? 1}
                            disabled={disabled}
                            aria-label={`Коэффициент: ${label}`}
                            onChange={(e) => onChange({ ...value, [m]: e.target.value === '' ? 1 : Number(e.target.value) })}
                        />
                    </VStack>
                );
            })}
        </SimpleGrid>
    );
}

function round(n, digits) {
    const f = 10 ** digits;
    return Math.round(n * f) / f;
}
