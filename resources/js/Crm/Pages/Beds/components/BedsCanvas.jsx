import { useMemo } from 'react';
import { Box, HStack, Progress, Text, VStack } from '@chakra-ui/react';
import { ResponsiveContainer, Tooltip, Treemap } from 'recharts';
import { useColorMode } from '@/components/ui/color-mode';
import { bedFill, bedInk, bedLegend } from './bedScale';

const money = (value) => `${Number(value ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`;

const compact = (value) => {
    const n = Math.abs(Number(value ?? 0));
    const trim = (x) => x.toFixed(1).replace(/\.0$/, '').replace('.', ',');
    if (n >= 1_000_000) return `${trim(n / 1_000_000)} млн`;
    if (n >= 1_000) return `${trim(n / 1_000)} тыс`;

    return String(Math.round(n));
};

/** Плитка, на которой ещё помещается имя. Меньше — только tooltip. */
const NAME_FITS = { w: 74, h: 34 };

/** Плитка, на которой помещается и вторая строка с цифрой. */
const VALUE_FITS = { w: 96, h: 54 };

function TileContent(props) {
    const { x, y, width, height, index, dark, onSelect } = props;
    const tile = props.payload ?? props;

    if (!(width > 0) || !(height > 0)) return null;

    const unallocated = tile.kind === 'unallocated';
    const fill = unallocated ? 'transparent' : bedFill(tile, dark);
    const ink = unallocated ? (dark ? '#8b8a83' : '#77756e') : bedInk(tile, dark);
    const showName = width >= NAME_FITS.w && height >= NAME_FITS.h;
    const showValue = width >= VALUE_FITS.w && height >= VALUE_FITS.h;

    return (
        <g
            onClick={() => !unallocated && onSelect?.(tile)}
            style={{ cursor: unallocated ? 'default' : 'pointer' }}
        >
            {index === 0 && (
                <defs>
                    {/* Штриховка «заросло»: признак, который виден и в ч/б,
                        и при любой форме дальтонизма — он не про цвет. */}
                    <pattern id="bedOvergrown" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                        <line x1="0" y1="0" x2="0" y2="8" stroke={dark ? '#ffffff' : '#000000'} strokeWidth="2" opacity="0.22" />
                    </pattern>
                    <pattern id="bedUnallocated" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                        <line x1="0" y1="0" x2="0" y2="10" stroke={dark ? '#6f6e67' : '#b8b6ae'} strokeWidth="2" />
                    </pattern>
                </defs>
            )}

            <rect
                x={x}
                y={y}
                width={width}
                height={height}
                fill={unallocated ? 'url(#bedUnallocated)' : fill}
                // 2px зазор фоном, чтобы соседние грядки не слипались в пятно.
                stroke={dark ? '#1a1a19' : '#fcfcfb'}
                strokeWidth={2}
            />

            {tile.sleeping && !unallocated && (
                <rect x={x} y={y} width={width} height={height} fill="url(#bedOvergrown)" stroke="none" />
            )}

            {showName && (
                <text x={x + 8} y={y + 18} fill={ink} fontSize={12} fontWeight={600}>
                    {String(tile.name ?? '').slice(0, Math.max(3, Math.floor(width / 7)))}
                </text>
            )}

            {showValue && (
                <text x={x + 8} y={y + 34} fill={ink} fontSize={11} opacity={0.85}>
                    {unallocated
                        ? compact(tile.area)
                        : `${tile.percent === null || tile.percent === undefined ? '—' : `${tile.percent}%`} · ${compact(tile.fact)}`}
                </text>
            )}

            {showValue && tile.sleeping && !unallocated && (
                <text x={x + 8} y={y + 49} fill={ink} fontSize={10} opacity={0.85}>
                    заросло {tile.overdue_days} дн.
                </text>
            )}
        </g>
    );
}

function BedTooltip({ active, payload, mode }) {
    if (!active || !payload || payload.length === 0) return null;

    const tile = payload[0]?.payload;
    if (!tile) return null;

    if (tile.kind === 'unallocated') {
        return (
            <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md" maxW="260px">
                <Text fontSize="sm" fontWeight="600">Не распределено</Text>
                <Text fontSize="xs" color="fg.muted">
                    {money(tile.area)} плана периода не разложено по {mode === 'managers' ? 'менеджерам' : 'партнёрам'}.
                </Text>
            </Box>
        );
    }

    return (
        <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md" maxW="280px">
            <Text fontSize="sm" fontWeight="600">{tile.name}</Text>
            <Text fontSize="xs" color="fg.muted">
                {tile.plan === null
                    ? `Плана нет · размер по обороту ${money(tile.area)}/мес`
                    : `План ${money(tile.plan)} · факт ${money(tile.fact)} · ${tile.percent ?? 0}%`}
            </Text>
            {tile.lag > 0 && (
                <Text fontSize="xs" color="fg.muted">Недобор {money(tile.lag)}</Text>
            )}
            {tile.sleeping && (
                <Text fontSize="xs" color="fg.muted">
                    Не покупает {tile.days_since} дн. при цикле {tile.cycle_days}
                </Text>
            )}
            {mode === 'managers' && (
                <Text fontSize="xs" color="fg.muted">Партнёров с отгрузками: {tile.clients_count}</Text>
            )}
            <Text fontSize="xs" color="fg.subtle" mt={1}>Нажмите, чтобы открыть</Text>
        </Box>
    );
}

