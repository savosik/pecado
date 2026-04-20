import React, { useState, useRef, useEffect } from 'react';
import { IconButton } from '@chakra-ui/react';
import { LuMic, LuMicOff } from 'react-icons/lu';

export default function VoiceMicButton({ onText, lang = 'ru-RU', size = 'sm' }) {
    const [isListening, setIsListening] = useState(false);
    const [isSupported, setIsSupported] = useState(false);
    const recognitionRef = useRef(null);

    useEffect(() => {
        setIsSupported(!!(window.SpeechRecognition || window.webkitSpeechRecognition));
        return () => recognitionRef.current?.abort();
    }, []);

    const start = () => {
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return;

        const recognition = new SR();
        recognition.lang = lang;
        recognition.continuous = true;
        recognition.interimResults = false;

        recognition.onresult = (e) => {
            const transcript = Array.from(e.results)
                .filter(r => r.isFinal)
                .map(r => r[0].transcript)
                .join(' ')
                .trim();
            if (transcript) onText(transcript);
        };

        recognition.onend = () => setIsListening(false);
        recognition.onerror = () => setIsListening(false);

        recognitionRef.current = recognition;
        recognition.start();
        setIsListening(true);
    };

    const stop = () => {
        recognitionRef.current?.stop();
        setIsListening(false);
    };

    if (!isSupported) return null;

    return (
        <IconButton
            size={size}
            variant={isListening ? 'solid' : 'ghost'}
            colorPalette={isListening ? 'red' : 'gray'}
            onClick={isListening ? stop : start}
            aria-label={isListening ? 'Остановить запись' : 'Голосовой ввод'}
            title={isListening ? 'Остановить запись' : 'Голосовой ввод'}
            flexShrink={0}
        >
            {isListening ? <LuMicOff /> : <LuMic />}
        </IconButton>
    );
}
