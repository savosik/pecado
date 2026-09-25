import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Голосовой ввод через SpeechRecognition браузера (ru-RU).
 *
 * Распознанный текст — это текст в поле, а не отправка: клиент видит, что
 * услышано, правит артикулы и числа и жмёт «Отправить» сам. Где API нет
 * (Firefox) — `supported` false, кнопки микрофона просто нет.
 *
 * @param {(text: string, final: boolean) => void} onText
 */
export function useSpeechInput(onText) {
    const Recognition = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition) : null;
    const supported = Boolean(Recognition);
    const [listening, setListening] = useState(false);
    const recognitionRef = useRef(null);
    const onTextRef = useRef(onText);
    onTextRef.current = onText;

    const stop = useCallback(() => {
        try {
            recognitionRef.current?.stop();
        } catch {
            // уже остановлен
        }
        setListening(false);
    }, []);

    const start = useCallback(() => {
        if (!Recognition) return;

        const recognition = new Recognition();
        recognition.lang = 'ru-RU';
        recognition.interimResults = true;
        recognition.continuous = false;
        recognition.maxAlternatives = 1;

        recognition.onresult = (event) => {
            let interim = '';
            let finalText = '';

            for (let i = event.resultIndex; i < event.results.length; i += 1) {
                const chunk = event.results[i][0].transcript;
                if (event.results[i].isFinal) finalText += chunk;
                else interim += chunk;
            }

            if (finalText) onTextRef.current(finalText, true);
            else if (interim) onTextRef.current(interim, false);
        };

        recognition.onerror = () => setListening(false);
        recognition.onend = () => setListening(false);

        recognitionRef.current = recognition;

        try {
            recognition.start();
            setListening(true);
        } catch {
            setListening(false);
        }
    }, [Recognition]);

    const toggle = useCallback(() => {
        if (listening) stop();
        else start();
    }, [listening, start, stop]);

    useEffect(() => () => stop(), [stop]);

    return { supported, listening, toggle, stop };
}
