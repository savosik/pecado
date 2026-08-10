import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Head, usePage } from '@inertiajs/react';
import { Box, HStack, Input, SimpleGrid, Spinner, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { LuArrowLeft } from 'react-icons/lu';
import BedsCanvas from './components/BedsCanvas';
import BedDrawer from './components/BedDrawer';

const money = (value) => (value === null || value === undefined
    ? '—'
    : `${Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`);

const selectStyle = {
    padding: '0.4rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '220px',
};

function Stat({ label, value, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="lg" p={3}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="xl" fontWeight="700">{value}</Text>
            {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
        </Box>
    );
}

/**
 * «Грядки» — план периода одной картинкой.
 *
 * Руководитель заходит на уровень отдела: плитки — менеджеры. Клик по менеджеру
 * проваливает в его грядки партнёров, клик по партнёру открывает панель аналитики.
 * Менеджер сразу видит своих партнёров — уровня отдела у него нет.
 */
export default function Index() {
    const {
        month: initialMonth,
        scopeOptions = [],
        canSeeAll = false,
    } = usePage().props;

    const [month, setMonth] = useState(initialMonth);
    // null — отдел (плитки-менеджеры). Число — конкретный менеджер.
    const [managerId, setManagerId] = useState(null);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(null);

    const params = useMemo(() => {
        const base = { month };

        if (managerId !== null) {
            base.scope = 'manager';
            base.scope_id = managerId;
        }

        return base;
    }, [month, managerId]);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const { data: payload } = await axios.get(route('crm.beds.data'), { params });
            setData(payload);
        } catch (e) {
            setError(e?.response?.data?.message || 'Не удалось загрузить полотно.');
        } finally {
            setLoading(false);
        }
    }, [params]);

    useEffect(() => { load(); }, [load]);

    const onSelect = (tile) => {
        // Плитка менеджера — не карточка, а уровень ниже: проваливаемся в его грядки.
        if (data?.mode === 'managers') {
            setManagerId(tile.id);

            return;
        }

        setSelected(tile);
    };

    const managerName = managerId === null
        ? null
        : scopeOptions.find((m) => m.id === managerId)?.name ?? null;

    return (
        <>
            <Head title="CRM — Грядки" />
            <PageHeader
                title="Грядки"
                description="План периода одной картинкой: площадь — масштаб, заливка — выполнение"
            />

            <VStack align="stretch" gap={4}>
                <HStack gap={3} flexWrap="wrap" align="center">
                    <Input
                        type="month"
                        value={month}
                        onChange={(e) => setMonth(e.target.value)}
                        maxW="180px"
                        size="sm"
                    />

                    {canSeeAll && (
                        <select
                            style={selectStyle}
                            value={managerId ?? ''}
                            onChange={(e) => setManagerId(e.target.value === '' ? null : Number(e.target.value))}
                        >
                            <option value="">Отдел целиком — по менеджерам</option>
                            {scopeOptions.map((manager) => (
                                <option key={manager.id} value={manager.id}>{manager.name}</option>
                            ))}
                        </select>
                    )}

                    {canSeeAll && managerId !== null && (
                        <Button size="sm" variant="ghost" onClick={() => setManagerId(null)}>
                            <LuArrowLeft /> К отделу
                        </Button>
                    )}

                    {loading && <Spinner size="xs" />}
                </HStack>

                {error && <Alert status="error" title="Ошибка">{error}</Alert>}

                {data && (
                    <>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                            <Stat
                                label="План периода"
                                value={money(data.plan)}
                                hint={data.plan === null ? 'не задан' : data.monthLabel}
                            />
                            <Stat
                                label="Факт"
                                value={money(data.fact)}
                                hint={data.percent === null ? null : `${data.percent}% плана`}
                            />
                            <Stat
                                label="Разложено по грядкам"
                                value={money(data.allocated)}
                                hint={data.unallocated ? `не распределено ${money(data.unallocated)}` : 'распределено полностью'}
                            />
                            <Stat
                                label={data.mode === 'managers' ? 'Менеджеров' : 'Грядок'}
                                value={data.summary?.tiles ?? 0}
                                hint={data.mode === 'managers'
                                    ? null
                                    : `заросло ${data.summary?.sleeping ?? 0} · без плана ${data.summary?.without_plan ?? 0}`}
                            />
                        </SimpleGrid>

                        <Text fontSize="sm" color="fg.muted">
                            {data.mode === 'managers'
                                ? 'Плитка — менеджер. Нажмите, чтобы провалиться в его грядки.'
                                : `Плитка — партнёр${managerName ? `, менеджер ${managerName}` : ''}. Нажмите, чтобы открыть аналитику.`}
                            {(data.summary?.hidden ?? 0) > 0
                                && ` Ещё ${data.summary.hidden} мелких на ${money(data.summary.hidden_area)} в полотно не поместились.`}
                        </Text>

                        {(data.tiles ?? []).length === 0 ? (
                            <Alert status="info" title="Полотно пустое">
                                {data.mode === 'managers'
                                    ? 'Ни у одного менеджера нет ни плана на период, ни отгрузок.'
                                    : 'Ни у одного партнёра нет ни плана на месяц, ни отгрузок за год. Грядку рисовать не из чего — задайте планы на «Планах продаж».'}
                            </Alert>
                        ) : (
                            <BedsCanvas canvas={data} onSelect={onSelect} />
                        )}
                    </>
                )}
            </VStack>

            <BedDrawer
                tile={selected}
                month={month}
                scope={managerId === null ? 'department' : 'manager'}
                scopeId={managerId}
                onClose={() => setSelected(null)}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
