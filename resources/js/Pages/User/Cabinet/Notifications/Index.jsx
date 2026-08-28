import { Head } from '@inertiajs/react';
import { Text, VStack } from '@chakra-ui/react';
import CabinetLayout from '@/Pages/User/Cabinet/CabinetLayout';
import NotificationMatrix from '@/Crm/Components/NotificationMatrix';

/**
 * Настройки уведомлений в кабинете партнёра.
 *
 * Тот же компонент, что видит менеджер: два представления одной настройки
 * разошлись бы, и клиент видел бы одно, а приходило другое.
 */
export default function Index() {
    return (
        <CabinetLayout title="Уведомления">
            <Head title="Уведомления" />

            <VStack align="stretch" gap={5}>
                <Text fontSize="sm" color="fg.muted">
                    Здесь вы решаете, о чём мы вам пишем и на какие адреса.
                    Настройка действует сразу — обращаться к менеджеру не нужно.
                </Text>

                <NotificationMatrix
                    canEdit
                    // Роли и конкретные люди — инструмент менеджера: через них
                    // клиент нащупал бы, кто ещё заведён в справочнике.
                    allowedTypes={['login', 'email']}
                    endpoints={{
                        index: route('cabinet.notifications.data'),
                        update: route('cabinet.notifications.update'),
                        contacts: null,
                        marketing: route('cabinet.notifications.marketing'),
                    }}
                />
            </VStack>
        </CabinetLayout>
    );
}
