import { useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight } from 'react-icons/lu';
import { fmtRub, fmtSigned } from '../../Salary/components/format';

const EVIDENCE_LIMIT = 40;

/**
 * Из чего сложился доход: строки в порядке Положения, каждая раскрывается.
 *
 * Раскрытие показывает три вещи в этом порядке: что это, как посчитано,
 * из чего сложилось. Перечень (партнёры, позиции, накладные) подгружается
 * отдельным запросом — в основном ответе только его размер.
 */
export default function IncomeLines({ calculation, month, managerId, canSeeAll }) {
    const lines = calculation.lines ?? [];

    if (lines.length === 0) {
        return null;
    }

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflow="hidden">
            <Box px={{ base: 4, md: 5 }} pt={4} pb={2}>
                <Text fontWeight="700">Из чего сложился доход</Text>
                <Text fontSize="xs" color="fg.muted">Строки в порядке Положения. Раскройте любую — увидите, как она посчитана.</Text>
            </Box>
            <VStack align="stretch" gap={0} divideY="1px" divideColor="border">
                {lines.map((line) => (
                    <Line key={line.key} line={line} month={month} managerId={managerId} canSeeAll={canSeeAll} />
                ))}
            </VStack>
        </Box>
    );
}

function Line({ line, month, managerId, canSeeAll }) {
    const [open, setOpen] = useState(false);
    const [evidence, setEvidence] = useState(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const negative = Number(line.amount) < 0;

    const toggle = async () => {
        const next = !open;
        setOpen(next);

        if (!next || evidence !== null || !line.evidence_count || loading) return;

        setLoading(true);
        setFailed(false);
        try {
            const params = { line: line.key, month };
            if (canSeeAll && managerId) params.manager = managerId;
            const res = await axios.get('/crm/motivation/evidence', { params });
            setEvidence(res.data);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        <Box>
            <HStack
                as="button"
                type="button"
                w="100%"
                px={{ base: 4, md: 5 }}
                py={3}
                gap={3}
                textAlign="left"
                cursor="pointer"
                _hover={{ bg: 'bg.subtle' }}
                onClick={toggle}
                aria-expanded={open}
            >
                <Box color="fg.subtle" flexShrink={0}>
                    {open ? <LuChevronDown size={16} /> : <LuChevronRight size={16} />}
                </Box>
                <Box flex="1" minW={0}>
                    <Text fontWeight="600" fontSize="sm">{line.label}</Text>
                    {line.clause && <Text fontSize="xs" color="fg.subtle">п. {line.clause} Положения</Text>}
                </Box>
                <Text fontWeight="700" fontVariantNumeric="tabular-nums" color={negative ? 'red.fg' : undefined}>
                    {negative ? fmtSigned(line.amount) : fmtRub(line.amount, 0)}
                </Text>
            </HStack>

            {open && (
                <Box px={{ base: 4, md: 5 }} pb={4} pl={{ base: 10, md: 12 }}>
                    <VStack align="stretch" gap={2} fontSize="sm">
                        {line.description && <Text color="fg.muted">{line.description}</Text>}
                        {line.how_computed && (
                            <Text><Text as="span" color="fg.subtle">Как считается: </Text>{line.how_computed}</Text>
                        )}
                        {line.explanation && (
                            <Text><Text as="span" color="fg.subtle">В этом месяце: </Text>{line.explanation}</Text>
                        )}
                        {line.evidence_count > 0 && (
                            <Evidence lineKey={line.key} count={line.evidence_count} evidence={evidence} loading={loading} failed={failed} onRetry={toggle} />
                        )}
                    </VStack>
                </Box>
            )}
        </Box>
    );
}

function Evidence({ lineKey, count, evidence, loading, failed, onRetry }) {
    if (loading) {
        return <Text fontSize="xs" color="fg.subtle">Загружаем перечень…</Text>;
    }

    if (failed) {
        return (
            <HStack fontSize="xs" color="fg.subtle" gap={2}>
                <Text>Не удалось загрузить перечень.</Text>
                <Text as="button" type="button" textDecoration="underline" onClick={onRetry}>Повторить</Text>
            </HStack>
        );
    }

    if (!evidence) {
        return null;
    }

    const rows = evidence.rows ?? [];
    const shown = rows.slice(0, EVIDENCE_LIMIT);
    const overdue = lineKey === 'overdue';

    return (
        <Box borderWidth="1px" borderColor="border" borderRadius="lg" overflow="hidden" mt={1}>
            <HStack px={3} py={2} bg="bg.subtle" fontSize="xs" color="fg.muted" justify="space-between">
                <Text>{overdue ? 'Накладные с просрочкой' : lineKey === 'focus' ? 'Позиции перечня' : 'Партнёры'} · {count}</Text>
                <Text>{overdue ? 'вклад в базу вычета' : 'отгружено'}</Text>
            </HStack>
            <VStack align="stretch" gap={0} divideY="1px" divideColor="border">
                {shown.map((row, i) => (
                    <HStack key={row.invoice_id ?? row.product_id ?? row.partner_id ?? i} px={3} py={1.5} fontSize="sm" gap={3}>
                        <Box flex="1" minW={0}>
                            <Text truncate>
                                {overdue ? `${row.number ?? ''} · ${row.partner_name ?? ''}` : (row.partner_name ?? row.name ?? '')}
                            </Text>
                            {overdue && (
                                <Text fontSize="xs" color="fg.subtle">
                                    {fmtRub(row.amount, 0)} · срок {row.due_on ?? '—'} · {row.days} дн.
                                </Text>
                            )}
                            {row.rate !== undefined && row.rate !== null && (
                                <Badge size="xs" variant="subtle" colorPalette="purple">своя ставка</Badge>
                            )}
                        </Box>
                        <Text fontVariantNumeric="tabular-nums" flexShrink={0}>
                            {fmtRub(overdue ? row.integral : row.amount, 0)}
                        </Text>
                    </HStack>
                ))}
            </VStack>
            {rows.length > shown.length && (
                <Text px={3} py={2} fontSize="xs" color="fg.subtle">
                    Показаны первые {shown.length} из {rows.length}. Полный перечень — на экране показателя.
                </Text>
            )}
        </Box>
    );
}
