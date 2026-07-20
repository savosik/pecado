import { Drawer, Box, HStack, Badge } from '@chakra-ui/react';
import { usePanel } from './PanelContext';
import { NavigationMenu } from './NavigationMenu';

export const MobileSidebar = ({ isOpen, onClose }) => {
    const { logoAlt, badge } = usePanel();

    return (
        <Drawer.Root open={isOpen} onOpenChange={(e) => !e.open && onClose()} placement="start">
            <Drawer.Backdrop />
            <Drawer.Positioner>
                <Drawer.Content>
                    <Drawer.Header>
                        <Drawer.Title>
                            <HStack gap={2}>
                                <Box as="img" src="/images/logo.png" alt={logoAlt} h="10" objectFit="contain" />
                                {badge && <Badge colorPalette="pecado" variant="subtle">{badge}</Badge>}
                            </HStack>
                        </Drawer.Title>
                        <Drawer.CloseTrigger />
                    </Drawer.Header>
                    <Drawer.Body>
                        <NavigationMenu onItemClick={onClose} />
                    </Drawer.Body>
                </Drawer.Content>
            </Drawer.Positioner>
        </Drawer.Root>
    );
};
