import { createSystem, defaultConfig, defineConfig } from '@chakra-ui/react';

const config = defineConfig({
    theme: {
        tokens: {
            fonts: {
                heading: { value: '"Jost", sans-serif' },
                body: { value: '"Jost", sans-serif' },
            },
            colors: {
                pecado: {
                    50: { value: '#fce8ec' },
                    100: { value: '#f5c0c8' },
                    200: { value: '#e8899a' },
                    300: { value: '#e25858' },
                    400: { value: '#d4364a' },
                    500: { value: '#c22538' },
                    600: { value: '#9e1b32' },
                    700: { value: '#7a1527' },
                    800: { value: '#570f1c' },
                    900: { value: '#340912' },
                    950: { value: '#1a0508' },
                },
            },
        },
        semanticTokens: {
            colors: {
                'pecado.solid': { value: '{colors.pecado.600}' },
                'pecado.contrast': { value: 'white' },
                'pecado.fg': { value: '{colors.pecado.600}' },
                'pecado.muted': { value: '{colors.pecado.100}' },
                'pecado.subtle': { value: '{colors.pecado.50}' },
                'pecado.emphasized': { value: '{colors.pecado.200}' },
                'pecado.focusRing': { value: '{colors.pecado.600}' },
            },
        },
    },
});

export const system = createSystem(defaultConfig, config);
