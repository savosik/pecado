import { Box } from '@chakra-ui/react';
import UserHeader from './UserHeader';
import UserFooter from './UserFooter';
import MobileNav from './MobileNav';
import ScrollToTop from '@/components/common/ScrollToTop';
import { ProductQuickViewProvider } from '@/contexts/ProductQuickViewContext';
import ProductQuickViewMount from '@/components/product/ProductQuickViewMount';

export default function UserLayout({ children }) {
    return (
        <ProductQuickViewProvider>
            <Box minH="100vh" bg="#f4f4f4" _dark={{ bg: 'gray.900' }} display="flex" flexDirection="column">
                <UserHeader />
                <Box
                    as="main"
                    flex="1"
                    maxW="1360px"
                    mx="auto"
                    w="100%"
                    px={{ base: '3', md: '6' }}
                    py="6"
                    pb={{ base: '70px', lg: '6' }}
                >
                    {children}
                </Box>
                <UserFooter />
                <MobileNav />
                <ScrollToTop />
                <ProductQuickViewMount />
            </Box>
        </ProductQuickViewProvider>
    );
}
