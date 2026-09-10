import { Head, Link, router } from '@inertiajs/react';
import { Box, HStack, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { useMotivationPolling } from './components/useMotivationPolling';
import IncomeHero from './components/IncomeHero';
import IncomeLines from './components/IncomeLines';
import PlanProgress from './components/PlanProgress';
import MotivationLevers from './components/MotivationLevers';
import ForecastRange from './components/ForecastRange';
import MotivationCalculator from './components/MotivationCalculator';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * «Мой месяц»: сколько заработано на сегодня и что принесёт следующий шаг.
 *
 * Все числа — из снимка расчёта на сервере; страница ничего не считает сама
 * и обновляется опросом с интервалом, который задаёт сервер.
 */
export default function MotivationIndex(props) {
    const { data, refreshing } = useMotivationPolling(props);
    const calc = data.calculation;

    const navigate = (changes) => {
        const params = { month: data.month, ...changes };
        if (data.can_see_all && data.manager?.id && !('manager' in changes)) params.manager = data.manager.id;
        if (params.manager === '') delete params.manager;
        router.get('/crm/motivation', params, { preserveState: true, preserveScroll: true });
    };

    const title = data.can_see_all && data.manager ? `Мотивация: ${data.manager.name}` : 'Мой месяц';

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя мотивация' }]}>
            <Head title="Моя мотивация — CRM" />
            <PageHeader
                title={title}
                description="Сколько заработано на сегодня по Положению о мотивации и что принесёт следующий шаг."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Месяц" style={selectStyle} value={data.month} onChange={(e) => navigate({ month: e.target.value })}>
                            {(data.months ?? []).map((m) => (
                                <option key={m.value} value={m.value}>{m.label}</option>
                            ))}
                        </select>
                        {data.can_see_all && (data.scope_options ?? []).length > 0 && (
                            <select aria-label="Работник" style={selectStyle} value={data.manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value })}>
                                <option value="">Выберите работника…</option>
                                {data.scope_options.map((o) => (
                                    <option key={o.id} value={o.id}>{o.name}</option>
                                ))}
                            </select>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4} maxW="1100px">
                {data.manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">
                        К вашей учётной записи не привязана карточка персонального менеджера. Выберите работника выше или обратитесь к руководителю.
                    </Alert>
                )}

                {data.manager !== null && data.participates === false && (
                    <Alert status="info" title="Расчёт не ведётся">
                        Для этого работника расчёт оплаты труда отключён в настройках. Включить его может руководитель.
                    </Alert>
                )}

                {calc && (calc.warnings ?? []).map((warning) => (
                    <Alert key={warning} status="warning" title={warning} />
                ))}

                {calc && !calc.on_scheme_v2 && (
                    <Alert status="info" title="Показатели Положения 2.2 для этого месяца не рассчитываются">
                        Расчёт за этот месяц — в разделе <Link href={`/crm/salary?month=${data.month}`}><u>«Моя зарплата»</u></Link>.
                    </Alert>
                )}

                {calc && calc.on_scheme_v2 && (
                    <>
                        <IncomeHero calculation={calc} monthLabel={data.month_label} refreshing={refreshing} />

                        <IncomeLines
                            calculation={calc}
                            month={data.month}
                            managerId={data.manager?.id}
                            canSeeAll={data.can_see_all}
                        />

                        <PlanProgress plan={calc.plan} days={calc.days} />

                        {!calc.frozen && data.is_current_month && (
                            <>
                                <MotivationLevers levers={calc.levers} />
                                <ForecastRange forecast={calc.forecast} total={calc.total} />
                                <MotivationCalculator
                                    calculation={calc}
                                    month={data.month}
                                    managerId={data.manager?.id}
                                    canSeeAll={data.can_see_all}
                                />
                            </>
                        )}

                        {calc.frozen && (
                            <Box fontSize="sm" color="fg.muted">
                                Месяц утверждён: числа читаются из снимка расчёта и не меняются. Расчётный лист — в разделе «Моя зарплата».
                            </Box>
                        )}
                    </>
                )}
            </VStack>
        </CrmLayout>
    );
}
