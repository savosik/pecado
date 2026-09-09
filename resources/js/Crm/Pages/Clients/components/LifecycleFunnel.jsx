import { Box, HStack, Text, Wrap, WrapItem } from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';
import { formatMoney } from '@/utils/formatPrice';

/**
 * Сумма в рублях коротко: «1,2 млн», «850 тыс.», «500».
 *
 * На чипе нет места под семь разрядов, а порядок величины важнее копеек —
 * точная сумма показывается подсказкой.
 *
 * @param {number} amount
 * @returns {string}
 */
export function formatCompactRub(amount) {
    const value = Number(amount) || 0;

    if (value >= 1_000_000) {
        return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(value / 1_000_000)} млн`;
    }

    if (value >= 1_000) {
        return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value / 1_000)} тыс.`;
    }

    return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value);
}

/**
 * Один чип воронки: стадия, число партнёров и их среднемесячные отгрузки.
 *
 * Цвет — тот же, что у бейджа стадии в строке и в карточке (см. цвет в
 * перечислении на сервере): лид фиолетовый, активный зелёный, риск оранжевый,
 * ушедший к конкуренту красный, остальные ушедшие серые. Активный чип
 * залит сплошным, остальные — светлым оттенком своего цвета.
 */
function StageChip({ label, color, count, amount, months, description, active, empty, onClick }) {
    const palette = color || 'gray';
    const hasMoney = amount !== null && amount !== undefined;

    const tooltip = [
        description,
        hasMoney
            ? `Среднемесячные отгрузки за ${months} мес.: ${formatMoney(amount)} ₽/мес`
            : null,
        active ? 'Нажмите ещё раз, чтобы снять отбор' : null,
    ].filter(Boolean).join('. ');

    return (
        <Tooltip content={tooltip} openDelay={400}>
            <Box
                as="button"
                type="button"
                onClick={onClick}
                aria-pressed={active}
                textAlign="left"
                px={3}
                py={1.5}
                borderRadius="lg"
                borderWidth="1px"
                cursor="pointer"
                transition="all 0.15s"
                opacity={empty && !active ? 0.55 : 1}
                bg={active ? `${palette}.solid` : `${palette}.subtle`}
                color={active ? `${palette}.contrast` : 'fg'}
                borderColor={active ? `${palette}.solid` : `${palette}.emphasized`}
                _hover={{
                    bg: active ? `${palette}.solid` : `${palette}.muted`,
                    borderColor: active ? `${palette}.solid` : `${palette}.fg`,
                }}
                _focusVisible={{ outline: '2px solid', outlineColor: `${palette}.fg`, outlineOffset: '1px' }}
            >
                <HStack gap={2} align="baseline">
                    <Text fontSize="sm" fontWeight="600" lineHeight="1.2">{label}</Text>
                    <Text fontSize="sm" fontWeight="700" lineHeight="1.2" fontVariantNumeric="tabular-nums">
                        {count}
                    </Text>
                </HStack>
                {hasMoney && (
                    <Text
                        fontSize="xs"
                        lineHeight="1.2"
                        mt={0.5}
                        color={active ? `${palette}.contrast` : 'fg.muted'}
                        opacity={active ? 0.85 : 1}
                        whiteSpace="nowrap"
                    >
                        {count > 0 && amount > 0 ? `≈ ${formatCompactRub(amount)} ₽/мес` : '—'}
                    </Text>
                )}
            </Box>
        </Tooltip>
    );
}

/**
 * Воронка партнёров — строка чипов от лида до ушедшего.
 *
 * Раздел должен читаться как воронка: «где, сколько и каких клиентов» — до
 * того, как менеджер начнёт листать таблицу. Чип работает и как фильтр по
 * стадии, но числа на чипах от него не зависят: они считаются по тому же
 * отбору, что и таблица, кроме самой стадии (см. ClientFunnelService).
 *
 * Группы («Работаем с партнёром» / «Больше не покупает») разделены зазором и
 * подписью — лестница обрывается ровно там, где партнёр перестаёт покупать.
 *
 * @param {{total: number, months: number, stages: Array}} funnel
 * @param {string|undefined} active — выбранная стадия (filters.lifecycle)
 * @param {Function} onSelect — (value | undefined) => void
 */
export default function LifecycleFunnel({ funnel, active, onSelect }) {
    if (!funnel?.stages?.length) return null;

    const groups = [];

    funnel.stages.forEach((stage) => {
        const found = groups.find(([key]) => key === stage.group);

        found ? found[2].push(stage) : groups.push([stage.group, stage.group_label, [stage]]);
    });

    const hasMoney = funnel.stages.some((stage) => stage.monthly_amount !== null && stage.monthly_amount !== undefined);
    const totalMonthly = hasMoney
        ? funnel.stages.reduce((sum, stage) => sum + (Number(stage.monthly_amount) || 0), 0)
        : null;

    return (
        <Box>
            <Wrap gap={2} align="flex-start">
                <WrapItem>
                    <StageChip
                        label="Все"
                        color="gray"
                        count={funnel.total}
                        amount={totalMonthly}
                        months={funnel.months}
                        description="Все партнёры текущего отбора"
                        active={!active}
                        empty={funnel.total === 0}
                        onClick={() => onSelect(undefined)}
                    />
                </WrapItem>

                {groups.map(([key, label, stages]) => (
                    <WrapItem key={key} alignItems="flex-start">
                        <HStack gap={2} align="flex-start">
                            <Text
                                fontSize="10px"
                                textTransform="uppercase"
                                letterSpacing="wide"
                                color="fg.muted"
                                whiteSpace="nowrap"
                                alignSelf="center"
                                pl={2}
                                borderLeftWidth="1px"
                                borderColor="border"
                                lineHeight="1.1"
                                maxW="64px"
                            >
                                {label}
                            </Text>
                            {stages.map((stage) => (
                                <StageChip
                                    key={stage.value}
                                    label={stage.label}
                                    color={stage.color}
                                    count={stage.count}
                                    amount={stage.monthly_amount}
                                    months={funnel.months}
                                    description={stage.description}
                                    active={active === stage.value}
                                    empty={stage.count === 0}
                                    onClick={() => onSelect(active === stage.value ? undefined : stage.value)}
                                />
                            ))}
                        </HStack>
                    </WrapItem>
                ))}
            </Wrap>
        </Box>
    );
}
