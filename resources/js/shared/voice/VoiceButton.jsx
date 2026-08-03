import { Box } from '@chakra-ui/react';
import { LuMic, LuMicOff } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { useSpeechRecognition } from '@/shared/voice/useSpeechRecognition';

/**
 * Кнопка голосового ввода: надиктованный текст дописывается в конец поля.
 *
 * В браузерах без Web Speech API (Firefox) не рисуется вовсе — мёртвая кнопка,
 * которая ничего не делает, хуже её отсутствия.
 *
 * @param {Function} onAppend — получает распознанный фрагмент
 * @param {string} title — подсказка на кнопке
 */
export default function VoiceButton({ onAppend, disabled = false, size = 'xs', title = 'Надиктовать' }) {
    const { supported, listening, error, toggle } = useSpeechRecognition(onAppend);

    if (!supported) {
        return null;
    }

    return (
        <Button
            type="button"
            size={size}
            variant={listening ? 'solid' : 'ghost'}
            colorPalette={listening ? 'red' : undefined}
            disabled={disabled}
            onClick={toggle}
            title={error || (listening ? 'Остановить запись' : title)}
            aria-label={listening ? 'Остановить запись' : title}
        >
            {listening ? <LuMicOff /> : <LuMic />}
            {/* Красная кнопка с подписью — признак записи внутри страницы:
                индикатор микрофона в браузере виден не во всех окнах. */}
            {listening && <Box as="span" ml={1} fontSize="xs">слушаю…</Box>}
        </Button>
    );
}
