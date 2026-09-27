import { Box } from '@chakra-ui/react';

/**
 * Персонаж помощника: круглая мордочка, которая дышит, моргает и кивает,
 * когда есть что сказать. Живой элемент угла страницы, а не статичная иконка.
 *
 * Только дешёвые анимации (transform и opacity), без перерисовки вёрстки;
 * keyframes — в resources/css/app.css, при prefers-reduced-motion всё замирает.
 */
export default function AssistantMascot({ talking = false, size = 56 }) {
    return (
        <Box
            className="assistant-mascot"
            position="relative"
            w={`${size}px`}
            h={`${size}px`}
            transition="transform 160ms ease"
            _groupHover={{ transform: 'scale(1.06)' }}
        >
            {/* Расходящееся кольцо, когда персонаж «заговорил» */}
            {talking && (
                <Box
                    position="absolute"
                    inset="0"
                    borderRadius="full"
                    bg="pecado.400"
                    style={{ animation: 'assistantGlow 1.4s ease-out 2', transformOrigin: 'center' }}
                    pointerEvents="none"
                />
            )}
            <Box
                position="absolute"
                inset="0"
                borderRadius="full"
                bg="pecado.500"
                boxShadow="0 8px 22px rgba(190, 30, 45, 0.35)"
                style={{
                    animation: talking
                        ? 'assistantNod 1.1s ease-in-out 2, assistantFloat 3.4s ease-in-out infinite'
                        : 'assistantFloat 3.4s ease-in-out infinite',
                    transformOrigin: '50% 70%',
                }}
            >
                <svg viewBox="0 0 56 56" width={size} height={size} aria-hidden="true">
                    {/* антенна */}
                    <line x1="28" y1="13" x2="28" y2="8" stroke="white" strokeWidth="2.2" strokeLinecap="round" />
                    <circle cx="28" cy="6.5" r="2.4" fill="white" style={{ animation: 'assistantAntenna 2.6s ease-in-out infinite', transformOrigin: '28px 6.5px' }} />
                    {/* голова */}
                    <rect x="13" y="14" width="30" height="24" rx="9" fill="white" />
                    {/* экран лица */}
                    <rect x="16.5" y="17.5" width="23" height="17" rx="6.5" fill="var(--chakra-colors-pecado-500, #b91c2c)" />
                    {/* глаза: моргают */}
                    <g style={{ animation: 'assistantBlink 5.2s ease-in-out infinite', transformOrigin: '28px 25px' }}>
                        <ellipse cx="23.5" cy="25" rx="2.3" ry="3" fill="white" />
                        <ellipse cx="32.5" cy="25" rx="2.3" ry="3" fill="white" />
                    </g>
                    {/* улыбка */}
                    <path d="M23 30.2 Q28 33.4 33 30.2" stroke="white" strokeWidth="1.9" strokeLinecap="round" fill="none" />
                    {/* хвостик речевого пузыря */}
                    <path d="M20 38 L17 44 L25 38.5 Z" fill="white" />
                </svg>
            </Box>
        </Box>
    );
}
