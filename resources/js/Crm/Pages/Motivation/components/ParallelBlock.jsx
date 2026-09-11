import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtRub, fmtSigned } from '../../Salary/components/format';

/**
 * Переходный период (п. 12.2): доход по оплачиваемой системе и «по другой системе
 * будет столько-то» — с разложением на строки и объяснением, откуда разница.
 * Только итоговую разницу показывать нельзя: работник должен видеть, какой
 * показатель её делает.
 */
export default function ParallelBlock({ parallel }) {
    if (!parallel) return null;
    const { paying, shadow, difference, categories, explanation } = parallel;
    const tone = difference > 0 ? 'green.fg' : difference < 0 ? 'red.fg' : undefined;

    return (
        <VStack align="stretch" gap={3}>
            <Alert status="info" title={parallel.phase === 'before' ? 'Считаем по обеим системам' : 'Сравнение с прежней системой'}>
                {parallel.phase_label} Окно сравнения: {parallel.window.from.slice(0, 7)} — {parallel.window.until.slice(0, 7)}.
            </Alert>

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                <Side label="К выплате" hint="По схеме, которая действует в этом месяце. Именно эта сумма попадает в ведомость." scheme={paying.scheme_label} total={paying.total} strong />
                <Side label={shadow.is_v2 ? 'По новой системе будет' : 'По прежней системе было бы'} hint="Справочный расчёт по другой схеме на тех же данных. В ведомость не попадает." scheme={shadow.scheme_label} total={shadow.total} />
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <HStack gap={1} fontSize="xs" color="fg.muted"><Text>Разница</Text><MetricHint text="Другая система минус оплачиваемая." /></HStack>
                    <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={tone}>{fmtSigned(difference)}</Text>
                    <Text fontSize="xs" color="fg.muted">{explanation}</Text>
                </Box>
            </SimpleGrid>

            {categories.length > 0 && (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Откуда разница</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">{paying.scheme_label}</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">{shadow.scheme_label}</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Разница</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {categories.map((c) => (
                                <Table.Row key={c.key}>
                                    <Table.Cell><Text fontSize="sm" fontWeight="600">{c.label}</Text></Table.Cell>
                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub(c.paying)}</Text></Table.Cell>
                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub(c.shadow)}</Text></Table.Cell>
                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums" color={c.difference > 0 ? 'green.fg' : c.difference < 0 ? 'red.fg' : 'fg.subtle'}>{fmtSigned(c.difference)}</Text></Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                <Lines title={paying.scheme_label} badge="к выплате" lines={paying.lines} total={paying.total} />
                <Lines title={shadow.scheme_label} badge="справочно" lines={shadow.lines} total={shadow.total} />
            </SimpleGrid>
        </VStack>
    );
}

function Side({ label, hint, scheme, total, strong }) {
    return (
        <Box bg="bg.panel" borderWidth={strong ? '2px' : '1px'} borderColor={strong ? 'blue.solid' : 'border'} borderRadius="xl" p={4}>
            <HStack gap={1} fontSize="xs" color="fg.muted"><Text>{label}</Text><MetricHint text={hint} /></HStack>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums">{fmtRub(total)}</Text>
            <Text fontSize="xs" color="fg.subtle">{scheme}</Text>
        </Box>
    );
}

function Lines({ title, badge, lines, total }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
            <HStack px={4} pt={3} gap={2}><Text fontWeight="700" fontSize="sm">{title}</Text><Badge size="xs" variant="subtle">{badge}</Badge></HStack>
            <Table.Root size="sm" mt={1}>
                <Table.Body>
                    {lines.map((l) => (
                        <Table.Row key={l.key}>
                            <Table.Cell>
                                <Text fontSize="sm">{l.label}{l.clause ? <Text as="span" fontSize="xs" color="fg.subtle"> · п. {l.clause}</Text> : null}</Text>
                                {l.explanation && <Text fontSize="xs" color="fg.muted">{l.explanation}</Text>}
                            </Table.Cell>
                            <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums" color={l.amount < 0 ? 'red.fg' : undefined}>{l.amount < 0 ? fmtSigned(l.amount) : fmtRub(l.amount)}</Text></Table.Cell>
                        </Table.Row>
                    ))}
                    <Table.Row bg="bg.subtle">
                        <Table.Cell><Text fontSize="sm" fontWeight="800">Итого</Text></Table.Cell>
                        <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="800" fontVariantNumeric="tabular-nums">{fmtRub(total)}</Text></Table.Cell>
                    </Table.Row>
                </Table.Body>
            </Table.Root>
        </Box>
    );
}
