import { useEffect, useRef } from 'react';
import { Box, Flex, Text, Button, IconButton } from '@chakra-ui/react';
import { LuX, LuInfo, LuUndo2, LuTriangleAlert } from 'react-icons/lu';

const PALETTE = {
    info:    { bg: 'blue.50',   border: 'blue.200',   color: 'blue.700',   _dark: { bg: 'blue.900/30',   border: 'blue.700',   color: 'blue.200' } },
    warning: { bg: 'orange.50', border: 'orange.200', color: 'orange.700', _dark: { bg: 'orange.900/30', border: 'orange.700', color: 'orange.200' } },
    success: { bg: 'green.50',  border: 'green.200',  color: 'green.700',  _dark: { bg: 'green.900/30', border: 'green.700',  color: 'green.200' } },
};

const ICON = {
    info: LuInfo,
    warning: LuTriangleAlert,
    success: LuUndo2,
};

/**
 * Inline-баннер для уведомлений на странице корзины.
 * Альтернатива toast'ам — не перекрывает контент на мобильном.
 *
 * @param {{
 *   flash: { id: number, type: 'info'|'warning'|'success', title: string, description?: string, action?: { label: string, onClick: () => void } } | null,
 *   onDismiss: () => void,
 *   autoDismissMs?: number,
 * }} props
 */
export default function CartFlash({ flash, onDismiss, autoDismissMs = 5000 }) {
    const ref = useRef(null);

    useEffect(() => {
        if (!flash) return;
        // При появлении прокручиваем баннер в зону видимости (если ушёл за скролл).
        ref.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        const t = setTimeout(onDismiss, autoDismissMs);
        return () => clearTimeout(t);
    }, [flash?.id, onDismiss, autoDismissMs]);

    if (!flash) return null;

    const palette = PALETTE[flash.type] || PALETTE.info;
    const Icon = ICON[flash.type] || LuInfo;

    return (
        <Box
            ref={ref}
            position="sticky"
            top={{ base: '12px', md: '16px' }}
            zIndex="20"
            borderWidth="1px"
            borderColor={palette.border}
            bg={palette.bg}
            color={palette.color}
            _dark={palette._dark}
            rounded="md"
            px="3"
            py="2.5"
            shadow="md"
            css={{
                animation: 'cart-flash-in 220ms ease-out',
                '@keyframes cart-flash-in': {
                    from: { opacity: 0, transform: 'translateY(-4px)' },
                    to:   { opacity: 1, transform: 'translateY(0)' },
                },
            }}
        >
            <Flex align="center" gap="3" wrap="wrap">
                <Box flexShrink={0} display="flex" alignItems="center">
                    <Icon size={18} />
                </Box>
                <Box minW="0" flex="1">
                    <Text fontSize="sm" fontWeight="600" lineHeight="1.3">{flash.title}</Text>
                    {flash.description && (
                        <Text fontSize="xs" mt="0.5" opacity={0.85} lineHeight="1.3">
                            {flash.description}
                        </Text>
                    )}
                </Box>
                {flash.action && (
                    <Button
                        size="xs"
                        variant="outline"
                        colorPalette={flash.type === 'warning' ? 'orange' : flash.type === 'success' ? 'green' : 'blue'}
                        onClick={() => {
                            flash.action.onClick();
                            onDismiss();
                        }}
                        flexShrink={0}
                    >
                        <LuUndo2 size={14} />
                        {flash.action.label}
                    </Button>
                )}
                <IconButton
                    aria-label="Закрыть"
                    size="xs"
                    variant="ghost"
                    colorPalette="gray"
                    onClick={onDismiss}
                    flexShrink={0}
                >
                    <LuX size={14} />
                </IconButton>
            </Flex>
        </Box>
    );
}
