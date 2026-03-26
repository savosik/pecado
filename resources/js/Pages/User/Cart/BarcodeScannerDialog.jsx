import { Dialog, Portal, CloseButton, Text } from '@chakra-ui/react';
import BarcodeScanner from './BarcodeScanner';

/**
 * Диалог сканера штрихкодов для корзины.
 *
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   onSuccess?: (data: any) => void,
 * }} props
 */
export default function BarcodeScannerDialog({ open, onClose, onSuccess }) {
    const handleSuccess = (data) => {
        onSuccess?.(data);
    };

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && onClose()}
            size="lg"
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content maxW="lg">
                        <Dialog.Header>
                            <Dialog.Title>Добавить товар по штрихкоду</Dialog.Title>
                            <Dialog.CloseTrigger asChild>
                                <CloseButton size="sm" />
                            </Dialog.CloseTrigger>
                        </Dialog.Header>
                        <Dialog.Body>
                            <Text fontSize="sm" color="fg.muted" mb="4">
                                Отсканируйте штрихкод товара или введите его вручную для быстрого добавления в корзину.
                            </Text>
                            <BarcodeScanner
                                onClose={onClose}
                                onSuccess={handleSuccess}
                            />
                        </Dialog.Body>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
