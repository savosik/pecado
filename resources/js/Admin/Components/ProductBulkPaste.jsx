import { useMemo, useState } from 'react';
import { Box, HStack, Stack, Text, Textarea } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuListChecks, LuTriangleAlert } from 'react-icons/lu';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';

/** Непустые строки текста (после trim). */
function nonEmptyLines(text) {
    return String(text || '')
        .split(/\r\n|\r|\n/)
        .map((l) => l.trim())
        .filter((l) => l !== '');
}

/**
 * Вставка товаров «в столбик», как «Импорт заказа» в корзине: штрихкоды, артикулы
 * или коды 1С по одному в строке → «Прописать товары». Найденное добавляется к уже
 * выбранным (без дублей), нераспознанные строки остаются в поле для правки.
 *
 * @param {{ value: Array, onChange: (products: Array) => void, resolveRoute?: string }} props
 */
export const ProductBulkPaste = ({ value = [], onChange, resolveRoute = 'admin.certificates.resolve-products' }) => {
    const [text, setText] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [unresolved, setUnresolved] = useState([]);

    const lines = useMemo(() => nonEmptyLines(text), [text]);

    const handleResolve = async () => {
        if (lines.length === 0 || submitting) return;

        setSubmitting(true);
        try {
            const { data } = await axios.post(route(resolveRoute), { identifiers: lines });

            const current = Array.isArray(value) ? value : [];
            const selectedIds = new Set(current.map((p) => p.id));
            const fresh = (data.products || []).filter((p) => !selectedIds.has(p.id));
            const missed = data.unresolved || [];

            if (fresh.length > 0) {
                onChange?.([...current, ...fresh]);
            }
            setUnresolved(missed);
            setText(missed.map((row) => row.identifier).join('\n'));

            const already = (data.products || []).length - fresh.length;
            toaster.create({
                title: fresh.length > 0 ? `Добавлено товаров: ${fresh.length}` : 'Новых товаров не добавлено',
                description: [
                    already > 0 ? `уже были в списке: ${already}` : null,
                    missed.length > 0 ? `не распознано: ${missed.length}` : null,
                ].filter(Boolean).join(', ') || undefined,
                type: missed.length > 0 ? 'warning' : 'success',
            });
        } catch (err) {
            toaster.create({
                title: 'Не удалось прописать товары',
                description: err?.response?.data?.message || 'Попробуйте ещё раз.',
                type: 'error',
            });
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Stack gap={2} mb={4}>
            <Textarea
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder={'Штрихкоды, артикулы или коды — по одному в строке\n4601234567890\nLE-13\n00-00012345'}
                rows={6}
                fontFamily="mono"
                fontSize="sm"
            />
            <HStack justify="space-between" flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted">
                    Строк: {lines.length}
                </Text>
                <Button
                    size="sm"
                    onClick={handleResolve}
                    loading={submitting}
                    disabled={lines.length === 0}
                >
                    <LuListChecks /> Прописать товары
                </Button>
            </HStack>

            {unresolved.length > 0 && (
                <Box borderWidth="1px" borderColor="orange.300" bg="orange.50" _dark={{ bg: 'orange.950', borderColor: 'orange.700' }} borderRadius="md" p={3}>
                    <HStack gap={2} mb={1}>
                        <LuTriangleAlert />
                        <Text fontSize="sm" fontWeight="medium">
                            Не распознано: {unresolved.length} — строки оставлены в поле выше
                        </Text>
                    </HStack>
                    <Stack gap={0} maxH="160px" overflowY="auto">
                        {unresolved.map((row, i) => (
                            <Text key={`${row.identifier}-${i}`} fontSize="xs">
                                <Text as="span" fontFamily="mono">{row.identifier}</Text> — {row.reason.toLowerCase()}
                            </Text>
                        ))}
                    </Stack>
                </Box>
            )}
        </Stack>
    );
};
