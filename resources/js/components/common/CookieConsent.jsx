import { useEffect, useState, useCallback } from 'react';
import { Link } from '@inertiajs/react';
import { Box, Button, Flex, Text } from '@chakra-ui/react';

const STORAGE_KEY = 'cookie_consent_accepted_at';

/**
 * Информационная панель об использовании cookies.
 *
 * Показывается, пока в localStorage нет timestamp принятия. После клика
 * по «Принять» сохраняем текущий timestamp и больше не показываем.
 * Высота нижнего отступа учитывает MobileNav на мобильных.
 */
export default function CookieConsent() {
    // null до чтения localStorage — чтобы не моргать панелью у тех,
    // кто уже согласился.
    const [visible, setVisible] = useState(null);

    useEffect(() => {
        let acceptedAt = 0;
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            acceptedAt = raw ? Number.parseInt(raw, 10) : 0;
            if (!Number.isFinite(acceptedAt)) acceptedAt = 0;
        } catch {
            acceptedAt = 0;
        }
        setVisible(acceptedAt <= 0);
    }, []);

    const handleAccept = useCallback(() => {
        try {
            window.localStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch {
            /* localStorage недоступен — баннер закроется только в текущей сессии */
        }
        setVisible(false);
    }, []);

    if (!visible) return null;

    return (
        <Box
            position="fixed"
            left={{ base: '3', md: '6' }}
            right={{ base: '3', md: '6' }}
            bottom={{
                base: 'calc(70px + env(safe-area-inset-bottom) + 8px)',
                lg: '6',
            }}
            zIndex="1400"
            bg="bg"
            color="fg"
            borderWidth="1px"
            borderColor="border"
            borderRadius="md"
            shadow="lg"
            px={{ base: '4', md: '6' }}
            py={{ base: '3', md: '4' }}
            maxW="1160px"
            mx="auto"
            role="region"
            aria-label="Использование cookies"
        >
            <Flex
                direction={{ base: 'column', md: 'row' }}
                align={{ base: 'stretch', md: 'center' }}
                gap={{ base: '3', md: '6' }}
            >
                <Text
                    fontSize="sm"
                    lineHeight="relaxed"
                    color="fg"
                    flex="1"
                >
                    Мы используем файлы cookie для работы сайта, аутентификации,
                    запоминания настроек и аналитики. Продолжая использовать сайт,
                    вы соглашаетесь с этим. Подробнее — в{' '}
                    <Link
                        href="/pages/cookies"
                        style={{ textDecoration: 'underline' }}
                    >
                        Политике cookies
                    </Link>
                    .
                </Text>
                <Button
                    colorPalette="pecado"
                    size="sm"
                    onClick={handleAccept}
                    flexShrink="0"
                >
                    Принять
                </Button>
            </Flex>
        </Box>
    );
}
