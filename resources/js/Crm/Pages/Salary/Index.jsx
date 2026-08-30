import { Head, router } from '@inertiajs/react';
import { Box, HStack, SimpleGrid, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { useSalaryPolling } from './components/useSalaryPolling';
import EarningsHero from './components/EarningsHero';
import PlanProgress from './components/PlanProgress';
import IncomeWaterfall from './components/IncomeWaterfall';
import MetricExplainer from './components/MetricExplainer';
import PenaltyInvoices from './components/PenaltyInvoices';
import PlannedClients from './components/PlannedClients';
import ShipmentsTimeline from './components/ShipmentsTimeline';
import ForecastChart from './components/ForecastChart';
import LeverCards from './components/LeverCards';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * «Моя зарплата»: сколько заработано на эту минуту, из чего это складывается
 * и что можно поднять. Все числа — из снимка расчёта на сервере; страница
 * ничего не считает сама и обновляется опросом раз в минуту.
 */
export default function SalaryIndex(props) {
    const { data, refreshing } = useSalaryPolling(props);
    const calc = data.calculation;

    const navigate = (changes) => {
        const params = { month: data.month, ...changes };
        if (data.can_see_all && data.manager?.id && !('manager' in changes)) params.manager = data.manager.id;
        if (params.manager === '') delete params.manager;
        router.get('/crm/salary', params, { preserveState: true, preserveScroll: true });
    };

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя зарплата' }]}>
            <Head title="Моя зарплата — CRM" />
            <PageHeader
                title={data.can_see_all && data.manager ? `Зарплата: ${data.manager.name}` : 'Моя зарплата'}
                description="Сколько заработано на эту минуту, из чего это складывается и что можно поднять."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select
                            aria-label="Месяц"
                            style={selectStyle}
                            value={data.month}
                            onChange={(e) => navigate({ month: e.target.value })}
                        >
                            {(data.months ?? []).map((m) => (
                                <option key={m.value} value={m.value}>{m.label}</option>
                            ))}
                        </select>
                        {data.can_see_all && (data.scope_options ?? []).length > 0 && (
                            <select
                                aria-label="Менеджер"
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
                )}
            />

            {!data.manager && (
                <Alert status="info" title="Карточка менеджера не привязана">
                    {data.can_see_all
                        ? 'Выберите менеджера в списке справа.'
                        : 'К вашей учётной записи не привязана карточка менеджера — расчёт показать некому. Обратитесь к руководителю отдела.'}
                </Alert>
            )}

            {calc && (
                <VStack align="stretch" gap={5}>
                    <EarningsHero calculation={calc} monthLabel={data.month_label} refreshing={refreshing} />

                    {(calc.warnings ?? []).map((w) => (
                        <Alert key={w} status="warning" title={w} />
                    ))}

                    <SimpleGrid columns={{ base: 1, xl: 2 }} gap={5} alignItems="stretch">
                        <PlanProgress calculation={calc} explanations={data.explanations} />
                        <IncomeWaterfall calculation={calc} />
                    </SimpleGrid>

                    {!calc.is_frozen && <LeverCards advice={calc.forecast?.advice} />}

                    {!calc.is_frozen && <ForecastChart forecast={calc.forecast} current={calc} />}

                    <MetricExplainer calculation={calc} explanations={data.explanations} />

                    <SimpleGrid columns={{ base: 1, xl: 2 }} gap={5} alignItems="start">
                        <PenaltyInvoices calculation={calc} />
                        <PlannedClients calculation={calc} />
                    </SimpleGrid>

                    <ShipmentsTimeline timeline={data.timeline} />

                    <Box h={2} />
                </VStack>
            )}
        </CrmLayout>
    );
}
