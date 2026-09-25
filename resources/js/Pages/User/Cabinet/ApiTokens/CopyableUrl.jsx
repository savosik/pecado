import { useState } from 'react';
import { Box, HStack, Text, IconButton } from '@chakra-ui/react';
import { LuCopy, LuCheck, LuGlobe } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

/* ──────────────────────────────────────────────── */
/*  Компонент: строка адреса с кнопкой копирования  */
/* ──────────────────────────────────────────────── */
export default function CopyableUrl({ label, value, icon: Icon = LuGlobe, caption }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(value);
        setCopied(true);
        toaster.create({ title: 'Скопировано', type: 'success', duration: 1500 });
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Box>
            {label && (
                <Text fontSize="2xs" fontWeight="700" color="gray.400" textTransform="uppercase" letterSpacing="0.05em" mb="1">
                    {label}
                </Text>
            )}
            <HStack
                bg="bg.subtle"
                borderRadius="lg" px="3" py="2.5"
                border="1px solid" borderColor="border"
            >
                <Icon size={14} style={{ flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.300' }} flex="1" truncate fontFamily="mono">
                    {value}
                </Text>
                <IconButton
                    size="2xs" variant="ghost" colorPalette={copied ? 'green' : 'gray'}
                    onClick={handleCopy}
                    aria-label="Скопировать адрес"
                >
                    {copied ? <LuCheck /> : <LuCopy />}
                </IconButton>
            </HStack>
            {caption && (
                <Text fontSize="2xs" color="gray.400" mt="1">
                    {caption}
                </Text>
            )}
        </Box>
    );
}

/* ──────────────────────────────────────────────── */
/*  Компонент: карточка API-ключа                  */
/* ──────────────────────────────────────────────── */
