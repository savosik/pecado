import { Box, Text, HStack, Badge } from '@chakra-ui/react';
import { usePanel } from './PanelContext';
import { NavigationMenu } from './NavigationMenu';

export const Sidebar = ({ isCollapsed = false }) => {
    const { logoAlt, badge, logoHeight = 'full', footer = null } = usePanel();

    return (
        <Box
            as="nav"
            position="fixed"
            left={0}
            top={0}
            h="100vh"
            w={isCollapsed ? '16' : '64'}
            bg="bg.panel"
            borderRightWidth="1px"
            borderColor="border.muted"
            overflowY="auto"
            display={{ base: 'none', md: 'block' }}
            transition="width 0.2s"
            zIndex="sticky"
        >
            {/* Логотип */}
            <Box px={4} mb={6} h="12" display="flex" alignItems="center">
                {isCollapsed ? (
                    <Text fontSize="xl" fontWeight="bold" color="fg">P</Text>
                ) : (
                    <HStack gap={2}>
                        <Box as="img" src="/images/logo.png" alt={logoAlt} h={logoHeight} objectFit="contain" />
                        {badge && <Badge colorPalette="pecado" variant="subtle">{badge}</Badge>}
                    </HStack>
                )}
            </Box>

            <NavigationMenu isCollapsed={isCollapsed} />

            {/* Подвал меню — слот панели: в CRM здесь зарплата, у склада и
                админки его нет, поэтому виджет не встроен в каркас. */}
            {footer && !isCollapsed && <Box px={3} pt={4} pb={6}>{footer}</Box>}
        </Box>
    );
};
