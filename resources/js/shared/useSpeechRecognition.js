/**
 * useSpeechRecognition — хук голосового ввода через Web Speech API.
 *
 * Возможности:
 *  - Определение поддержки SpeechRecognition / webkitSpeechRecognition
 *  - Конфигурация: continuous: false, interimResults: false, lang: 'ru-RU'
 *  - Throttle 300ms на toggleListening для защиты от быстрых кликов
 *  - Игнорирование безобидных ошибок (aborted, no-speech)
 *  - Callback onResult(transcript) при успешном распознавании
 *
 * Использование:
 *   const speech = useSpeechRecognition({ onResult: (text) => setQuery(text) });
 *   <button onClick={speech.toggleListening} disabled={!speech.isSupported}>
 *     {speech.isListening ? '🔴' : '🎤'}
 *   </button>
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const THROTTLE_MS = 300;
const ERROR_DISPLAY_MS = 3000;

// Безобидные ошибки, которые не нужно показывать пользователю
const IGNORED_ERRORS = new Set(['aborted', 'no-speech']);

/**
 * @param {object} options
 * @param {function} options.onResult — вызывается с распознанным текстом
 * @param {string}  [options.lang='ru-RU'] — язык распознавания
 */
export default function useSpeechRecognition({ onResult, lang = 'ru-RU' } = {}) {
    const [isListening, setIsListening] = useState(false);
    const [hasError, setHasError] = useState(false);

    const recognitionRef = useRef(null);
    const throttleRef = useRef(false);
    const errorTimerRef = useRef(null);
    const onResultRef = useRef(onResult);

    // Обновляем ref при изменении callback, чтобы избежать stale closure
    useEffect(() => {
        onResultRef.current = onResult;
    }, [onResult]);

    // Определение поддержки API (мемоизация, т.к. window не меняется)
    const isSupported = useMemo(() => {
        return typeof window !== 'undefined' &&
            !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    }, []);

    // Очистка таймера ошибки
    const clearErrorTimer = useCallback(() => {
        if (errorTimerRef.current) {
            clearTimeout(errorTimerRef.current);
            errorTimerRef.current = null;
        }
    }, []);

    // Показать ошибку на 3 секунды
    const showError = useCallback(() => {
        clearErrorTimer();
        setHasError(true);
        errorTimerRef.current = setTimeout(() => {
            setHasError(false);
            errorTimerRef.current = null;
        }, ERROR_DISPLAY_MS);
    }, [clearErrorTimer]);

    // Начать запись
    const startListening = useCallback(() => {
        if (!isSupported) return;

        // Остановить предыдущий экземпляр
        if (recognitionRef.current) {
            try { recognitionRef.current.abort(); } catch { /* ignore */ }
        }

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recognition = new SpeechRecognition();

        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.lang = lang;

        recognition.onstart = () => {
            setIsListening(true);
            setHasError(false);
            clearErrorTimer();
        };

        recognition.onresult = (event) => {
            const transcript = event.results[0]?.[0]?.transcript?.trim();
            if (transcript && onResultRef.current) {
                onResultRef.current(transcript);
            }
        };

        recognition.onerror = (event) => {
            if (!IGNORED_ERRORS.has(event.error)) {
                showError();
            }
        };

        recognition.onend = () => {
            setIsListening(false);
            recognitionRef.current = null;
        };

        recognitionRef.current = recognition;

        try {
            recognition.start();
        } catch {
            showError();
            setIsListening(false);
        }
    }, [isSupported, lang, clearErrorTimer, showError]);

    // Остановить запись
    const stopListening = useCallback(() => {
        if (recognitionRef.current) {
            try { recognitionRef.current.stop(); } catch { /* ignore */ }
        }
    }, []);

    // Переключить с throttle 300ms
    const toggleListening = useCallback(() => {
        if (throttleRef.current) return;
        throttleRef.current = true;
        setTimeout(() => { throttleRef.current = false; }, THROTTLE_MS);

        if (isListening) {
            stopListening();
        } else {
            startListening();
        }
    }, [isListening, startListening, stopListening]);

    // Cleanup при размонтировании
    useEffect(() => {
        return () => {
            if (recognitionRef.current) {
                try { recognitionRef.current.abort(); } catch { /* ignore */ }
                recognitionRef.current = null;
            }
            clearErrorTimer();
        };
    }, [clearErrorTimer]);

    return {
        isListening,
        isSupported,
        hasError,
        toggleListening,
        startListening,
        stopListening,
    };
}
