import { Box, HStack, Text } from '@chakra-ui/react';

/**
 * Доля полосой: длина читается быстрее числа, а число нужно для точности —
 * поэтому рядом и то и другое.
 *
 * Ненулевая, но крошечная доля рисуется полоской в 2 %: нулевой ширины
 * не видно, и «немного» стало бы неотличимо от «ничего».
 */
export default function ShareBar({ value, tone = 'red', caption, width, height = '6px' }) {
    const share = Number(value) || 0;

    return (
        <HStack gap={2} width={width}>
            <Box bg="bg.muted" borderRadius="full" height={height} flex="1" minW="40px" overflow="hidden">
                <Box
                    bg={tone === 'red' ? 'red.solid' : tone === 'green' ? 'green.solid' : 'orange.solid'}
                    height={height}
                    width={`${Math.min(100, Math.max(share > 0 ? 2 : 0, share))}%`}
                />
            </Box>
            {caption !== undefined && (
                <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">{caption}</Text>
            )}
        </HStack>
    );
}
