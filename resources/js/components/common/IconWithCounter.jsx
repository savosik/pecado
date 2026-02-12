import { Box, IconButton, Circle, Icon } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

/**
 * Иконка с badge-счётчиком (для корзины, избранного и т.п.).
 *
 * @param {{
 *   icon: React.ElementType,
 *   count: number,
 *   onClick?: () => void,
 *   href?: string,
 *   ariaLabel: string,
 * }} props
 */
export default function IconWithCounter({ icon, count, onClick, href, ariaLabel }) {
    const hasCount = count > 0;

    const badge = hasCount && (
        <Circle
            position="absolute"
            top="-1"
            right="-1"
            size="5"
            bg="pecado.solid"
            color="white"
            fontSize="2xs"
            fontWeight="bold"
            lineHeight="1"
            pointerEvents="none"
        >
            {count > 99 ? '99+' : count}
        </Circle>
    );

    if (href) {
        return (
            <Box position="relative" display="inline-flex">
                <IconButton
                    asChild
                    aria-label={ariaLabel}
                    variant="ghost"
                    size="md"
                    rounded="full"
                >
                    <Link href={href}>
                        <Icon as={icon} boxSize="5" />
                    </Link>
                </IconButton>
                {badge}
            </Box>
        );
    }

    return (
        <Box position="relative" display="inline-flex">
            <IconButton
                aria-label={ariaLabel}
                variant="ghost"
                size="md"
                rounded="full"
                onClick={onClick}
            >
                <Icon as={icon} boxSize="5" />
            </IconButton>
            {badge}
        </Box>
    );
}
