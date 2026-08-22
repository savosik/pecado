import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Возможные дубли.
 *
 * Подсказка, а не приговор: однофамильцы бывают, и решает человек. При слиянии
 * на победителя переезжает всё, что на дубль ссылалось, а сам дубль не стирается
 * физически — ссылка из старого отчёта не должна упереться в пустоту.
 */
export default function DuplicatesPanel({ onDone, onCancel }) {
    const [groups, setGroups] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        axios.get(route('crm.contacts.duplicate-pairs'))
            .then((res) => setGroups(res.data.data || []))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    useEffect(load, [load]);

    const merge = async (winnerId, duplicateId) => {
        setBusy(true);
        try {
            const res = await axios.post(route('crm.contacts.merge', winnerId), { duplicate_id: duplicateId });
            toastSuccess('Готово', res.data?.message);
            load();
            onDone?.();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Box borderWidth="1px" borderRadius="lg" p={4}>
            <VStack align="stretch" gap={3}>
                <Text fontSize="sm" fontWeight="600">Возможные дубли</Text>

                {loading && <Text fontSize="sm" color="fg.muted">Ищем похожих…</Text>}

                {!loading && groups.length === 0 && (
                    <Alert status="success" title="Дублей не видно">
                        Совпадений по телефону, почте и имени не нашлось.
                    </Alert>
                )}

                {groups.map((group) => (
                    <Box key={group.winner.id} borderWidth="1px" borderRadius="md" p={3}>
                        <HStack gap={2} mb={2} flexWrap="wrap">
                            <Text fontSize="sm" fontWeight="600">{group.winner.full_name}</Text>
                            <Badge colorPalette="green" variant="subtle">оставить</Badge>
                            <Text fontSize="xs" color="fg.muted">
                                {[group.winner.phone, group.winner.email].filter(Boolean).join(' · ')}
                            </Text>
                        </HStack>

                        <VStack align="stretch" gap={2}>
                            {group.duplicates.map((duplicate) => (
                                <HStack key={duplicate.id} justifyContent="space-between" gap={3} flexWrap="wrap">
                                    <VStack align="start" gap={0}>
                                        <Text fontSize="sm">{duplicate.full_name}</Text>
                                        <Text fontSize="xs" color="fg.muted">
                                            {[duplicate.phone, duplicate.email, duplicate.client?.name]
                                                .filter(Boolean).join(' · ')}
                                        </Text>
                                    </VStack>
                                    <Button
                                        size="xs"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => merge(group.winner.id, duplicate.id)}
                                    >
                                        Слить сюда
                                    </Button>
                                </HStack>
                            ))}
                        </VStack>
                    </Box>
                ))}

                <Button size="sm" variant="ghost" onClick={onCancel}>Закрыть</Button>
            </VStack>
        </Box>
    );
}
