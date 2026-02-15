import { useState, useEffect, useRef } from 'react';
import { Box, Flex, Input, Text } from '@chakra-ui/react';

const DEBOUNCE_MS = 500;

/**
 * Форматирование числа с разделителями тысяч.
 */
function formatPrice(val) {
    if (val == null || val === '') return '';
    return Number(val).toLocaleString('ru-RU');
}

/**
 * Проверка, выбран ли данный bucket.
 */
function isBucketActive(localMin, localMax, bucket) {
    return (
        localMin !== '' &&
        localMax !== '' &&
        Number(localMin) === bucket.from &&
        Number(localMax) === bucket.to
    );
}

/**
 * PriceFilter — фильтр по цене: два input (от/до) + гистограмма buckets.
 *
 * @param {{
 *   priceMin?: string|number,
 *   priceMax?: string|number,
 *   priceData?: { min: number, max: number, buckets: Array<{from: number, to: number, count: number}> },
 *   onPriceChange: (min: string, max: string) => void,
 * }} props
 */
export default function PriceFilter({
    priceMin = '',
    priceMax = '',
    priceData = null,
    onPriceChange,
}) {
    const [localMin, setLocalMin] = useState(priceMin);
    const [localMax, setLocalMax] = useState(priceMax);
    const timerRef = useRef(null);
    const mountedRef = useRef(false);
    const onPriceChangeRef = useRef(onPriceChange);
    onPriceChangeRef.current = onPriceChange;

    // Синхронизация с внешними значениями
    useEffect(() => {
        setLocalMin(priceMin || '');
        setLocalMax(priceMax || '');
    }, [priceMin, priceMax]);

    // Debounced onChange
    useEffect(() => {
        if (!mountedRef.current) {
            mountedRef.current = true;
            return;
        }

        timerRef.current = setTimeout(() => {
            onPriceChangeRef.current(localMin, localMax);
        }, DEBOUNCE_MS);

        return () => clearTimeout(timerRef.current);
    }, [localMin, localMax]);

    const handleBucketClick = (from, to) => {
        setLocalMin(String(from));
        setLocalMax(String(to));
        // Сразу отправляем без debounce при клике на bucket
        clearTimeout(timerRef.current);
        onPriceChangeRef.current(String(from), String(to));
    };

    const buckets = priceData?.buckets || [];
    const maxCount = Math.max(...buckets.map((b) => b.count), 1);

    return (
        <Box>
            {/* Inputs min / max */}
            <Flex gap="2" mb="3">
                <Box flex="1">
                    <Input
                        value={localMin}
                        onChange={(e) => setLocalMin(e.target.value.replace(/[^0-9.]/g, ''))}
                        placeholder={priceData ? `от ${formatPrice(priceData.min)}` : 'от'}
                        size="sm"
                        borderRadius="lg"
                        type="text"
                        inputMode="decimal"
                        _focus={{
                            borderColor: 'pink.400',
                            boxShadow: '0 0 0 1px var(--chakra-colors-pink-400)',
                        }}
                    />
                </Box>
                <Box flex="1">
                    <Input
                        value={localMax}
                        onChange={(e) => setLocalMax(e.target.value.replace(/[^0-9.]/g, ''))}
                        placeholder={priceData ? `до ${formatPrice(priceData.max)}` : 'до'}
                        size="sm"
                        borderRadius="lg"
                        type="text"
                        inputMode="decimal"
                        _focus={{
                            borderColor: 'pink.400',
                            boxShadow: '0 0 0 1px var(--chakra-colors-pink-400)',
                        }}
                    />
                </Box>
            </Flex>

            {/* Гистограмма buckets */}
            {buckets.length > 0 && (
                <Box>
                    {/* Столбики */}
                    <Flex align="flex-end" gap="1px" mb="1.5" h="40px">
                        {buckets.map((bucket, i) => {
                            const heightPct = Math.max((bucket.count / maxCount) * 100, 4);
                            const isActive = isBucketActive(localMin, localMax, bucket);

                            return (
                                <Box
                                    key={i}
                                    flex="1"
                                    h={`${heightPct}%`}
                                    bg={isActive ? 'pink.400' : 'gray.200'}
                                    _dark={{ bg: isActive ? 'pink.500' : 'gray.600' }}
                                    borderRadius="sm"
                                    cursor="pointer"
                                    transition="background 0.15s"
                                    _hover={{ bg: isActive ? 'pink.500' : 'gray.300', _dark: { bg: isActive ? 'pink.600' : 'gray.500' } }}
                                    onClick={() => handleBucketClick(bucket.from, bucket.to)}
                                    title={`${formatPrice(bucket.from)} – ${formatPrice(bucket.to)} (${bucket.count})`}
                                />
                            );
                        })}
                    </Flex>

                    {/* Кнопки-buckets */}
                    <Flex direction="column" gap="1">
                        {buckets.map((bucket, i) => {
                            const isActive = isBucketActive(localMin, localMax, bucket);

                            return (
                                <Flex
                                    key={i}
                                    as="button"
                                    type="button"
                                    align="center"
                                    justify="space-between"
                                    px="2"
                                    py="1"
                                    borderRadius="md"
                                    fontSize="xs"
                                    bg={isActive ? 'pink.50' : 'transparent'}
                                    color={isActive ? 'pink.600' : 'gray.600'}
                                    _dark={{
                                        bg: isActive ? 'pink.900/30' : 'transparent',
                                        color: isActive ? 'pink.300' : 'gray.400',
                                    }}
                                    _hover={{
                                        bg: isActive ? 'pink.100' : 'gray.50',
                                        _dark: { bg: isActive ? 'pink.900/40' : 'gray.700' },
                                    }}
                                    transition="all 0.15s"
                                    onClick={() => handleBucketClick(bucket.from, bucket.to)}
                                >
                                    <Text>
                                        {formatPrice(bucket.from)} – {formatPrice(bucket.to)} ₽
                                    </Text>
                                    <Text color="gray.400" _dark={{ color: 'gray.500' }}>
                                        {bucket.count}
                                    </Text>
                                </Flex>
                            );
                        })}
                    </Flex>
                </Box>
            )}
        </Box>
    );
}
