import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuRotateCcw } from 'react-icons/lu';
import { Slider } from '@/components/ui/slider';
import { Button } from '@/components/ui/button';
import { fmtCompact, fmtRub, fmtSigned } from '../../Salary/components/format';

const SLIDERS = [
    { key: 'base_revenue', label: 'Отгрузки базе', step: 50_000, tone: 'green', max: (f, plan) => Math.max(plan * 2, f * 2, 1_000_000) },
    { key: 'new_partners_revenue', label: 'Новым партнёрам', step: 10_000, tone: 'blue', max: (f) => Math.max(f * 3, 1_000_000) },
    { key: 'focus_revenue', label: 'Фокус-товары', step: 5_000, tone: 'purple', max: (f) => Math.max(f * 3, 500_000) },
    { key: 'debt_per_day', label: 'Просрочка, в среднем за день', step: 10_000, tone: 'orange', max: (f) => Math.max(f * 2, 1_000_000) },
];

/**
 * Калькулятор фонда отдела: ползунки по каждому работнику рядом, итого по отделу.
 *
 * Тот же серверный симулятор, что и на «Моём месяце», — формулы на фронте нет.
 * Каждый работник считается своим запросом; фонд — сумма ответов.
 */
export default function DepartmentCalculator({ month, rows }) {
    const daysInMonth = useMemo(() => {
        const [y, m] = String(month).split('-').map(Number);
        return new Date(y, m, 0).getDate() || 30;
    }, [month]);

    const facts = useMemo(() => Object.fromEntries(rows.map((r) => [r.manager.id, {
        base_revenue: Number(r.facts.base_revenue),
        new_partners_revenue: Number(r.facts.new_partners_revenue),
        focus_revenue: Number(r.facts.focus_revenue),
        debt_per_day: Math.round(Number(r.facts.overdue_integral) / daysInMonth),
    }])), [rows, daysInMonth]);

    const [values, setValues] = useState(facts);
    const [results, setResults] = useState({});
    const [busy, setBusy] = useState(false);
    const timer = useRef(null);

    useEffect(() => { setValues(facts); setResults({}); }, [facts]);

    const touchedIds = rows.map((r) => r.manager.id).filter((id) => SLIDERS.some((s) => values[id]?.[s.key] !== facts[id]?.[s.key]));
    const touched = touchedIds.length > 0;

    useEffect(() => {
        if (!touched) {
            setResults({});
            return undefined;
        }

        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(async () => {
            setBusy(true);
            try {
                const answers = await Promise.all(touchedIds.map(async (id) => {
                    const v = values[id];
                    const res = await axios.post('/crm/motivation/simulate', {
                        month,
                        manager: id,
                        base_revenue: v.base_revenue,
                        new_partners_revenue: v.new_partners_revenue,
                        focus_revenue: v.focus_revenue,
                        overdue_integral: v.debt_per_day * daysInMonth,
                    });
                    return [id, res.data];
                }));
                setResults(Object.fromEntries(answers));
            } catch {
                // сеть моргнула — остаётся прошлый результат
            } finally {
                setBusy(false);
            }
        }, 250);

        return () => window.clearTimeout(timer.current);
    }, [values, touched, month, daysInMonth]); // eslint-disable-line react-hooks/exhaustive-deps

    const totalOf = (r) => (results[r.manager.id] ? Number(results[r.manager.id].total) : Number(r.scenarios.current.total));
    const baselineOf = (r) => Number(r.scenarios.current.total);
    const fund = rows.reduce((s, r) => s + totalOf(r), 0);
    const fundBaseline = rows.reduce((s, r) => s + baselineOf(r), 0);

    if (rows.length === 0) {
        return null;
    }

    return (
        <VStack align="stretch" gap={4}>
            <SimpleGrid columns={{ base: 1, lg: rows.length > 1 ? 2 : 1 }} gap={4}>
                {rows.map((r) => {
                    const id = r.manager.id;
                    const v = values[id] ?? facts[id];
                    const res = results[id];
                    const rowTouched = touchedIds.includes(id);
                    const parts = res?.variable_part;
                    return (
                        <Box key={id} bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                            <HStack justify="space-between" align="baseline" mb={3} flexWrap="wrap" gap={2}>
                                <Text fontWeight="700">{r.manager.name}</Text>
                                <HStack align="baseline" gap={2}>
                                    <Text fontSize="xl" fontWeight="800" fontVariantNumeric="tabular-nums" opacity={busy && rowTouched ? 0.6 : 1}>{fmtRub(totalOf(r), 0)}</Text>
                                    {rowTouched && res && <Text fontWeight="700" fontSize="sm" color={res.delta >= 0 ? 'green.fg' : 'red.fg'}>{fmtSigned(res.delta)}</Text>}
                                </HStack>
                            </HStack>
                            <VStack align="stretch" gap={3}>
                                {SLIDERS.map((s) => {
                                    const max = s.max(facts[id][s.key], Number(r.plan ?? 0));
                                    return (
                                        <VStack key={s.key} align="stretch" gap={0}>
                                            <HStack justify="space-between" fontSize="xs">
                                                <Text color="fg.muted">{s.label}</Text>
                                                <Text fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub(v[s.key], 0)}</Text>
                                            </HStack>
                                            <Slider
                                                size="sm"
                                                colorPalette={s.tone}
                                                value={[v[s.key]]}
                                                min={0}
                                                max={max}
                                                step={s.step}
                                                aria-label={[`${r.manager.name}: ${s.label}`]}
                                                onValueChange={(e) => setValues((prev) => ({ ...prev, [id]: { ...prev[id], [s.key]: e.value[0] } }))}
                                            />
                                            <HStack justify="space-between" fontSize="2xs" color="fg.subtle"><Text>0</Text><Text>{fmtCompact(max)}</Text></HStack>
                                        </VStack>
                                    );
                                })}
                            </VStack>
                            {parts && (
                                <Text fontSize="xs" color="fg.subtle" mt={2}>
                                    П1 {fmtRub(parts.p1, 0)} · П2 {fmtRub(parts.p2, 0)} · П3 {fmtRub(parts.p3, 0)} · К1 −{fmtRub(parts.k1, 0)}
                                    {parts.capped ? ' · предельный размер' : ''}{parts.floored ? ' · не ниже нуля' : ''}
                                </Text>
                            )}
                        </Box>
                    );
                })}
            </SimpleGrid>

            <HStack justify="space-between" align="end" flexWrap="wrap" gap={3} p={4} borderRadius="xl" bg="bg.subtle">
                <VStack align="start" gap={0}>
                    <Text fontSize="xs" color="fg.muted">{touched ? 'Фонд отдела получился бы' : 'Фонд отдела сейчас'}</Text>
                    <HStack align="baseline" gap={2}>
                        <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" opacity={busy ? 0.6 : 1}>{fmtRub(fund, 0)}</Text>
                        {touched && <Text fontWeight="700" color={fund - fundBaseline >= 0 ? 'green.fg' : 'red.fg'}>{fmtSigned(fund - fundBaseline)}</Text>}
                    </HStack>
                    <Text fontSize="xs" color="fg.subtle">Без квартальной премии. Считает сервер той же формулой, что и расчётный лист.</Text>
                </VStack>
                <Button size="sm" variant="ghost" onClick={() => setValues(facts)} disabled={!touched}>
                    <LuRotateCcw size={14} /> Вернуть факт
                </Button>
            </HStack>
        </VStack>
    );
}
