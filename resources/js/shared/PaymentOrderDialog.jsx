import { useEffect, useState } from 'react';
import { Dialog, Portal, Spinner, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import PaymentOrderForm from '@/shared/PaymentOrderForm';

/**
 * Диалог платёжки «бери и плати» (pay-01) — один для кабинета и CRM.
 *
 * Данные (пары, сценарии, адресная книга) грузятся при открытии, а не с
 * родительской страницей: регистр считать ради кнопки, которую могут не нажать,
 * незачем.
 *
 * @param {boolean} open
 * @param {Function} onClose
 * @param {string} title
 * @param {string|null} description — строка под заголовком (например, по какому документу)
 * @param {() => Promise<object>} loadOptions — промис с PaymentOrderService::options()
 * @param {(params: object) => string} previewUrl
 * @param {(params: object, format: 'pdf'|'txt') => string} downloadUrl
 * @param {(payload: object) => Promise<void>} onSend
 * @param {object|null} preset — см. PaymentOrderForm
 */
export default function PaymentOrderDialog({
    open,
    onClose,
    title = 'Платёжное поручение',
    description = null,
    loadOptions,
    previewUrl,
    downloadUrl,
    onSend,
    preset = null,
}) {
    const [options, setOptions] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!open) return undefined;
        let cancelled = false;
        setOptions(null);
        setError(null);
        loadOptions()
            .then((data) => { if (!cancelled) setOptions(data); })
            .catch(() => { if (!cancelled) setError('Не удалось загрузить данные по расчётам'); });
        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="xl" scrollBehavior="inside">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header flexDirection="column" alignItems="flex-start" gap="1">
                            <Dialog.Title>{title}</Dialog.Title>
                            {description && <Text fontSize="sm" color="fg.muted">{description}</Text>}
                        </Dialog.Header>
                        <Dialog.Body>
                            {error && <Text color="red.fg">{error}</Text>}
                            {!options && !error && <Spinner size="sm" />}
                            {options && (
                                <PaymentOrderForm
                                    compact
                                    options={options}
                                    previewUrl={previewUrl}
                                    downloadUrl={downloadUrl}
                                    onSend={onSend}
                                    preset={preset}
                                />
                            )}
                        </Dialog.Body>
                        <Dialog.Footer>
                            <Button variant="outline" onClick={onClose}>Закрыть</Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
