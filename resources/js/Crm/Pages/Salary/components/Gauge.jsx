import { Box, HStack, Text, VStack } from '@chakra-ui/react';

/**
 * Спидометр: дуга со шкалой, цветными зонами и яркими порогами.
 *
 * Пороги — главное на этой картинке: менеджеру важно не «где я вообще»,
 * а «сколько осталось до следующей ступени». Поэтому риски порогов рисуются
 * контрастной линией поверх дуги и подписываются числом, а не прячутся
 * в бледную сетку.
 *
 * Дуга 240°, стрелка — от центра к текущему значению.
 */
const ARC = 240;
const START = 180 + (360 - ARC) / 2;

const polar = (cx, cy, r, angleDeg) => {
    const a = (angleDeg * Math.PI) / 180;

    return [cx + r * Math.cos(a), cy + r * Math.sin(a)];
};

const arcPath = (cx, cy, r, fromDeg, toDeg) => {
    const [x1, y1] = polar(cx, cy, r, fromDeg);
    const [x2, y2] = polar(cx, cy, r, toDeg);
    const large = Math.abs(toDeg - fromDeg) > 180 ? 1 : 0;

    return `M ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2}`;
};

export default function Gauge({
    value,
    max = 1,
    segments = [],
    markers = [],
    centerValue,
    centerLabel,
    caption,
    tone = 'blue',
    size = 210,
}) {
    const cx = size / 2;
    const cy = size / 2 + 6;
    const r = size / 2 - 26;
    const width = 15;

    const clamp = (v) => Math.max(0, Math.min(1, (Number(v) || 0) / (max || 1)));
    const angle = (v) => START + ARC * clamp(v);

    const [nx, ny] = polar(cx, cy, r - 2, angle(value));
    const [tx, ty] = polar(cx, cy, r * 0.34, angle(value));

    return (
        <VStack gap={0} align="center">
            <Box position="relative" width={`${size}px`} height={`${size * 0.74}px`}>
                <svg width={size} height={size * 0.86} viewBox={`0 0 ${size} ${size * 0.86}`} role="img" aria-label={caption}>
                    {/* Фон дуги */}
                    <path
                        d={arcPath(cx, cy, r, START, START + ARC)}
                        fill="none"
                        stroke="var(--chakra-colors-bg-muted)"
                        strokeWidth={width}
                        strokeLinecap="round"
                    />

                    {/* Зоны шкалы: от слабого к насыщенному — «чем правее, тем лучше» */}
                    {segments.map((s, i) => (
                        <path
                            key={i}
                            d={arcPath(cx, cy, r, angle(s.from), angle(s.to))}
                            fill="none"
                            stroke={s.color}
                            strokeWidth={width}
                            opacity={s.dim ? 0.35 : 1}
                        />
                    ))}

                    {/* Пороги — контрастные риски поверх дуги */}
                    {markers.map((m, i) => {
                        const a = angle(m.value);
                        const [x1, y1] = polar(cx, cy, r - width / 2 - 2, a);
                        const [x2, y2] = polar(cx, cy, r + width / 2 + 2, a);
                        const [lx, ly] = polar(cx, cy, r + width / 2 + 13, a);

                        return (
                            <g key={`m${i}`}>
                                <line
                                    x1={x1} y1={y1} x2={x2} y2={y2}
                                    stroke={m.reached ? 'var(--chakra-colors-fg)' : 'var(--chakra-colors-fg-muted)'}
                                    strokeWidth={m.strong ? 3 : 2}
                                    strokeLinecap="round"
                                />
                                {m.label && (
                                    <text
                                        x={lx} y={ly}
                                        textAnchor="middle" dominantBaseline="middle"
                                        fontSize="10"
                                        fontWeight={m.strong ? 700 : 600}
                                        fill={m.reached ? 'var(--chakra-colors-fg)' : 'var(--chakra-colors-fg-muted)'}
                                    >
                                        {m.label}
                                    </text>
                                )}
                            </g>
                        );
                    })}

                    {/* Стрелка */}
                    <line
                        x1={tx} y1={ty} x2={nx} y2={ny}
                        stroke={`var(--chakra-colors-${tone}-solid)`}
                        strokeWidth={3}
                        strokeLinecap="round"
                    />
                    <circle cx={cx} cy={cy} r={6} fill={`var(--chakra-colors-${tone}-solid)`} />
                    <circle cx={nx} cy={ny} r={5} fill={`var(--chakra-colors-${tone}-solid)`} stroke="var(--chakra-colors-bg-panel)" strokeWidth={2} />
                </svg>

                <VStack
                    position="absolute"
                    top={`${size * 0.42}px`}
                    left="0"
                    right="0"
                    gap={0}
                    pointerEvents="none"
                >
                    <Text fontSize="2xl" fontWeight="800" lineHeight="1" fontVariantNumeric="tabular-nums">
                        {centerValue}
                    </Text>
                    {centerLabel && <Text fontSize="xs" color="fg.muted">{centerLabel}</Text>}
                </VStack>
            </Box>

            {caption && (
                <HStack gap={1}>
                    <Text fontSize="sm" color="fg.muted" textAlign="center">{caption}</Text>
                </HStack>
            )}
        </VStack>
    );
}
