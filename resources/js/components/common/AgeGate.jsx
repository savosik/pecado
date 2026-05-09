import { useEffect, useState, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { Box, Button, Flex, Heading, Text } from '@chakra-ui/react';

const STORAGE_KEY = 'age_confirmed_18plus_at';
const CONFIRM_TTL_MS = 60 * 60 * 1000; // 1 час
const EXIT_URL = 'https://www.google.com';

/**
 * Возрастной гейт для публичной части сайта.
 *
 * Поведение:
 *  - Любому аутентифицированному пользователю — модалка не показывается,
 *    блюр не применяется.
 *  - Гостю:
 *      * нет валидного timestamp в localStorage (или ему больше часа) —
 *        модалка + блюр изображений (класс nsfw-blur на <html>);
 *      * нажатие «Да, мне есть 18» — пишем текущий timestamp, скрываем
 *        модалку, снимаем блюр;
 *      * «Уйти» — редирект на EXIT_URL.
 */
export default function AgeGate() {
    const { auth } = usePage().props;
    const user = auth?.user || null;
    const isAuthenticated = !!user;

    // gateActive = нужно ли показывать гейт (модалка + блюр).
    // Начальное значение null — до прочтения localStorage не рендерим ничего,
    // чтобы не моргать модалкой подтверждённому пользователю.
    const [gateActive, setGateActive] = useState(null);

    useEffect(() => {
        if (isAuthenticated) {
            setGateActive(false);
            return;
        }
        let confirmedAt = 0;
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            confirmedAt = raw ? Number.parseInt(raw, 10) : 0;
            if (!Number.isFinite(confirmedAt)) confirmedAt = 0;
        } catch {
            confirmedAt = 0;
        }
        const isFresh = confirmedAt > 0 && Date.now() - confirmedAt < CONFIRM_TTL_MS;
        setGateActive(!isFresh);
    }, [isAuthenticated]);

    useEffect(() => {
        const root = document.documentElement;
        if (gateActive) {
            root.classList.add('nsfw-blur');
        } else {
            root.classList.remove('nsfw-blur');
        }
        return () => root.classList.remove('nsfw-blur');
    }, [gateActive]);

    const handleConfirm = useCallback(() => {
        try {
            window.localStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch {
            /* localStorage недоступен — флаг просто не сохранится */
        }
        setGateActive(false);
    }, []);

    const handleExit = useCallback(() => {
        window.location.href = EXIT_URL;
    }, []);

    if (!gateActive) return null;

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
                bg="bg"
                borderRadius="md"
                shadow="xl"
                maxW="480px"
                w="100%"
                p="6"
            >
                <Heading
                    id="age-gate-title"
                    as="h2"
                    fontSize="xl"
                    mb="3"
                    color="fg"
                >
                    Внимание!
                </Heading>
                <Box borderTopWidth="1px" borderColor="border" mb="4" />
                <Text fontSize="sm" mb="2" color="fg">
                    Данный сайт содержит материалы для взрослых.
                </Text>
                <Text fontSize="sm" mb="6" color="fg">
                    Чтобы продолжить, вы должны подтвердить, что вам уже исполнилось 18 лет.
                </Text>
                <Flex gap="4" align="center">
                    <Button
                        colorPalette="pecado"
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
                        _hover={{
                            color: { base: 'pecado.700', _dark: 'pecado.200' },
                            textDecoration: 'underline',
                        }}
                        onClick={handleExit}
                    >
                        Уйти
                    </Button>
                </Flex>
            </Box>
        </Box>
    );
}
