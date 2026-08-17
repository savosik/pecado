import { useEffect, useState } from 'react';
import axios from 'axios';
import { Box, Dialog, HStack, Portal, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Push-уведомления по задачам в этом браузере.
 *
 * Разрешение запрашивается только по клику на тумблер: авто-запрос при входе —
 * верный способ получить вечное «Блокировать». Несколько браузеров = несколько
 * подписок, протухшие сервер чистит сам.
 */
export default function TaskPushDialog({ open, onClose }) {
    const [state, setState] = useState({ enabled: false, subscribed: false, publicKey: null });
    const [permission, setPermission] = useState(
        typeof Notification !== 'undefined' ? Notification.permission : 'unsupported',
    );
    const [busy, setBusy] = useState(false);

    const supported = typeof Notification !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window;

    useEffect(() => {
        if (!open) {
            return;
        }

        axios.get('/crm/push-subscriptions/status')
            .then((res) => setState({
                enabled: res.data.enabled,
                subscribed: res.data.subscribed,
                publicKey: res.data.public_key,
            }))
            .catch(() => toastError('Не удалось получить состояние уведомлений'));
    }, [open]);

    const subscribe = async () => {
        setBusy(true);
        try {
            const granted = await Notification.requestPermission();
            setPermission(granted);

            if (granted !== 'granted') {
                toastError('Уведомления не разрешены', 'Разрешите их в настройках сайта в браузере.');

                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(state.publicKey),
            });

            const json = subscription.toJSON();
            await axios.post('/crm/push-subscriptions', {
                endpoint: json.endpoint,
                keys: json.keys,
                content_encoding: 'aes128gcm',
            });

            setState((prev) => ({ ...prev, subscribed: true }));
            toastSuccess('Push включены', 'Напоминания будут приходить и при закрытой вкладке.');
        } catch (e) {
            toastError('Не удалось подписаться', e?.response?.data?.message || String(e?.message || 'Попробуйте ещё раз.'));
        } finally {
            setBusy(false);
        }
    };

    const unsubscribe = async () => {
        setBusy(true);
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            await axios.delete('/crm/push-subscriptions', {
                data: { endpoint: subscription?.endpoint || null },
            });
            await subscription?.unsubscribe();

            setState((prev) => ({ ...prev, subscribed: false }));
            toastSuccess('Push выключены в этом браузере');
        } catch (e) {
            toastError('Не удалось отписаться', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const toggle = (checked) => (checked ? subscribe() : unsubscribe());

    return (
        <Dialog.Root open={open} onOpenChange={({ open: isOpen }) => !isOpen && onClose()} size="md">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Уведомления браузера</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                {!state.enabled && (
                                    <Text fontSize="sm" color="fg.muted">
                                        Push-уведомления пока выключены на сервере. Тосты внутри CRM
                                        работают и без них.
                                    </Text>
                                )}

                                {state.enabled && !supported && (
                                    <Text fontSize="sm" color="orange.fg">
                                        Этот браузер не поддерживает push-уведомления.
                                    </Text>
                                )}

                                {state.enabled && supported && (
                                    <>
                                        <HStack justify="space-between">
                                            <Box>
                                                <Text fontSize="sm" fontWeight="600">Push в этом браузере</Text>
                                                <Text fontSize="xs" color="fg.muted">
                                                    Напоминания о сроках и назначениях — даже при закрытой вкладке CRM.
                                                </Text>
                                            </Box>
                                            <Switch
                                                checked={state.subscribed}
                                                disabled={busy || permission === 'denied'}
                                                onCheckedChange={(e) => toggle(!!e.checked)}
                                            />
                                        </HStack>

                                        {permission === 'denied' && (
                                            <Text fontSize="xs" color="red.fg">
                                                Уведомления заблокированы для сайта. Разблокируйте их в настройках
                                                браузера (значок замка в адресной строке) и попробуйте снова.
                                            </Text>
                                        )}
                                    </>
                                )}
                            </VStack>
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

/**
 * VAPID public key: base64url → Uint8Array для pushManager.subscribe.
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);

    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}
