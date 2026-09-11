import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Box, Collapsible, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuCalculator, LuChevronDown, LuRotateCcw } from 'react-icons/lu';
import { Slider } from '@/components/ui/slider';
import { Button } from '@/components/ui/button';
import { fmtCompact, fmtRub, fmtSigned } from '../../Salary/components/format';

/**
 * Калькулятор: четыре ползунка, считает сервер той же формулой.
 *
 * Формулы на фронте нет намеренно — иначе ползунок и настоящий расчёт рано
 * или поздно разойдутся. Ползунки стоят на фактических значениях месяца;
 * долг задаётся среднедневным остатком — это база вычета, делённая на дни
 * месяца, и обратно она восстанавливается точно, без приближений.
 */
export default function MotivationCalculator({ calculation, month, managerId, canSeeAll }) {
    const lines = calculation.lines ?? [];
    const meta = (key) => lines.find((l) => l.key === key)?.meta ?? {};
    const daysInMonth = useMemo(() => {
        const [y, m] = String(month).split('-').map(Number);
        return new Date(y, m, 0).getDate() || 30;
    }, [month]);

    const fact = useMemo(() => ({
        base: Number(calculation.plan?.shipped ?? 0),
        newcomers: Number(meta('new_partners').revenue ?? 0),
        focus: Number(meta('focus').revenue ?? 0),
        debt: Math.round(Number(meta('overdue').integral ?? 0) / daysInMonth),
    }), [calculation, daysInMonth]); // eslint-disable-line react-hooks/exhaustive-deps

    const [base, setBase] = useState(fact.base);
    const [newcomers, setNewcomers] = useState(fact.newcomers);
    const [focus, setFocus] = useState(fact.focus);
    const [debt, setDebt] = useState(fact.debt);
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState(false);
    const timer = useRef(null);

    const touched = base !== fact.base || newcomers !== fact.newcomers || focus !== fact.focus || debt !== fact.debt;

    const simulate = useCallback(async (payload) => {
        setBusy(true);
        try {
            const res = await axios.post('/crm/motivation/simulate', {
                month,
                ...(canSeeAll && managerId ? { manager: managerId } : {}),
                ...payload,
            });
            setResult(res.data);
        } catch {
            // сеть моргнула — остаётся прошлый результат
        } finally {
            setBusy(false);
        }
    }, [month, managerId, canSeeAll]);

    useEffect(() => {
        if (!touched) {
            setResult(null);
            return undefined;
        }

        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => simulate({
            base_revenue: base,
            new_partners_revenue: newcomers,
            focus_revenue: focus,
            overdue_integral: debt * daysInMonth,
        }), 220);

        return () => window.clearTimeout(timer.current);
    }, [base, newcomers, focus, debt, touched, simulate, daysInMonth]);

    const reset = () => {
        setBase(fact.base);
        setNewcomers(fact.newcomers);
        setFocus(fact.focus);
        setDebt(fact.debt);
    };

    const total = result ? Number(result.total) : Number(calculation.total);
    const delta = result ? Number(result.delta) : 0;
    const parts = result?.variable_part;

    const planAmount = Number(calculation.plan?.amount ?? 0);
    const sliders = [
        { key: 'base', label: 'Отгрузки закреплённой базе', value: base, set: setBase, max: Math.max(planAmount * 2, fact.base * 2, 1_000_000), step: 50_000, tone: 'green' },
        { key: 'newcomers', label: 'Отгрузки новым партнёрам', value: newcomers, set: setNewcomers, max: Math.max(fact.newcomers * 3, 1_000_000), step: 10_000, tone: 'blue' },
        { key: 'focus', label: 'Фокус-товары', value: focus, set: setFocus, max: Math.max(fact.focus * 3, 500_000), step: 5_000, tone: 'purple' },
        { key: 'debt', label: 'Просроченный долг, в среднем за день', value: debt, set: setDebt, max: Math.max(fact.debt * 2, 1_000_000), step: 10_000, tone: 'orange' },
    ];

    return (
        <Collapsible.Root bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflow="hidden" onOpenChange={(e) => { if (!e.open) reset(); }}>
            <Collapsible.Trigger asChild>
                <HStack as="button" type="button" w="100%" gap={3} p={4} textAlign="left" cursor="pointer" _hover={{ bg: 'bg.subtle' }}>
                    <Box p={2} borderRadius="lg" bg="yellow.subtle" color="yellow.fg" display="flex" flexShrink={0}>
                        <LuCalculator size={18} />
                    </Box>
                    <Box flex="1" minW={0}>
                        <Text fontWeight="700" fontSize="sm">Посчитайте, каким может быть доход</Text>
                        <Text fontSize="xs" color="fg.muted">Четыре ползунка на фактических значениях месяца. Считает сервер той же формулой.</Text>
                    </Box>
                    <Box color="fg.subtle"><LuChevronDown size={18} /></Box>
                </HStack>
            </Collapsible.Trigger>

            <Collapsible.Content>
                <VStack align="stretch" gap={5} px={4} pb={4}>
                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={5}>
                        {sliders.map((s) => (
                            <VStack key={s.key} align="stretch" gap={1}>
                                <HStack justify="space-between" fontSize="sm">
                                    <Text color="fg.muted">{s.label}</Text>
                                    <Text fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub(s.value, 0)}</Text>
                                </HStack>
                                <Slider
                                    size="md"
                                    colorPalette={s.tone}
                                    value={[s.value]}
                                    min={0}
                                    max={s.max}
                                    step={s.step}
                                    aria-label={[s.label]}
                                    onValueChange={(e) => s.set(e.value[0])}
                                />
                                <HStack justify="space-between" fontSize="xs" color="fg.subtle">
                                    <Text>0</Text>
                                    <Text>{fmtCompact(s.max)}</Text>
                                </HStack>
                            </VStack>
                        ))}
                    </SimpleGrid>

                    <HStack justify="space-between" align="end" flexWrap="wrap" gap={3} p={4} borderRadius="lg" bg="bg.subtle">
                        <VStack align="start" gap={0}>
                            <Text fontSize="xs" color="fg.muted">{touched ? 'Получилось бы' : 'Сейчас'}</Text>
                            <HStack align="baseline" gap={2}>
                                <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" opacity={busy ? 0.6 : 1}>
                                    {fmtRub(total, 0)}
                                </Text>
                                {touched && result && (
                                    <Text fontWeight="700" color={delta >= 0 ? 'green.fg' : 'red.fg'}>{fmtSigned(delta)}</Text>
                                )}
                            </HStack>
                            {parts && (
                                <Text fontSize="xs" color="fg.subtle">
                                    П1 {fmtRub(parts.p1, 0)} · П2 {fmtRub(parts.p2, 0)} · П3 {fmtRub(parts.p3, 0)} · К1 −{fmtRub(parts.k1, 0)}
                                    {parts.capped ? ' · предельный размер' : ''}
                                    {parts.floored ? ' · не ниже нуля' : ''}
                                </Text>
                            )}
                        </VStack>
                        <Button size="sm" variant="ghost" onClick={reset} disabled={!touched}>
                            <LuRotateCcw size={14} /> Вернуть факт
                        </Button>
                    </HStack>
                </VStack>
            </Collapsible.Content>
        </Collapsible.Root>
    );
}
