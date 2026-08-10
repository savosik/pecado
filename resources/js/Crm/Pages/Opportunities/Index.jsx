import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Box, Card, HStack, Input, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import OpportunityPanel from '@/Crm/Components/OpportunityPanel';

/**
 * Раздел «Возможности» — план, превращённый в список звонков.
 *
 * Месяц живёт в состоянии страницы, а не в адресе: список грузится тем же
 * JSON-запросом, что и на вкладке «Планов», и полный визит Inertia ради смены
 * месяца был бы лишним.
 */
export default function Index({ month: initialMonth, canSeeAll = false }) {
    const [month, setMonth] = useState(initialMonth);

    return (
        <>
            <Head title="CRM — Возможности" />
            <PageHeader
                title="Возможности"
                description="Кому продать сегодня: недобор плана, просроченный цикл закупок, падение и спящие партнёры."
            />

            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Body>
                        <HStack gap={4} flexWrap="wrap" align="end">
                            <Box>
                                <Text fontSize="xs" color="fg.muted" mb="1">Месяц</Text>
                                <Input
                                    size="sm"
                                    type="month"
                                    maxW="180px"
                                    value={month}
                                    onChange={(e) => setMonth(e.target.value)}
                                />
                            </Box>
                            <Text fontSize="xs" color="fg.muted">
                                План и факт берутся за выбранный месяц, обороты и класс ABC — за последние 12 месяцев.
                            </Text>
                        </HStack>
                    </Card.Body>
                </Card.Root>

                <OpportunityPanel month={month} canSeeAll={canSeeAll} />
            </VStack>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
