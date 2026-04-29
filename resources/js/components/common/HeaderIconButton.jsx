import { forwardRef } from 'react';
import { Box } from '@chakra-ui/react';

/**
 * Иконка-кнопка для шапки сайта с опциональным бейджем-счётчиком.
 *
 * @param {{
 *   icon: React.ElementType,
 *   count?: number,
 *   badgeColor?: string,
 *   badgeProps?: object,  // объект пропсов для Box бейджа (bg/style/color/...)
 *   'aria-label': string,
 *   [key: string]: any,
 * }} props
 */
const HeaderIconButton = forwardRef(function HeaderIconButton(
    { icon: Icon, count = 0, badgeColor = 'red.500', badgeProps, 'aria-label': ariaLabel, ...rest },
    ref,
) {
    const finalBadgeProps = badgeProps ?? { bg: badgeColor, color: 'white' };
    return (
        <Box
            ref={ref}
            as="span"
            position="relative"
            display="inline-flex"
            p="2"
            borderRadius="sm"
            cursor="pointer"
            _hover={{ bg: 'gray.100' }}
            _dark={{ _hover: { bg: 'gray.800' } }}
            aria-label={ariaLabel}
            {...rest}
        >
            <Icon size={24} />
            {count > 0 && (
                <Box
                    as="span"
                    position="absolute"
                    top="0"
                    right="0"
                    fontSize="10px"
                    fontWeight="600"
                    borderRadius="full"
                    minW="18px"
                    h="18px"
                    lineHeight="18px"
                    textAlign="center"
                    px="4px"
                    boxShadow="sm"
                    pointerEvents="none"
                    {...finalBadgeProps}
                >
                    {count > 99 ? '99+' : count}
                </Box>
            )}
        </Box>
    );
});

export default HeaderIconButton;
