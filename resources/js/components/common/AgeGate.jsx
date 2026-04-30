import { useEffect, useState, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { Box, Button, Flex, Heading, Text } from '@chakra-ui/react';

const STORAGE_KEY = 'age_confirmed_18plus';
const EXIT_URL = 'https://www.google.com';

/**
 * Возрастной гейт для публичной части сайта.
 *
 * Поведение:
 *  - Аутентифицированным активным пользователям (status=active или is_admin)
 *    модалка не показывается и блюр не применяется.
 *  - Гостю и неактивному юзеру:
 *      * если в localStorage нет флага подтверждения — показываем модалку
 *        и блюрим изображения (через класс html.nsfw-blur);
 *      * после «Да, мне есть 18» — модалка скрывается, флаг сохраняется,
 *        но блюр остаётся до регистрации/активации (так и хотел заказчик);
 *      * «Уйти» — редирект на google.com.
 */
export default function AgeGate() {
    const { auth } = usePage().props;
    const user = auth?.user || null;
    const isFullAccess = !!user && (user.is_admin || user.status === 'active');

    const [showModal, setShowModal] = useState(false);
    const [needsBlur, setNeedsBlur] = useState(false);

    useEffect(() => {
        if (isFullAccess) {
            setShowModal(false);
            setNeedsBlur(false);
            return;
        }
        let confirmed = false;
        try {
            confirmed = window.localStorage.getItem(STORAGE_KEY) === '1';
        } catch {
            confirmed = false;
        }
        setShowModal(!confirmed);
        setNeedsBlur(true);
    }, [isFullAccess]);

    useEffect(() => {
        const root = document.documentElement;
        if (needsBlur) {
            root.classList.add('nsfw-blur');
        } else {
            root.classList.remove('nsfw-blur');
        }
        return () => root.classList.remove('nsfw-blur');
    }, [needsBlur]);

    const handleConfirm = useCallback(() => {
        try {
            window.localStorage.setItem(STORAGE_KEY, '1');
        } catch {
            /* localStorage недоступен — просто закрываем модалку на сессию */
        }
        setShowModal(false);
    }, []);

    const handleExit = useCallback(() => {
        window.location.href = EXIT_URL;
    }, []);

    if (!showModal) return null;

    return (
        <Box
            position="fixed"
            inset="0"
            bg="blackAlpha.500"
            zIndex="1500"
            display="flex"
            alignItems="center"
            justifyContent="center"
            px="4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="age-gate-title"
        >
            <Box
                bg="white"
                _dark={{ bg: 'gray.800' }}
                borderRadius="md"
                shadow="xl"
                maxW="480px"
                w="100%"
                p="6"
            >
                <Heading id="age-gate-title" as="h2" fontSize="xl" mb="3">
                    Внимание!
                </Heading>
                <Box borderTopWidth="1px" borderColor={{ base: 'gray.200', _dark: 'gray.700' }} mb="4" />
                <Text fontSize="sm" mb="2">
                    Данный сайт содержит материалы для взрослых.
                </Text>
                <Text fontSize="sm" mb="6">
                    Чтобы продолжить, вы должны подтвердить, что вам уже исполнилось 18 лет.
                </Text>
                <Flex gap="4" align="center">
                    <Button
                        colorPalette="red"
                        size="md"
                        onClick={handleConfirm}
                        autoFocus
                    >
                        Да, мне есть 18
                    </Button>
                    <Button
                        variant="ghost"
                        size="md"
                        color={{ base: 'pecado.600', _dark: 'pecado.300' }}
                        onClick={handleExit}
                    >
                        Уйти
                    </Button>
                </Flex>
            </Box>
        </Box>
    );
}
