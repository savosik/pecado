import { useEffect } from 'react';
import { Box, Flex, IconButton, Text } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';
import BarcodeCameraView from './BarcodeCameraView';

/**
 * BarcodeSearchScanner — полноэкранный модал со сканером штрихкода для поиска в шапке.
 *
 * Открывается поверх мобильного оверлея поиска, при успешном распознавании
 * вызывает onScan(text). Закрывается крестиком или Escape.
 *
 * @param {object} props
 * @param {boolean} props.open
 * @param {(text: string) => void} props.onScan
 * @param {() => void} props.onClose
 */
export default function BarcodeSearchScanner({ open, onScan, onClose }) {
    useEffect(() => {
        if (!open) return undefined;
        const handleKey = (e) => {
            if (e.key === 'Escape') onClose?.();
        };
        document.addEventListener('keydown', handleKey);
        return () => document.removeEventListener('keydown', handleKey);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <Box
            position="fixed"
            top="0"
            left="0"
            right="0"
            bottom="0"
            bg="black"
            zIndex="1500"
            display="flex"
            flexDirection="column"
        >
            <Flex
                align="center"
                justify="space-between"
                px="3"
                py="2"
                bg="blackAlpha.800"
                color="white"
            >
                <Text fontWeight="medium">Поиск по штрихкоду</Text>
                <IconButton
                    aria-label="Закрыть сканер"
                    size="sm"
                    variant="ghost"
                    color="white"
                    _hover={{ bg: 'whiteAlpha.200' }}
                    onClick={onClose}
                >
                    <LuX size={22} />
                </IconButton>
            </Flex>

            <Box flex="1" minH="0">
                <BarcodeCameraView onScan={onScan} height="100%" />
            </Box>

            <Box bg="blackAlpha.800" px="4" py="3">
                <Text color="white" fontSize="sm" textAlign="center">
                    Наведите камеру на штрихкод товара
                </Text>
            </Box>
        </Box>
    );
}
