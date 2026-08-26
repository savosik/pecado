import React from 'react';
import { Dialog, Portal, Button } from '@chakra-ui/react';

/**
 * ConfirmDialog - диалог подтверждения (например, удаления)
 * 
 * @param {boolean} open - Открыт ли диалог
 * @param {Function} onClose - Callback закрытия
 * @param {Function} onConfirm - Callback подтверждения
 * @param {string} title - Заголовок диалога
 * @param {string} description - Описание/текст диалога
 * @param {string} confirmLabel - Текст кнопки подтверждения
 * @param {string} cancelLabel - Текст кнопки отмены
 * @param {boolean} isLoading - Индикация загрузки
 * @param {string} colorPalette - Цветовая схема кнопки подтверждения
 */
export const ConfirmDialog = ({
    open = false,
    onClose,
    onConfirm,
    title = 'Подтверждение действия',
    description = 'Вы уверены, что хотите выполнить это действие?',
    confirmLabel = 'Подтвердить',
    cancelLabel = 'Отмена',
    isLoading = false,
    colorPalette = 'red',
}) => {
    const handleConfirm = () => {
        onConfirm();
        if (!isLoading) {
            onClose();
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={({ open }) => !open && onClose()}>
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{title}</Dialog.Title>
                        </Dialog.Header>
                        <Dialog.Body>
                            <Dialog.Description>
                                {description}
                            </Dialog.Description>
                        </Dialog.Body>
                        <Dialog.Footer>
                            <Dialog.ActionTrigger asChild>
                                <Button
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={isLoading}
                                >
                                    {cancelLabel}
                                </Button>
                            </Dialog.ActionTrigger>
                            <Button
                                colorPalette={colorPalette}
                                onClick={handleConfirm}
                                loading={isLoading}
                            >
                                {confirmLabel}
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
};
