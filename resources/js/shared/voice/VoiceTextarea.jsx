import { Box, Textarea } from '@chakra-ui/react';
import VoiceButton from '@/shared/voice/VoiceButton';

/**
 * Текстовое поле с голосовым вводом.
 *
 * Надиктованное дописывается в конец, а не заменяет текст: менеджер часто начинает
 * печатать, потом договаривает голосом.
 *
 * @param {string} value
 * @param {Function} onChange — получает новое значение строкой, а не событие
 */
export default function VoiceTextarea({ value = '', onChange, disabled = false, ...props }) {
    const append = (text) => {
        const base = (value || '').trimEnd();

        onChange(base ? `${base} ${text}` : text);
    };

    return (
        <Box position="relative" w="full">
            <Textarea
                value={value}
                onChange={(e) => onChange(e.target.value)}
                disabled={disabled}
                // Место под кнопку, чтобы она не легла на текст.
                pr={10}
                {...props}
            />
            <Box position="absolute" top="1" right="1">
                <VoiceButton onAppend={append} disabled={disabled} />
            </Box>
        </Box>
    );
}
