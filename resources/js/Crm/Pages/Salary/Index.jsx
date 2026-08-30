import { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';

const fmtRub = (value) => `${Number(value ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ₽`;

const selectStyle = {
    padding: '0.4rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * «Моя зарплата» — первая версия (pay-04): итог, разбор по компонентам и опрос.
 * Визуальная часть (hero, водопад, пояснения, лента) — карточка pay-05.
 */
export default function SalaryIndex(props) {
    const [data, setData] = useState(props);
    const timer = useRef(null);

    useEffect(() => setData(props), [props]);

    useEffect(() => {
        let stopped = false;

        const poll = async () => {
            if (stopped) return;
            if (document.hidden) {
                timer.current = window.setTimeout(poll, (data.poll_seconds ?? 60) * 1000);
                return;
            }
            try {
                const params = { month: data.month };
                if (data.manager?.id && data.can_see_all) params.manager = data.manager.id;
                const res = await axios.get('/crm/salary/data', { params });
                if (!stopped) setData(res.data);
            } catch {
                // сеть моргнула — следующий опрос покажет
            }
            timer.current = window.setTimeout(poll, (data.poll_seconds ?? 60) * 1000);
        };

        timer.current = window.setTimeout(poll, (data.poll_seconds ?? 60) * 1000);

        return () => {
            stopped = true;
            window.clearTimeout(timer.current);
        };
    }, [data.month, data.manager?.id, data.poll_seconds, data.can_see_all]);

    const navigate = (changes) => {
        const params = { month: data.month, ...changes };
        if (data.can_see_all && data.manager?.id && !('manager' in changes)) params.manager = data.manager.id;
        router.get('/crm/salary', params, { preserveState: true, preserveScroll: true });
    };

    const calc = data.calculation;

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя зарплата' }]}>
            <Head title="Моя зарплата — CRM" />
            <PageHeader
                title="Моя зарплата"
                description="Сколько заработано на эту минуту и из чего это складывается."
            />

            <HStack gap={3} mb={6} flexWrap="wrap">
                <select style={selectStyle} value={data.month} onChange={(e) => navigate({ month: e.target.value })}>
                    {(data.months ?? []).map((m) => (
                        <option key={m.value} value={m.value}>{m.label}</option>
                    ))}
                </select>
                {data.can_see_all && (data.scope_options ?? []).length > 0 && (
                    <select
                        style={selectStyle}
                        value={data.manager?.id ?? ''}
                        onChange={(e) => navigate({ manager: e.target.value })}
                    >
                        <option value="">Выберите менеджера…</option>
                        {data.scope_options.map((m) => (
                            <option key={m.id} value={m.id}>{m.name}</option>
                        ))}
                    </select>
                )}
            </HStack>

            {!data.manager && (
                <Alert status="info" title="Карточка менеджера не привязана">
                    {data.can_see_all
                        ? 'Выберите менеджера в списке выше.'
                        : 'К вашей учётной записи не привязана карточка менеджера — расчёт показать некому. Обратитесь к руководителю отдела.'}
                </Alert>
            )}

            {calc && (
                <VStack align="stretch" gap={6}>
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={6}>
                        <Text fontSize="sm" color="fg.muted">
                            {calc.is_frozen ? calc.status_label : 'Заработано на эту минуту'} · {data.month_label}
                        </Text>
                        <Text fontSize="4xl" fontWeight="bold" lineHeight="1.1" mt={1}>
                            {fmtRub(calc.total)}
                        </Text>
                        {calc.computed_at && (
                            <Text fontSize="xs" color="fg.subtle" mt={2}>
                                Обновлено {new Date(calc.computed_at).toLocaleString('ru-RU')}
                            </Text>
                        )}
                    </Box>

                    {(calc.warnings ?? []).map((w) => (
                        <Alert key={w} status="warning" title={w} />
                    ))}

                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                        {(calc.breakdown?.components ?? []).map((c) => (
                            <Box key={c.key} bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="lg" p={4}>
                                <HStack justify="space-between" align="start">
                                    <Text fontWeight="600">{c.label}</Text>
                                    <Text fontWeight="700">{fmtRub(c.amount)}</Text>
                                </HStack>
                                <Text fontSize="sm" color="fg.muted" mt={2}>{c.explanation}</Text>
                                {(c.children ?? []).map((child) => (
                                    <Text key={child.key} fontSize="sm" color="fg.muted" mt={1}>• {child.explanation}</Text>
                                ))}
                            </Box>
                        ))}
                    </SimpleGrid>
                </VStack>
            )}
        </CrmLayout>
    );
}
