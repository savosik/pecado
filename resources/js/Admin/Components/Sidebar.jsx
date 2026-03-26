import { Box, Text } from "@chakra-ui/react";
import { NavigationMenu } from "./NavigationMenu";

export const Sidebar = ({ isCollapsed = false }) => {
    return (
        <Box
            as="nav"
            position="fixed"
            left={0}
            top={0}
            h="100vh"
            w={isCollapsed ? "16" : "64"}
            bg="bg.panel"
            borderRightWidth="1px"
            borderColor="border.muted"
            overflowY="auto"
            display={{ base: "none", md: "block" }}
            transition="width 0.2s"
            zIndex="sticky"
        >
            {/* Logo */}
            <Box px={4} mb={6} h="12" display="flex" alignItems="center">
                {isCollapsed ? (
                    <Text fontSize="xl" fontWeight="bold" color="fg.default">P</Text>
                ) : (
                    <Box as="img" src="/images/logo.png" alt="Pecado Admin" h="full" objectFit="contain" />
                )}
            </Box>

            {/* Navigation */}
            <NavigationMenu isCollapsed={isCollapsed} />
        </Box>
    );
};
