import { Box } from '@chakra-ui/react';
import UserHeader from './UserHeader';
import UserStatusBanner from './UserStatusBanner';
import UserFooter from './UserFooter';
import MobileNav from './MobileNav';
import ScrollToTop from '@/components/common/ScrollToTop';
import { Toaster } from '@/components/ui/toaster';
import { ProductQuickViewProvider } from '@/contexts/ProductQuickViewContext';
import ProductQuickViewMount from '@/components/product/ProductQuickViewMount';
import { AuthDialogProvider } from '@/contexts/AuthDialogContext';
import BugReportWidget from '@/Components/BugReportWidget';
import AgeGate from '@/components/common/AgeGate';
import CookieConsent from '@/components/common/CookieConsent';

export default function UserLayout({ children, fluid = false }) {
    return (
        <AuthDialogProvider>
        <ProductQuickViewProvider>
            <Box minH="100vh" bg="bg.subtle" display="flex" flexDirection="column" overflowX="clip">
                <UserHeader />
                <UserStatusBanner />
                <Box
                    as="main"
                    flex="1"
                    maxW="1360px"
                    mx="auto"
                    w="100%"
                    px={fluid ? { base: '0', md: '6' } : { base: '3', md: '6' }}
                    pt="3"
                    pb="6"
                    pb={{ base: 'calc(70px + env(safe-area-inset-bottom))', lg: '6' }}
                >
                    {children}
                </Box>
                <UserFooter />
                <MobileNav />
                <ScrollToTop />
                <ProductQuickViewMount />
                <Toaster />
                <BugReportWidget />
                <AgeGate />
                <CookieConsent />
            </Box>
        </ProductQuickViewProvider>
        </AuthDialogProvider>
    );
}
