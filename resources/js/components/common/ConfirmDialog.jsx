import { Dialog, Portal, Button } from '@chakra-ui/react';

/**
 * Диалог подтверждения — accessible modal с backdrop, focus trap, Escape.
 *
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   onConfirm: () => void,
 *   title?: string,
 *   description?: string,
 *   confirmLabel?: string,
 *   cancelLabel?: string,
 *   colorPalette?: string,
 *   children?: React.ReactNode,
 * }} props
 */
export default function ConfirmDialog({
    open = false,
    onClose,
    onConfirm,
    title = 'Подтверждение действия',
    description = 'Вы уверены, что хотите выполнить это действие?',
    confirmLabel = 'Подтвердить',
    cancelLabel = 'Отмена',
    colorPalette = 'red',
    children,
}) {
    const handleConfirm = () => {
        onConfirm();
        onClose();
    };

    return (
        <Dialog.Root open={open} onOpenChange={({ open: isOpen }) => !isOpen && onClose()}>
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{title}</Dialog.Title>
                        </Dialog.Header>
                        <Dialog.Body>
                            {children || (
                                <Dialog.Description>
                                    {description}
                                </Dialog.Description>
                            )}
                        </Dialog.Body>
                        <Dialog.Footer>
                            <Dialog.ActionTrigger asChild>
                                <Button variant="outline" onClick={onClose}>
                                    {cancelLabel}
                                </Button>
                            </Dialog.ActionTrigger>
                            <Button
                                colorPalette={colorPalette}
                                onClick={handleConfirm}
                            >
                                {confirmLabel}
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
