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
 */
export const PanelLayout = ({ panel, children, breadcrumbs = [], extras = null }) => {
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

                    <Box as="main" p={{ base: 4, md: 6 }}>
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
