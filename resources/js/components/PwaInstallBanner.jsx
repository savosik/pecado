import { useState, useEffect } from 'react';
import { Box, Card, Flex, Text, HStack } from '@chakra-ui/react';
import { LuSmartphone, LuX } from 'react-icons/lu';

const STORAGE_KEY = 'pwa_install_dismissed';

function isMobileDevice() {
    return /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
}

function isIosDevice() {
    return /iPhone|iPad|iPod/i.test(navigator.userAgent);
}

function isAlreadyInstalled() {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true
    );
}

export default function PwaInstallBanner() {
    const [deferredPrompt, setDeferredPrompt] = useState(null);
    const [showIosHint, setShowIosHint] = useState(false);
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => {
        if (localStorage.getItem(STORAGE_KEY)) return;
        if (!isMobileDevice()) return;
        if (isAlreadyInstalled()) return;

        if (window.__pwaInstallPrompt) {
            setDeferredPrompt(window.__pwaInstallPrompt);
        }

        const handler = (e) => {
            e.preventDefault();
            window.__pwaInstallPrompt = e;
            setDeferredPrompt(e);
        };
        window.addEventListener('beforeinstallprompt', handler);

        if (isIosDevice()) {
            setShowIosHint(true);
        }

        return () => window.removeEventListener('beforeinstallprompt', handler);
    }, []);

    const handleInstall = async () => {
        if (!deferredPrompt) return;
        await deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        window.__pwaInstallPrompt = null;
        setDeferredPrompt(null);
        if (outcome === 'accepted') {
            localStorage.setItem(STORAGE_KEY, '1');
            setDismissed(true);
        }
    };

    const handleDismiss = () => {
        localStorage.setItem(STORAGE_KEY, '1');
        setDismissed(true);
    };

    if (dismissed) return null;
    if (!deferredPrompt && !showIosHint) return null;

    return (
        <Card.Root
            mb="6"
            borderRadius="xl"
            overflow="hidden"
            border="1px solid"
            borderColor="pecado.200"
            bg="pecado.50"
            _dark={{ bg: 'pecado.900/10', borderColor: 'pecado.800' }}
        >
            <Card.Body p="5">
                <Flex align="center" gap="4">
                    <Flex
                        align="center"
                        justify="center"
                        w="10"
                        h="10"
                        borderRadius="xl"
                        bg="pecado.100"
                        color="pecado.600"
                        _dark={{ bg: 'pecado.900/20', color: 'pecado.300' }}
                        flexShrink="0"
                    >
                        <LuSmartphone size={20} />
                    </Flex>
                    <Box flex="1">
                        <Text fontSize="sm" fontWeight="600" color="pecado.800" _dark={{ color: 'pecado.200' }}>
                            Установить приложение
                        </Text>
                        {showIosHint && !deferredPrompt ? (
                            <Text fontSize="xs" color="pecado.600" _dark={{ color: 'pecado.400' }}>
                                Нажмите <Box as="strong">«Поделиться»</Box> в браузере и выберите <Box as="strong">«На экран "Домой"»</Box>
                            </Text>
                        ) : (
                            <Text fontSize="xs" color="pecado.600" _dark={{ color: 'pecado.400' }}>
                                Добавьте Pecado на рабочий стол для быстрого доступа к заказам и каталогу.
                            </Text>
                        )}
                    </Box>
                    <HStack gap="2" flexShrink="0">
                        {deferredPrompt && (
                            <Box
                                as="button"
                                px="4"
                                py="2"
                                borderRadius="lg"
                                bg="pecado.600"
                                color="white"
                                fontSize="sm"
                                fontWeight="600"
                                _hover={{ bg: 'pecado.700' }}
                                transition="background 0.15s"
                                cursor="pointer"
                                onClick={handleInstall}
                            >
                                Установить
                            </Box>
                        )}
                        <Box
                            as="button"
                            p="2"
                            borderRadius="lg"
                            color="pecado.500"
                            _hover={{ bg: 'pecado.100', _dark: { bg: 'pecado.900/20' } }}
                            transition="background 0.15s"
                            cursor="pointer"
                            onClick={handleDismiss}
                            aria-label="Закрыть"
                        >
                            <LuX size={16} />
                        </Box>
                    </HStack>
                </Flex>
            </Card.Body>
        </Card.Root>
    );
}
