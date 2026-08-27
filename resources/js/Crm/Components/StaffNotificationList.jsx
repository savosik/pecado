import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { LuBellOff } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Что получает сотрудник.
 *
 * Проще матрицы партнёра, и намеренно: адресат здесь один и всегда известен —
 * сам сотрудник. Настраивается только «получать или нет», выбирать адресатов
 * не из чего.
 */
export default function StaffNotificationList({ managerId = null, canEdit = true }) {
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);

    const params = useMemo(() => (managerId ? { manager: managerId } : {}), [managerId]);

    const load = useCallback(() => {
        axios.get(route('crm.my-notifications.data'), { params })
            .then((res) => setData(res.data))
            .catch(() => toastError('Не удалось загрузить настройки уведомлений'));
    }, [params]);

    useEffect(() => { load(); }, [load]);

    const toggle = async (row) => {
        setBusy(true);
        try {
            const res = await axios.patch(route('crm.my-notifications.update'), {
                ...params,
                occasion_key: row.key,
                is_enabled: !row.enabled,
            });
            setData(res.data);
            toastSuccess('Сохранено');
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const groups = useMemo(() => {
        if (!data) return [];
        const map = new Map();
        data.rows.forEach((row) => {
            if (!map.has(row.family_label)) map.set(row.family_label, []);
            map.get(row.family_label).push(row);
        });
        return [...map.entries()];
    }, [data]);

    if (!data) {
        return <Text fontSize="sm" color="fg.muted">Загружаем…</Text>;
    }

    return (
        <VStack align="stretch" gap={5}>
            {groups.map(([family, rows]) => (
                <Box key={family}>
                    <Text fontSize="sm" fontWeight="700" mb={2}>{family}</Text>

                    <VStack align="stretch" gap={0} borderWidth="1px" borderRadius="md">
                        {rows.map((row, index) => (
                            <Box key={row.key} p={3} borderTopWidth={index === 0 ? 0 : '1px'}>
                                <HStack justify="space-between" align="start" gap={4} flexWrap="wrap">
                                    <VStack align="stretch" gap={1} flex="1" minW="220px">
                                        <HStack gap={2}>
                                            <Text fontSize="sm" fontWeight="600">{row.label}</Text>
                                            {row.overridden && (
                                                <Badge size="sm" colorPalette="blue" variant="subtle">
                                                    настроено
                                                </Badge>
                                            )}
                                        </HStack>

                                        {row.hint && (
                                            <Text fontSize="xs" color="fg.muted">{row.hint}</Text>
                                        )}

                                        {!row.enabled && (
                                            <HStack gap={1} color="fg.muted">
                                                <LuBellOff size={13} />
                                                <Text fontSize="xs">Не присылать</Text>
                                            </HStack>
                                        )}
                                    </VStack>

                                    {canEdit && (
                                        <Button
                                            size="xs"
                                            variant={row.enabled ? 'ghost' : 'solid'}
                                            disabled={busy}
                                            onClick={() => toggle(row)}
                                        >
                                            {row.enabled ? 'Отключить' : 'Включить'}
                                        </Button>
                                    )}
                                </HStack>
                            </Box>
                        ))}
                    </VStack>
                </Box>
            ))}
        </VStack>
    );
}
