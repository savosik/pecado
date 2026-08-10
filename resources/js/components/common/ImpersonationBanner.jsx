import { useState } from 'react';
import { Box, HStack, Text } from '@chakra-ui/react';
import { usePage, router } from '@inertiajs/react';
import { LuEye } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

/**
 * Плашка режима «менеджер смотрит сайт от имени партнёра».
 *
 * Рендерится в GlobalLayout (app.jsx), поэтому видна на всех страницах —
 * и на витрине, и в кабинете, независимо от лэйаута страницы.
 *
 * Плавающая, а не в потоке документа: шапки страниц sticky, и полоса сверху
 * либо перекрывалась бы ими, либо сдвигала вёрстку. Снизу отступ поднимает
 * плашку над мобильной навигацией.
 */
export default function ImpersonationBanner() {
    const { impersonation } = usePage().props;
    const [leaving, setLeaving] = useState(false);

    if (!impersonation) return null;

    const handleStop = () => {
        setLeaving(true);
        router.post(impersonation.stop_url, {}, {
            onFinish: () => setLeaving(false),
        });
    };

    return (
        <Box
            role="status"
            position="fixed"
            left="50%"
            transform="translateX(-50%)"
            bottom={{ base: 'calc(70px + env(safe-area-inset-bottom))', lg: '4' }}
            zIndex="modal"
            maxW="calc(100vw - 16px)"
            bg="orange.100"
            borderWidth="2px"
            borderColor="orange.400"
            _dark={{ bg: 'orange.900', borderColor: 'orange.600' }}
            borderRadius="lg"
            boxShadow="lg"
            px="4"
            py="2"
        >
            <HStack gap="3" flexWrap="wrap" justifyContent="center">
                <Box color="orange.600" _dark={{ color: 'orange.200' }} fontSize="lg" lineHeight="1">
                    <LuEye />
                </Box>
                <Text fontSize="sm" color="orange.900" _dark={{ color: 'orange.100' }}>
                    Просмотр от имени партнёра:{' '}
                    <Text as="span" fontWeight="bold">{impersonation.client_name}</Text>
                    {' '}· оформлять заказы нельзя
                </Text>
                <Button
                    size="xs"
                    colorPalette="orange"
                    loading={leaving}
                    onClick={handleStop}
                >
                    Вернуться в CRM
                </Button>
            </HStack>
        </Box>
    );
}
