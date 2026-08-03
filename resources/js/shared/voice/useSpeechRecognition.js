import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Голосовой ввод через встроенное в браузер распознавание речи (Web Speech API).
 *
 * Своего распознавания не поднимаем и на сторонний сервис не ходим: браузерное
 * работает без ключей, без трафика через наш сервер и без платы за минуту.
 * Цена — поддержка только в Chromium (Chrome, Edge, Яндекс.Браузер) и Safari;
 * в Firefox API нет, и кнопка микрофона там просто не рисуется.
 *
 * @param {Function} onText — вызывается с распознанным фрагментом (только финальным)
 * @param {string} lang — язык распознавания
 */
export function useSpeechRecognition(onText, lang = 'ru-RU') {
    const [listening, setListening] = useState(false);
    const [error, setError] = useState(null);
    const recognitionRef = useRef(null);
    // Колбэк держим в ref: пересоздавать распознаватель на каждый ререндер
    // родителя означало бы обрывать запись на первом же нажатии клавиши.
    const onTextRef = useRef(onText);
    onTextRef.current = onText;

    const supported = typeof window !== 'undefined'
        && !!(window.SpeechRecognition || window.webkitSpeechRecognition);

    const stop = useCallback(() => {
        recognitionRef.current?.stop();
        setListening(false);
    }, []);

    const start = useCallback(() => {
        if (!supported || recognitionRef.current) {
            return;
        }

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recognition = new SpeechRecognition();

        recognition.lang = lang;
        // Непрерывный режим: менеджер диктует несколько фраз подряд, а не одну.
        recognition.continuous = true;
        recognition.interimResults = false;

        recognition.onresult = (event) => {
            for (let i = event.resultIndex; i < event.results.length; i += 1) {
                const result = event.results[i];

                if (result.isFinal) {
                    const text = result[0]?.transcript?.trim();

                    if (text) {
                        onTextRef.current?.(text);
                    }
                }
            }
        };

        recognition.onerror = (event) => {
            // no-speech и aborted — обычное течение дел (пауза, ручная остановка),
            // ошибкой их показывать нечего.
            if (!['no-speech', 'aborted'].includes(event.error)) {
                setError(event.error === 'not-allowed'
                    ? 'Браузер не дал доступ к микрофону'
                    : 'Распознавание недоступно');
            }
            setListening(false);
        };

        recognition.onend = () => {
            recognitionRef.current = null;
            setListening(false);
        };

        setError(null);
        recognition.start();
        recognitionRef.current = recognition;
        setListening(true);
    }, [supported, lang]);

    const toggle = useCallback(() => (listening ? stop() : start()), [listening, start, stop]);

    // Уходя со страницы, микрофон отпускаем: иначе индикатор записи останется висеть.
    useEffect(() => () => recognitionRef.current?.abort(), []);

    return { supported, listening, error, start, stop, toggle };
}
