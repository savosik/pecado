import { Drawer, Box } from "@chakra-ui/react";
import { NavigationMenu } from "./NavigationMenu";

export const MobileSidebar = ({ isOpen, onClose }) => {
    return (
        <Drawer.Root open={isOpen} onOpenChange={(e) => !e.open && onClose()} placement="start">
            <Drawer.Backdrop />
            <Drawer.Positioner>
                <Drawer.Content>
                    <Drawer.Header>
                        <Drawer.Title>
                            <Box as="img" src="/images/logo.png" alt="Pecado Admin" h="12" objectFit="contain" />
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
