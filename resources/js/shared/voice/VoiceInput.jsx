import { Box, Input } from '@chakra-ui/react';
import VoiceButton from '@/shared/voice/VoiceButton';

/**
 * Однострочное поле с голосовым вводом — брат-близнец `VoiceTextarea`.
 *
 * Отдельный компонент, а не `VoiceTextarea rows={1}`: у заголовков задач нет
 * переносов строк, а textarea тянется и ловит Enter иначе, чем инпут.
 *
 * Надиктованное дописывается в конец, а не заменяет текст: заголовок часто
 * начинают печатать, а договаривают голосом.
 *
 * @param {string} value
 * @param {Function} onChange — получает новое значение строкой, а не событие
 */
export default function VoiceInput({ value = '', onChange, disabled = false, title, ...props }) {
    const append = (text) => {
        const base = (value || '').trimEnd();

        onChange(base ? `${base} ${text}` : text);
    };

    return (
        <Box position="relative" w="full">
            <Input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                disabled={disabled}
                // Место под кнопку, чтобы она не легла на текст.
                pr={10}
                {...props}
            />
            <Box position="absolute" top="50%" right="1" transform="translateY(-50%)">
                <VoiceButton onAppend={append} disabled={disabled} title={title} />
            </Box>
        </Box>
    );
}
