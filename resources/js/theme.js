import { createSystem, defaultConfig, defineConfig } from '@chakra-ui/react';

const config = defineConfig({
    theme: {
        tokens: {
            fonts: {
                heading: { value: '"Jost", sans-serif' },
                body: { value: '"Jost", sans-serif' },
            },
            colors: {
                // Основной бренд-цвет (Бордовый / Pecado)
                pecado: {
                    50: { value: '#fcf3f4' },
                    100: { value: '#f5dadd' },
                    200: { value: '#ebb5bc' },
                    300: { value: '#de8694' },
                    400: { value: '#cd5466' },
                    500: { value: '#b62f43' },
                    600: { value: '#9e1b32' }, // Основной брендовый
                    700: { value: '#851326' },
                    800: { value: '#6d1222' },
                    900: { value: '#5e1420' },
                    950: { value: '#32060e' },
                },
                // Акцентный цвет (Шампань / Золотой) - для бейджей "Новинка", "Премиум"
                champagne: {
                    50: { value: '#fdfbfa' },
                    100: { value: '#f8f1e9' },
                    200: { value: '#efe0cd' },
                    300: { value: '#e2caae' },
                    400: { value: '#d2af8a' },
                    500: { value: '#c29769' },
                    600: { value: '#b38153' },
                    700: { value: '#956643' },
                    800: { value: '#79533a' },
                    900: { value: '#634432' },
                    950: { value: '#352217' },
                },
                // Теплый серый для интерфейса (вместо стандартного холодного gray)
                sand: {
                    50: { value: '#faf9f8' },
                    100: { value: '#f4f2f0' },
                    200: { value: '#e8e5e1' },
                    300: { value: '#d6d1cb' },
                    400: { value: '#bcaba3' },
                    500: { value: '#a49890' },
                    600: { value: '#8a7e76' },
                    700: { value: '#726760' },
                    800: { value: '#5d544f' },
                    900: { value: '#4c4642' },
                    950: { value: '#292523' },
                }
            },
            radii: {
                // Немного скругляем углы для современного вида
                none: { value: '0' },
                sm: { value: '0.25rem' },
                md: { value: '0.5rem' },
                lg: { value: '0.75rem' },
                xl: { value: '1rem' },
                '2xl': { value: '1.5rem' },
                full: { value: '9999px' },
            }
        },
        semanticTokens: {
            colors: {
                // Семантика для основного цвета Pecado.
                // В тёмной теме светлые оттенки 50/100/200 дают почти белый
                // фон → нужны тёмные варианты, чтобы текст и решётки были видны.
                'pecado.solid': {
                    value: { _light: '{colors.pecado.600}', _dark: '{colors.pecado.500}' },
                },
                'pecado.contrast': { value: 'white' },
                'pecado.fg': {
                    value: { _light: '{colors.pecado.600}', _dark: '{colors.pecado.300}' },
                },
                'pecado.muted': {
                    value: { _light: '{colors.pecado.100}', _dark: '{colors.pecado.900}' },
                },
                'pecado.subtle': {
                    value: { _light: '{colors.pecado.50}', _dark: '{colors.pecado.950}' },
                },
                'pecado.emphasized': {
                    value: { _light: '{colors.pecado.200}', _dark: '{colors.pecado.800}' },
                },
                'pecado.focusRing': { value: '{colors.pecado.600}' },

                // Насыщенные фоны (Premium Dark Mode)
                bg: {
                    DEFAULT: { value: { _light: '{colors.sand.50}', _dark: '#130c0e' } }, // Глубокий тёмный фон для dark mode
                    subtle: { value: { _light: '{colors.sand.100}', _dark: '#1a1013' } },
                    muted: { value: { _light: '{colors.sand.200}', _dark: '#24161a' } },
                },

                fg: {
                    DEFAULT: { value: { _light: '{colors.sand.900}', _dark: '{colors.sand.50}' } },
                    muted: { value: { _light: '{colors.sand.600}', _dark: '{colors.sand.400}' } },
                    subtle: { value: { _light: '{colors.sand.500}', _dark: '{colors.sand.500}' } },
                },

                border: {
                    DEFAULT: { value: { _light: '{colors.sand.200}', _dark: '#301c21' } },
                    muted: { value: { _light: '{colors.sand.100}', _dark: '#24161a' } },
                }
            },
            shadows: {
                // Мягкие "стеклянные" тени (Glassmorphism/Modern)
                sm: { value: { _light: '0 1px 2px rgba(158, 27, 50, 0.04)', _dark: '0 1px 2px rgba(0, 0, 0, 0.4)' } },
                md: { value: { _light: '0 4px 12px rgba(158, 27, 50, 0.06)', _dark: '0 4px 12px rgba(0, 0, 0, 0.6)' } },
                lg: { value: { _light: '0 10px 24px rgba(158, 27, 50, 0.08)', _dark: '0 10px 24px rgba(0, 0, 0, 0.8)' } },
            }
        },
    },
});

export const system = createSystem(defaultConfig, config);
