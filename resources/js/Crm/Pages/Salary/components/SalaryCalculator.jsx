import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Badge, Box, Collapsible, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuCalculator, LuChevronDown, LuRotateCcw } from 'react-icons/lu';
import { Slider } from '@/components/ui/slider';
import { Button } from '@/components/ui/button';
import { fmtCompact, fmtRub0, fmtSigned, plural } from './format';

/**
 * Калькулятор зарплаты: три ползунка — выручка, клиенты, просрочка.
 *
 * Свёрнут по умолчанию: страница отвечает на вопрос «сколько я заработал»,
 * а калькулятор — на «сколько мог бы»; второй вопрос возникает не всегда,
 * и разворачивать его должен сам менеджер.
 *
 * Ползунки стоят на факте месяца, поэтому первое, что он видит, развернув, —
 * своя настоящая цифра; дальше двигает ручки и смотрит, как меняется доход.
 * Считает сервер тем же калькулятором, что и настоящий расчёт: своей формулы
 * на странице нет, иначе калькулятор и зарплата однажды разошлись бы.
 */
/**
 * Золотая рамка: граница красится градиентом, которого border-color не умеет.
 *
 * Два фоновых слоя — заливка панели по padding-box и золото по border-box;
 * верхний слой закрывает золото везде, кроме двух пикселей границы. Обычный
 * bg на этом же элементе задавать нельзя: background-color обрезается по
 * последнему clip (border-box) и закрашивает рамку целиком — именно так она
 * и пропала с экрана в первый раз.
 */
const GOLD_FRAME = {
    backgroundImage:
        'linear-gradient(var(--chakra-colors-bg-panel), var(--chakra-colors-bg-panel)),'
        + ' linear-gradient(135deg, #F7CB45 0%, #EDA92B 55%, #DE8A20 100%)',
    backgroundOrigin: 'border-box',
    backgroundClip: 'padding-box, border-box',
};

