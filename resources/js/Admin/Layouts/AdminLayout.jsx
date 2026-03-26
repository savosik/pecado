import { useState } from "react";
import { Box } from "@chakra-ui/react";
import { Sidebar } from "../Components/Sidebar";
import { MobileSidebar } from "../Components/MobileSidebar";
import { Header } from "../Components/Header";
import { Toaster } from "@/components/ui/toaster";

export const AdminLayout = ({ children, breadcrumbs = [] }) => {
    const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

    return (
        <Box minH="100vh" bg="bg.subtle">
            {/* Desktop Sidebar */}
            <Sidebar />

            {/* Mobile Sidebar (Drawer) */}
            <MobileSidebar
                isOpen={isMobileMenuOpen}
                onClose={() => setIsMobileMenuOpen(false)}
            />

            {/* Main Content */}
            <Box ml={{ base: 0, md: 64 }}>
                {/* Header */}
                <Header
                    onMobileMenuOpen={() => setIsMobileMenuOpen(true)}
                    breadcrumbs={breadcrumbs}
                />

                {/* Page Content */}
                <Box as="main" p={{ base: 4, md: 6 }}>
                    {children}
                </Box>
            </Box>

            <Toaster />
        </Box>
    );
};

export default AdminLayout;
