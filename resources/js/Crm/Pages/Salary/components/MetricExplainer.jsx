import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import {
    AccordionItem, AccordionItemContent, AccordionItemTrigger, AccordionRoot,
} from '@/components/ui/accordion';
import { fmtRub0, fmtSigned } from './format';

/**
 * «Что означает каждый показатель и как он посчитан» — аккордеон по компонентам.
 *
 * Три слоя текста на каждый показатель: что это (для новичка), как считается
 * (правило без чисел) и пояснение с числами этого месяца из снимка. Факторы
 * KPI показаны внутри премии с их эффектом в рублях.
 */
export default function MetricExplainer({ calculation, explanations }) {
    const components = calculation.breakdown?.components ?? [];

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 3, md: 4 }}>
            <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={2}>Что означает каждый показатель</Text>
            <AccordionRoot collapsible multiple variant="plain">
                {components.map((c) => (
                    <AccordionItem key={c.key} value={c.key}>
                        <AccordionItemTrigger py={3}>
                            <HStack justify="space-between" flex="1" gap={3}>
                                <Text fontWeight="600">{c.label}</Text>
                                <Text fontWeight="700" fontVariantNumeric="tabular-nums" color={c.amount < 0 ? 'red.fg' : undefined}>
                                    {fmtRub0(c.amount)}
                                </Text>
                            </HStack>
                        </AccordionItemTrigger>
                        <AccordionItemContent pb={4}>
                            <Explanation entry={explanations?.[c.key]} explanation={c.explanation} />
                            {(c.children ?? []).length > 0 && (
                                <VStack align="stretch" gap={3} mt={4} pl={3} borderLeftWidth="2px" borderColor="border">
                                    {c.children.map((child) => (
                                        <Box key={child.key}>
                                            <HStack gap={2} flexWrap="wrap">
                                                <Text fontWeight="600" fontSize="sm">{child.label}</Text>
                                                {child.effect_rub !== null && child.effect_rub !== undefined && Math.abs(child.effect_rub) >= 0.5 && (
                                                    <Badge colorPalette={child.effect_rub < 0 ? 'red' : 'green'} variant="subtle" size="sm">
                                                        {fmtSigned(child.effect_rub)} к премии
                                                    </Badge>
                                                )}
                                            </HStack>
                                            <Explanation entry={explanations?.[child.key]} explanation={child.explanation} compact />
                                        </Box>
                                    ))}
                                </VStack>
                            )}
                        </AccordionItemContent>
                    </AccordionItem>
                ))}
            </AccordionRoot>
        </Box>
    );
}

function Explanation({ entry, explanation, compact = false }) {
    return (
        <VStack align="stretch" gap={1} fontSize={compact ? 'xs' : 'sm'}>
            {entry?.description && <Text color="fg.muted">{entry.description}</Text>}
            {entry?.how_computed && (
                <Text color="fg.muted"><Text as="span" fontWeight="600">Как считается:</Text> {entry.how_computed}</Text>
            )}
            {explanation && (
                <Text><Text as="span" fontWeight="600">В этом месяце:</Text> {explanation}</Text>
            )}
        </VStack>
    );
}
