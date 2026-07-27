import { Box, Flex, Text } from '@chakra-ui/react';
import { LuTag } from 'react-icons/lu';

/**
 * PromotionFilter — фильтр «Участвует в акции».
 *
 * Источник тот же, что у бейджа «Акция» в карточке: контентная привязка к
 * активной акции или условие активного правила конструктора промо.
 *
 * @param {{ value?: boolean, onChange: (checked: boolean) => void }} props
 */
export default function PromotionFilter({ value = false, onChange }) {
    const isActive = Boolean(value);

    return (
        <Flex
            as="button"
            type="button"
            align="center"
            gap="2.5"
            px="2"
            py="1.5"
            borderRadius="md"
            cursor="pointer"
            transition="all 0.15s"
            w="100%"
            bg={isActive ? 'pecado.50' : 'transparent'}
            _dark={{ bg: isActive ? 'pecado.900/30' : 'transparent' }}
            _hover={{
                bg: isActive ? 'pecado.100' : 'gray.50',
                _dark: { bg: isActive ? 'pecado.900/40' : 'gray.700' },
            }}
            onClick={() => onChange(!isActive)}
            aria-pressed={isActive}
        >
            {/* Чекбокс в стиле StockFilter — без Chakra-обёртки, как у соседних фильтров */}
            <Box
                w="16px"
                h="16px"
                borderRadius="sm"
                border="2px solid"
                borderColor={isActive ? 'pecado.500' : 'gray.300'}
                bg={isActive ? 'pecado.500' : 'transparent'}
                _dark={{ borderColor: isActive ? 'pecado.400' : 'gray.600', bg: isActive ? 'pecado.400' : 'transparent' }}
                display="flex"
                alignItems="center"
                justifyContent="center"
                flexShrink="0"
                transition="all 0.15s"
            >
                {isActive && (
                    <Box as="span" color="white" fontSize="10px" lineHeight="1" fontWeight="700">
                        ✓
                    </Box>
                )}
            </Box>

            <Flex align="center" gap="1.5">
                <Box color={isActive ? 'pecado.500' : 'gray.400'} _dark={{ color: isActive ? 'pecado.300' : 'gray.500' }}>
                    <LuTag size={14} />
                </Box>
                <Text
                    fontSize="sm"
                    color={isActive ? 'pecado.600' : 'gray.700'}
                    _dark={{ color: isActive ? 'pecado.300' : 'gray.300' }}
                    fontWeight={isActive ? '500' : '400'}
                >
                    Участвует в акции
                </Text>
            </Flex>
        </Flex>
    );
}
