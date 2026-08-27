import { Head } from '@inertiajs/react';
import { VStack, Text } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import StaffNotificationList from '@/Crm/Components/StaffNotificationList';

/**
 * «Мои уведомления» — что получаю я.
 *
 * Настройки клиента живут в его карточке, настройки сотрудника — здесь.
 * Одно с другим не смешивается: у партнёра выбирают адресатов из его
 * окружения, у сотрудника адресат один — он сам.
 */
export default function Index() {
    return (
        <>
            <Head title="CRM — Мои уведомления" />

            <PageHeader
                title="Мои уведомления"
                description="Что система присылает вам на почту"
            />

            <VStack align="stretch" gap={4}>
                <Text fontSize="sm" color="fg.muted">
                    Здесь только ваши письма. Что приходит партнёру — настраивается
                    в его карточке, на вкладке «Уведомления».
                </Text>

                <StaffNotificationList />
            </VStack>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
