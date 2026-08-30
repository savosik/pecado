import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuCopy } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { toastError, toastSuccess } from '@/utils/toast';
import ParamsGrid from './components/settings/ParamsGrid';
import ParamsDrawer from './components/settings/ParamsDrawer';
import AdjustmentsPanel from './components/settings/AdjustmentsPanel';
import InvoicesPanel from './components/settings/InvoicesPanel';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * Настройки зарплаты (РОП): константы менеджер × месяц и ручные строки дохода.
 *
 * Схема отдела — умолчание; всё, что отличается, подсвечено бейджами «постоянно»
 * и «на месяц». Совпадение с умолчанием сервер не хранит — это возврат к схеме.
 */
export default function SalarySettings(props) {
    const [data, setData] = useState(props);
    const [tab, setTab] = useState('params');
    const [editing, setEditing] = useState(null);
    const [copying, setCopying] = useState(false);

    useEffect(() => setData(props), [props]);

    const changeMonth = (month) => router.get('/crm/salary/settings', { month }, { preserveState: true, preserveScroll: true });

    const reload = async () => {
        const res = await axios.get('/crm/salary/settings/data', { params: { month: data.month } });
        setData(res.data);
    };

    const replaceManager = (row) => setData({
        ...data,
        managers: data.managers.map((m) => (m.id === row.id ? row : m)),
    });

    const copyPrevious = async () => {
        const [y, m] = data.month.split('-').map(Number);
        const prev = new Date(y, m - 2, 1);
        const from = `${prev.getFullYear()}-${String(prev.getMonth() + 1).padStart(2, '0')}`;

        setCopying(true);
        try {
            const res = await axios.post('/crm/salary/settings/copy-month', { from, to: data.month });
            toastSuccess(res.data.copied > 0
                ? `Скопировано отклонений: ${res.data.copied}${res.data.skipped ? `, пропущено: ${res.data.skipped}` : ''}`
                : 'В прошлом месяце отклонений на месяц не было — копировать нечего');
            await reload();
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось скопировать');
        } finally {
            setCopying(false);
        }
    };

    const scheme = data.scheme ?? {};

    return (
        <CrmLayout breadcrumbs={[{ label: 'Команда' }, { label: 'Настройки зарплаты' }]}>
            <Head title="Настройки зарплаты — CRM" />
            <PageHeader
                title="Настройки зарплаты"
                description="Константы расчёта на менеджера и месяц, позиции доп. дохода и корректировки."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Месяц" style={selectStyle} value={data.month} onChange={(e) => changeMonth(e.target.value)}>
                            {(data.months ?? []).map((m) => (
                                <option key={m.value} value={m.value}>{m.label}</option>
                            ))}
                        </select>
                        <Button size="sm" variant="outline" onClick={copyPrevious} loading={copying}>
                            <LuCopy /> Из прошлого месяца
                        </Button>
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                <HStack justify="space-between" flexWrap="wrap" gap={3}>
                    <SegmentedControl
                        size="sm"
                        value={tab}
                        onValueChange={(e) => setTab(e.value)}
                        items={[
                            { value: 'params', label: 'Параметры' },
                            { value: 'adjustments', label: `Доп. доход и корректировки${data.adjustments?.length ? ` (${data.adjustments.length})` : ''}` },
                            { value: 'invoices', label: 'Накладные' },
                        ]}
                    />
                    <Text fontSize="xs" color="fg.muted">
                        Схема «{scheme.title}» v{scheme.version} · действует с {scheme.effective_from}
                    </Text>
                </HStack>

                {tab === 'params' && (
                    <Box>
                        <ParamsGrid managers={data.managers ?? []} onEdit={setEditing} />
                        <Text fontSize="xs" color="fg.muted" mt={2}>
                            Без бейджа — значение по схеме отдела. «Постоянно» — личное значение менеджера,
                            «на месяц» — отклонение только для {data.month_label?.toLowerCase()}.
                        </Text>
                    </Box>
                )}

                {tab === 'adjustments' && (
                    <AdjustmentsPanel
                        month={data.month}
                        managers={data.managers ?? []}
                        adjustments={data.adjustments ?? []}
                        onChanged={(rows) => setData({ ...data, adjustments: rows })}
                    />
                )}

                {tab === 'invoices' && (
                    <InvoicesPanel month={data.month} managers={data.managers ?? []} />
                )}
            </VStack>

            <ParamsDrawer
                manager={editing}
                month={data.month}
                monthLabel={data.month_label ?? ''}
                components={data.components}
                schemeEnabled={scheme.enabled ?? []}
                open={Boolean(editing)}
                onClose={() => setEditing(null)}
                onSaved={replaceManager}
            />
        </CrmLayout>
    );
}