export default function SalaryCalculator({ calculation, month, managerId, canSeeAll }) {
    const inputs = calculation.inputs;
    const kpi = calculation.kpi;

    const factRevenue = Number(inputs.revenue ?? 0);
    const factClients = Number(inputs.active_count ?? 0);
    const factPenalty = Number(kpi?.penalty ?? 0);
    const planned = Number(inputs.planned_count ?? 0);
    const plan = Number(inputs.plan ?? 0);
    const riskAmount = Number(inputs.at_risk_amount ?? 0);

    const maxRevenue = Math.max(plan * 1.3, factRevenue * 1.3, 1);
    const maxPenalty = Math.max(factPenalty * 2, factPenalty + riskAmount * 1.5, 1);

    const [revenue, setRevenue] = useState(factRevenue);
    const [clients, setClients] = useState(factClients);
    const [penalty, setPenalty] = useState(factPenalty);
    const [result, setResult] = useState({ total: calculation.total, components: calculation.breakdown?.components ?? [] });
    const [busy, setBusy] = useState(false);
    const timer = useRef(null);

    const touched = Math.abs(revenue - factRevenue) > 1 || clients !== factClients || Math.abs(penalty - factPenalty) > 1;

    const simulate = useCallback(async (payload) => {
        setBusy(true);
        try {
            const res = await axios.post('/crm/salary/simulate', {
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
            setResult({ total: calculation.total, components: calculation.breakdown?.components ?? [] });
            return undefined;
        }

        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () => simulate({ revenue, active_clients: clients, penalty }),
            220,
        );

        return () => window.clearTimeout(timer.current);
    }, [revenue, clients, penalty, touched, simulate, calculation.total, calculation.breakdown]);

    const reset = () => {
        setRevenue(factRevenue);
        setClients(factClients);
        setPenalty(factPenalty);
    };

    const delta = Number(result.total ?? 0) - Number(calculation.total ?? 0);

    return (
        <Collapsible.Root
            borderWidth="2px"
            borderColor="transparent"
            borderRadius="xl"
            overflow="hidden"
            onOpenChange={(e) => { if (!e.open) reset(); }}
            css={GOLD_FRAME}
        >
            <Collapsible.Trigger asChild>
                <HStack
                    as="button"
                    type="button"
                    w="100%"
                    gap={3}
                    p={{ base: 4, md: 5 }}
                    textAlign="left"
                    cursor="pointer"
                    _hover={{ bg: 'bg.subtle' }}
                    transition="background 0.15s"
                >
                    <Box
                        p={2.5}
                        borderRadius="lg"
                        bg="yellow.subtle"
                        color="yellow.fg"
                        display="flex"
                        flexShrink={0}
                    >
                        <LuCalculator size={22} />
                    </Box>

                    <Box flex="1" minW={0}>
                        <Text fontWeight="700" fontSize={{ base: 'sm', md: 'md' }}>
                            Посчитайте, какой может быть зарплата
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            Три ползунка: выручка, клиенты, просрочки. Сразу видно, сколько выйдет.
                        </Text>
                    </Box>

                    <Box color="fg.muted" flexShrink={0} transition="transform 0.2s" css={{ '[data-state=open] &': { transform: 'rotate(180deg)' } }}>
                        <LuChevronDown size={20} />
                    </Box>
                </HStack>
            </Collapsible.Trigger>

            <Collapsible.Content>
                <Box px={{ base: 4, md: 5 }} pb={{ base: 4, md: 5 }} pt={1} borderTopWidth="1px" borderColor="border">
                    <HStack justify="flex-end" mb={2} minH="28px">
                        {touched && (
                            <Button size="xs" variant="ghost" onClick={reset}><LuRotateCcw /> Как есть сейчас</Button>
                        )}
                    </HStack>

                    <SimpleGrid columns={{ base: 1, lg: 2 }} gap={{ base: 5, lg: 8 }} alignItems="start">
                <VStack align="stretch" gap={6}>
                    <Control
                        label="Реализации за месяц"
                        value={fmtCompact(revenue)}
                        hint={plan > 0 ? `${Math.round((revenue / plan) * 100)} % плана` : 'плана нет'}
                        sliderValue={[revenue]}
                        max={maxRevenue}
                        step={Math.max(1000, Math.round(maxRevenue / 200 / 1000) * 1000)}
                        marks={plan > 0 ? [{ value: plan, label: 'план' }] : []}
                        onChange={(v) => setRevenue(v)}
                        factHint={Math.abs(revenue - factRevenue) > 1 ? `сейчас ${fmtCompact(factRevenue)}` : null}
                    />

                    <Control
                        label="Активных клиентов"
                        value={`${clients} из ${planned}`}
                        hint={planned > 0 ? `${Math.round((clients / planned) * 100)} % плановых` : 'плановых нет'}
                        sliderValue={[clients]}
                        max={Math.max(1, planned)}
                        step={1}
                        onChange={(v) => setClients(Math.round(v))}
                        factHint={clients !== factClients ? `сейчас ${factClients}` : null}
                    />

                    <Control
                        label="Вычет за просрочки"
                        value={fmtRub0(penalty)}
                        hint={penalty <= 0 ? 'все платят в срок' : 'вычитается из выручки'}
                        sliderValue={[penalty]}
                        max={maxPenalty}
                        step={Math.max(500, Math.round(maxPenalty / 200 / 500) * 500)}
                        onChange={(v) => setPenalty(v)}
                        factHint={Math.abs(penalty - factPenalty) > 1 ? `сейчас ${fmtRub0(factPenalty)}` : null}
                        tone="red"
                    />
                </VStack>

                <Box borderWidth="1px" borderColor="border" borderRadius="lg" p={4} bg="bg.subtle">
                    <Text fontSize="xs" color="fg.muted">{touched ? 'При этих условиях' : 'Сейчас'}</Text>
                    <HStack align="baseline" gap={3} flexWrap="wrap">
                        <Text fontSize={{ base: '3xl', md: '4xl' }} fontWeight="800" lineHeight="1.1" fontVariantNumeric="tabular-nums" opacity={busy ? 0.55 : 1}>
                            {fmtRub0(result.total)}
                        </Text>
                        {touched && Math.abs(delta) >= 1 && (
                            <Badge size="lg" colorPalette={delta > 0 ? 'green' : 'red'} variant="subtle" fontVariantNumeric="tabular-nums">
                                {fmtSigned(delta)}
                            </Badge>
                        )}
                    </HStack>

                    <VStack align="stretch" gap={2} mt={4}>
                        {(result.components ?? []).map((c) => (
                            <HStack key={c.key} justify="space-between" fontSize="sm">
                                <Text color="fg.muted">{c.label}</Text>
                                <Text fontWeight="600" fontVariantNumeric="tabular-nums">{fmtRub0(c.amount)}</Text>
                            </HStack>
                        ))}
                    </VStack>

                    {result.capped && (
                        <Text fontSize="xs" color="orange.fg" mt={3}>
                            Упёрлись в потолок премии — дальше доход не растёт.
                        </Text>
                    )}
                        </Box>
                    </SimpleGrid>
                </Box>
            </Collapsible.Content>
        </Collapsible.Root>
    );
}

function Control({ label, value, hint, sliderValue, max, step, marks, onChange, factHint, tone = 'blue' }) {
    return (
        <Box>
            <HStack justify="space-between" align="baseline" mb={1} flexWrap="wrap" gap={2}>
                <Text fontSize="sm" color="fg.muted">{label}</Text>
                <HStack gap={2} align="baseline">
                    <Text fontSize="lg" fontWeight="700" fontVariantNumeric="tabular-nums">{value}</Text>
                    <Text fontSize="xs" color="fg.subtle">{hint}</Text>
                </HStack>
            </HStack>
            <Slider
                size="md"
                colorPalette={tone}
                value={sliderValue}
                min={0}
                max={max}
                step={step}
                marks={marks}
                onValueChange={(e) => onChange(e.value[0])}
            />
            {factHint && <Text fontSize="10px" color="fg.subtle" mt={marks?.length ? 5 : 1}>{factHint}</Text>}
        </Box>
    );
}
