import { useState } from 'react';
import { Box, Grid, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuChevronDown, LuChevronUp, LuBookOpen } from 'react-icons/lu';

/**
 * Легенда причин: что означает каждая категория и какие причины в неё входят.
 *
 * Без легенды справочник из девяти строк превращается в угадайку: «отменил
 * менеджер вручную» и «отменил менеджер по просьбе клиента» отличаются одним
 * словом, а разрез по ним показывает совершенно разные вещи — наши потери
 * против решения клиента. Пояснение к причине пишет РОП там же, в справочнике.
 *
 * Свёрнута по умолчанию: читают её один раз, а место в шапке нужно каждый день.
 */
export default function ReasonLegend({ categories = [], reasons = [], activeCategory = '', onSelectCategory }) {
    const [open, setOpen] = useState(false);

    const withReasons = categories.map((category) => ({
        ...category,
        items: reasons.filter((reason) => reason.category === category.value && reason.is_active),
    }));

    return (
        <Box>
            <Button size="xs" variant="ghost" onClick={() => setOpen((prev) => !prev)}>
                <LuBookOpen />
                Легенда причин
                {open ? <LuChevronUp /> : <LuChevronDown />}
            </Button>

            {open && (
                <Grid
                    mt={2}
                    gap={3}
                    templateColumns={{ base: '1fr', md: 'repeat(2, 1fr)', xl: 'repeat(3, 1fr)' }}
                >
                    {withReasons.map((category) => (
                        <Box
                            key={category.value}
                            borderWidth="1px"
                            borderRadius="lg"
                            p={3}
                            bg="bg.panel"
                            borderColor={activeCategory === category.value ? `${category.color}.solid` : 'border'}
                        >
                            <HStack gap={2} mb={1}>
                                <Box w="10px" h="10px" borderRadius="full" bg={`${category.color}.solid`} flexShrink={0} />
                                <Text
                                    fontWeight="semibold"
                                    fontSize="sm"
                                    cursor={onSelectCategory ? 'pointer' : 'default'}
                                    onClick={() => onSelectCategory?.(
                                        activeCategory === category.value ? '' : category.value,
                                    )}
                                >
                                    {category.label}
                                </Text>
                            </HStack>

                            <Text fontSize="xs" color="fg.muted" mb={2}>{category.description}</Text>

                            <VStack align="start" gap={1}>
                                {category.items.length === 0 && (
                                    <Text fontSize="xs" color="fg.muted">Причин пока нет.</Text>
                                )}

                                {category.items.map((reason) => (
                                    <Box key={reason.value}>
                                        <Text fontSize="xs" fontWeight="medium">{reason.label}</Text>
                                        {reason.description && (
                                            <Text fontSize="xs" color="fg.muted">{reason.description}</Text>
                                        )}
                                    </Box>
                                ))}
                            </VStack>
                        </Box>
                    ))}
                </Grid>
            )}
        </Box>
    );
}
