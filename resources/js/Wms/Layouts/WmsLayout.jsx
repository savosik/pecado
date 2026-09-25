import { router, usePage } from '@inertiajs/react';
import { Box, HStack, Image, Text } from '@chakra-ui/react';
import { LuLogOut } from 'react-icons/lu';
import { PanelLayout } from '@/shared/Panel/PanelLayout';
import { Button } from '@/components/ui/button';
import { menuConfig } from '../config/menuConfig';

const panel = {
    key: 'wms',
    basePath: '/wms',
    menuConfig,
    homeLabel: 'Рабочий стол',
    logoAlt: 'Pecado Склад',
    badge: 'Склад',
    logoHeight: '8',
};

/**
 * Шапка «киоска» (pick-17): вошедший по ссылке кладовщик видит только экран выдачи —
 * логотип, кто вошёл и выход. Ни меню, ни других разделов склада.
 */
function KioskBar() {
    const { auth } = usePage().props;

    return (
        <Box bg="bg" borderBottomWidth="1px" px={{ base: 3, md: 6 }} py="2">
            <HStack justify="space-between" maxW="720px" mx="auto">
                <HStack gap="3" minW="0">
                    <Image src="/logo.png" alt="Pecado Склад" h="7" />
                    <Text fontWeight="600" fontSize="sm" lineClamp="1">{auth?.user?.name}</Text>
                </HStack>
                <Button size="sm" variant="ghost" onClick={() => router.post('/logout')}><LuLogOut /> Выйти</Button>
            </HStack>
        </Box>
    );
}

export const WmsLayout = ({ children, breadcrumbs = [] }) => {
    const { kiosk } = usePage().props;

    return (
        <PanelLayout panel={panel} breadcrumbs={breadcrumbs} kiosk={kiosk ? <KioskBar /> : null}>
            {children}
        </PanelLayout>
    );
};

export default WmsLayout;
