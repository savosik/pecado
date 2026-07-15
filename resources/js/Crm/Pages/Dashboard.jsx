import { Head, usePage } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Box, Card, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { Alert } from '@/components/ui/alert';
import { LuUsers, LuUsersRound, LuBriefcase } from 'react-icons/lu';

function StatCard({ label, value, icon: Icon }) {
    return (
        <Card.Root>
            <Card.Body>
                <VStack align="start" gap={2}>
                    <Box color="fg.muted">
                        <Icon size={20} />
                    </Box>
                    <Text fontSize="xs" color="fg.muted">{label}</Text>
                    <Text fontSize="2xl" fontWeight="bold">{value}</Text>
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}

export default function Dashboard() {
    const { stats, seesAll, managerProfileLinked, auth } = usePage().props;

    return (
        <>
            <Head title="CRM — Рабочий стол" />
            <PageHeader
                title={`Здравствуйте, ${auth?.user?.name || 'коллега'}`}
                description={seesAll ? 'Обзор по отделу продаж' : 'Обзор по вашим клиентам'}
            />

            <VStack gap={4} align="stretch">
                {!managerProfileLinked && (
                    <Alert
                        status="warning"
                        title="Аккаунт не связан с карточкой менеджера"
                    >
                        Ваш аккаунт не связан с карточкой персонального менеджера, поэтому клиенты не отображаются.
                        Обратитесь к администратору — привязка настраивается в разделе «Персональные менеджеры».
                    </Alert>
                )}

                <SimpleGrid columns={{ base: 1, md: seesAll ? 3 : 1 }} gap={4}>
                    <StatCard
                        label={seesAll ? 'Клиентов вам видно' : 'Моих клиентов'}
                        value={stats.visible_clients}
                        icon={LuUsers}
                    />
                    {seesAll && (
                        <>
                            <StatCard label="Клиентов в отделе" value={stats.department_clients} icon={LuBriefcase} />
                            <StatCard label="Менеджеров" value={stats.managers} icon={LuUsersRound} />
                        </>
                    )}
                </SimpleGrid>

                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" color="fg.muted">
                            Раздел в разработке: здесь появятся сделки, задачи и воронка продаж.
                            Сейчас доступны «Мои клиенты»{seesAll ? ' и «Команда»' : ''}.
                        </Text>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Dashboard.layout = (page) => <CrmLayout>{page}</CrmLayout>;
