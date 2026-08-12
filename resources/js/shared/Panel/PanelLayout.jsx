import { useState } from 'react';
import { Box } from '@chakra-ui/react';
import { Toaster } from '@/components/ui/toaster';
import { PanelProvider } from './PanelContext';
import { Sidebar } from './Sidebar';
import { MobileSidebar } from './MobileSidebar';
import { Header } from './Header';

/**
 * PanelLayout — общий каркас панелей (/admin, /crm, /wms).
 *
 * Конкретная панель передаёт свой `panel` (см. PanelConfig в PanelContext)
 * и при необходимости `extras` — дополнительные виджеты поверх layout.
 *
 * `topBar` — узкая полоса между шапкой и содержимым. Слот, а не готовый виджет:
 * каркас общий для админки, CRM и склада, и появившаяся у всех троих полоска
 * присутствия клиентов на складе была бы шумом.
 */
export const PanelLayout = ({ panel, children, breadcrumbs = [], extras = null, topBar = null }) => {
    const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

    return (
        <PanelProvider value={panel}>
            <Box minH="100vh" bg="bg.subtle">
                <Sidebar />

                <MobileSidebar
                    isOpen={isMobileMenuOpen}
                    onClose={() => setIsMobileMenuOpen(false)}
                />

                <Box ml={{ base: 0, md: 64 }}>
                    <Header
                        onMobileMenuOpen={() => setIsMobileMenuOpen(true)}
                        breadcrumbs={breadcrumbs}
                    />

                    {topBar}

                    <Box as="main" p={{ base: 3, md: 6 }}>
                        {children}
                    </Box>
                </Box>

                <Toaster />
                {extras}
            </Box>
        </PanelProvider>
    );
};

export default PanelLayout;
