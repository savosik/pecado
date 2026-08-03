import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import tsParser from '@typescript-eslint/parser';

/**
 * Линтер фронтенда — намеренно узкий.
 *
 * Задача конфига одна: ловить то, что ломает страницу в браузере, но не ломает
 * сборку. Vite молча собирает `<TaskCloseDialog />` без импорта — необъявленный
 * идентификатор для него ошибка времени выполнения, а не сборки, и раздел падает
 * уже у пользователя. Ровно так /crm и слёг после волны crm-14.
 *
 * Стилевые правила не включены сознательно: код писался годами без линтера,
 * и правило про кавычки, утонувшее в тысяче замечаний, не поймает ни одной
 * настоящей поломки. Форматирование — дело .editorconfig и ревью.
 */

/**
 * Правила, выключенные из `js.configs.recommended`.
 *
 * Все они про качество, а не про поломку: включать их на легаси-кодовой базе
 * значит утопить настоящие ошибки в сотнях замечаний, и линтер перестанут читать.
 */
const noiseRules = {
    'no-unused-vars': 'off',
    'no-empty': 'off',
    'no-prototype-builtins': 'off',
    'no-useless-escape': 'off',
    'no-control-regex': 'off',
    'no-constant-condition': 'off',
    'no-cond-assign': 'off',
    'no-fallthrough': 'off',
    'no-case-declarations': 'off',
    'no-async-promise-executor': 'off',
    'no-misleading-character-class': 'off',
    'no-sparse-arrays': 'off',
    'no-irregular-whitespace': 'off',
    'no-redeclare': 'off',
    'no-import-assign': 'off',
};

const languageOptions = {
    // Парсер TypeScript понимает и обычный JS с JSX, поэтому он один на все
    // четыре расширения — иначе .ts-файлы (menuConfig) выпали бы из проверки,
    // а иконку в меню там объявляют так же, как в JSX.
    parser: tsParser,
    ecmaVersion: 'latest',
    sourceType: 'module',
    parserOptions: {
        ecmaFeatures: { jsx: true },
    },
    globals: {
        ...globals.browser,
        ...globals.es2021,
        // Ziggy подмешивает route() в window через директиву @routes,
        // импорта у него нет.
        route: 'readonly',
        Ziggy: 'readonly',
    },
};

export default [
    {
        ignores: [
            'public/**',
            'node_modules/**',
            'vendor/**',
            'docs-erp/**',
            'docs-testing/**',
            'docs/**',
            'storage/**',
            'bootstrap/**',
        ],
    },

    {
        files: ['resources/js/**/*.{js,jsx,ts,tsx}'],
        languageOptions,

        // В коде живут `eslint-disable` для правил, которые мы намеренно не
        // включаем. Ругаться на них как на лишние — чистая суета: комментарий
        // объясняет решение автора и должен пережить настройки линтера.
        linterOptions: { reportUnusedDisableDirectives: 'off' },

        plugins: { react, 'react-hooks': reactHooks },
        settings: { react: { version: 'detect' } },

        rules: {
            ...js.configs.recommended.rules,
            ...noiseRules,

            // Ядро проверки: компонент в JSX, которого нет ни в импортах,
            // ни в файле. Именно этот класс ошибок доходит до продакшена.
            'react/jsx-no-undef': 'error',
            'no-undef': 'error',
            // Опечатка в имени пропса рушит рендер так же надёжно.
            'react/jsx-no-duplicate-props': 'error',
            // Хук под условием — гарантированное падение React в рантайме.
            'react-hooks/rules-of-hooks': 'error',
            // А вот полнота зависимостей — вопрос вкуса и причина живых
            // `eslint-disable` в коде: плагин подключён ради имени правила,
            // чтобы эти комментарии резолвились, а не ради самого правила.
            'react-hooks/exhaustive-deps': 'off',
        },
    },

    {
        // В TypeScript типы стираются при компиляции, и `no-undef` принимает
        // их за необъявленные переменные (`React.ElementType` без импорта).
        // Проверку неопределённых имён для .ts берёт на себя сам tsc.
        files: ['resources/js/**/*.{ts,tsx}'],
        rules: {
            'no-undef': 'off',
        },
    },
];