/**
 * Полотно грядок: план периода прямоугольником, внутри плитки.
 *
 * Площадь — масштаб (план, а без плана — средний месячный оборот), заливка —
 * выполнение. Клик проваливает внутрь. Никаких эффектов сверх этого:
 * увлекательность здесь даёт читаемая картинка и быстрый провал, а не анимации.
 *
 * На узком экране treemap заменяется списком: плитка в четверть пальца
 * не читается ни глазом, ни пальцем.
 */
export default function BedsCanvas({ canvas, onSelect }) {
    const { colorMode } = useColorMode();
    const dark = colorMode === 'dark';

    const data = useMemo(() => {
        const tiles = (canvas?.tiles ?? []).map((tile) => ({ ...tile, size: tile.area }));

        // Нераспределённый остаток — такая же площадь полотна: видно, что план
        // периода расписан не весь. Без него картинка врёт, показывая сумму
        // плиток за целое.
        if ((canvas?.unallocated ?? 0) > 0) {
            tiles.push({
                kind: 'unallocated',
                name: 'Не распределено',
                area: canvas.unallocated,
                size: canvas.unallocated,
                plan: null,
                percent: null,
                sleeping: false,
            });
        }

        return tiles;
    }, [canvas]);

    if (data.length === 0) {
        return null;
    }

    const legend = bedLegend(dark);

    return (
        <VStack align="stretch" gap={3}>
            <Box display={{ base: 'none', md: 'block' }} h="520px">
                <ResponsiveContainer width="100%" height="100%">
                    <Treemap
                        data={data}
                        dataKey="size"
                        isAnimationActive={false}
                        content={<TileContent dark={dark} onSelect={onSelect} />}
                    >
                        <Tooltip content={<BedTooltip mode={canvas.mode} />} />
                    </Treemap>
                </ResponsiveContainer>
            </Box>

            {/* Мобильный вариант: тот же порядок, те же цифры, но списком. */}
            <VStack align="stretch" gap={2} display={{ base: 'flex', md: 'none' }}>
                {data.filter((t) => t.kind !== 'unallocated').map((tile) => (
                    <Box
                        key={tile.id}
                        bg="bg.panel"
                        borderWidth="1px"
                        borderColor={tile.sleeping ? 'orange.emphasized' : 'border'}
                        borderStyle={tile.sleeping ? 'dashed' : 'solid'}
                        borderRadius="lg"
                        p={3}
                        onClick={() => onSelect?.(tile)}
                    >
                        <HStack justify="space-between" mb={1}>
                            <Text fontSize="sm" fontWeight="600">{tile.name}</Text>
                            <Text fontSize="sm" color="fg.muted">
                                {tile.percent === null ? 'плана нет' : `${tile.percent}%`}
                            </Text>
                        </HStack>
                        <Progress.Root value={Math.min(100, Number(tile.percent ?? 0))} size="sm" mb={1}>
                            <Progress.Track><Progress.Range /></Progress.Track>
                        </Progress.Root>
                        <Text fontSize="xs" color="fg.muted">
                            {tile.plan === null
                                ? `Оборот ${money(tile.area)}/мес`
                                : `${money(tile.fact)} из ${money(tile.plan)}`}
                            {tile.sleeping ? ` · заросло ${tile.overdue_days} дн.` : ''}
                        </Text>
                    </Box>
                ))}
            </VStack>

            <HStack gap={3} flexWrap="wrap" fontSize="xs" color="fg.muted">
                <Text>Заливка — выполнение плана:</Text>
                {legend.map((item) => (
                    <HStack key={item.label} gap={1}>
                        <Box w="14px" h="14px" borderRadius="sm" bg={item.color} borderWidth="1px" borderColor="border" />
                        <Text>{item.label}</Text>
                    </HStack>
                ))}
                <HStack gap={1}>
                    <Box w="14px" h="14px" borderRadius="sm" borderWidth="1px" borderColor="border" bgImage="repeating-linear-gradient(45deg, currentColor 0 2px, transparent 2px 6px)" opacity={0.5} />
                    <Text>заросло — не покупает дольше своего цикла</Text>
                </HStack>
            </HStack>
        </VStack>
    );
}
