import { Box, Flex, Text } from '@chakra-ui/react';

const STOCK_OPTIONS = [
    { value: '', label: 'Все' },
    { value: 'instock', label: 'В наличии' },
    { value: 'preorder', label: 'Предзаказ' },
    { value: 'notavailable', label: 'Нет в наличии' },
    { value: 'defect', label: 'Некондиция' },
];

/**
 * StockFilter — фильтр по наличию товара (radio-кнопки).
 *
 * @param {{
 *   value?: string,
 *   onChange: (mode: string) => void,
 * }} props
 */
export default function StockFilter({ value = '', onChange }) {
    return (
        <Flex direction="column" gap="1">
            {STOCK_OPTIONS.map((opt) => {
                const isActive = value === opt.value;

                return (
                    <Flex
                        key={opt.value}
                        as="button"
                        type="button"
                        align="center"
                        gap="2.5"
                        px="2"
                        py="1.5"
                        borderRadius="md"
                        cursor="pointer"
                        transition="all 0.15s"
                        bg={isActive ? 'pecado.50' : 'transparent'}
                        _dark={{ bg: isActive ? 'pecado.900/30' : 'transparent' }}
                        _hover={{
                            bg: isActive ? 'pecado.100' : 'gray.50',
                            _dark: { bg: isActive ? 'pecado.900/40' : 'gray.700' },
                        }}
                        onClick={() => onChange(opt.value)}
                    >
                        {/* Custom radio circle */}
                        <Box
                            w="16px"
                            h="16px"
                            borderRadius="full"
                            border="2px solid"
                            borderColor={isActive ? 'pecado.500' : 'gray.300'}
                            _dark={{ borderColor: isActive ? 'pecado.400' : 'gray.600' }}
                            display="flex"
                            alignItems="center"
                            justifyContent="center"
                            flexShrink="0"
                            transition="border-color 0.15s"
                        >
                            {isActive && (
                                <Box
                                    w="8px"
                                    h="8px"
                                    borderRadius="full"
                                    bg="pecado.500"
                                    _dark={{ bg: 'pecado.400' }}
                                />
                            )}
                        </Box>

                        <Text
                            fontSize="sm"
                            color={isActive ? 'pecado.600' : 'gray.700'}
                            _dark={{ color: isActive ? 'pecado.300' : 'gray.300' }}
                            fontWeight={isActive ? '500' : '400'}
                        >
                            {opt.label}
                        </Text>
                    </Flex>
                );
            })}
        </Flex>
    );
}
