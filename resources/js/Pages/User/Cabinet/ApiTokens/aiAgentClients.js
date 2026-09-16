/**
 * Где скачать ИИ-агента и как подключить к нему MCP-сервер Pecado.
 *
 * Сервер принимает статический ключ в заголовке `Authorization: Bearer`, OAuth у него
 * нет. Поэтому здесь только клиенты, которые умеют передать такой заголовок, —
 * проверено по официальной документации вендоров 16.09.2026:
 *   Claude Code        https://code.claude.com/docs/en/mcp
 *   Claude Desktop     https://claude.com/docs/connectors/custom/remote-mcp (удалённые серверы
 *                      с заголовками — только через мост mcp-remote, нужен Node.js)
 *   ChatGPT / Codex    https://learn.chatgpt.com/docs/extend/mcp (config.toml, http_headers)
 *   Cursor             https://cursor.com/docs/mcp
 *   VS Code + Copilot  https://code.visualstudio.com/docs/copilot/reference/mcp-configuration
 *   Gemini CLI         https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md
 *   Qwen Code          https://github.com/QwenLM/qwen-code/blob/main/docs/users/features/mcp.md
 *   Kimi Code CLI      https://moonshotai.github.io/kimi-code/en/customization/mcp.html
 *   Yandex AI Studio   https://aistudio.yandex.ru/docs/ru/ai-studio/operations/mcp-servers/connect-external.html
 *
 * Мобильные приложения (Claude, ChatGPT, Gemini, GigaChat, Алиса, DeepSeek, Qwen, Kimi)
 * передать статический ключ не умеют — ссылок на них здесь нет намеренно: скачанное
 * приложение, которое не подключится, хуже, чем честное «подключите на компьютере».
 *
 * Установка — официальная страница загрузки (стабильнее прямой ссылки на версию)
 * либо официальная команда установщика.
 */

export const OS_LIST = [
    { key: 'windows', label: 'Windows' },
    { key: 'macos', label: 'macOS' },
    { key: 'linux', label: 'Linux' },
    { key: 'ios', label: 'iPhone и iPad' },
    { key: 'android', label: 'Android' },
];

export const MOBILE_OS = ['ios', 'android'];

const json = (value) => JSON.stringify(value, null, 2);

const claudeDesktopConfigPath = {
    windows: '%APPDATA%\\Claude\\claude_desktop_config.json',
    macos: '~/Library/Application Support/Claude/claude_desktop_config.json',
};

export const AGENT_CLIENTS = [
    {
        id: 'chatgpt',
        name: 'ChatGPT',
        icon: 'ChatGPT',
        region: 'США',
        kind: 'Приложение для компьютера',
        install: {
            windows: { url: 'https://chatgpt.com/download/' },
            macos: { url: 'https://chatgpt.com/download/' },
            linux: { url: 'https://learn.chatgpt.com/docs/linux/linux-app', note: 'предварительная версия' },
        },
        connect: ({ url, key }) => ({
            where: 'Файл ~/.codex/config.toml — его читают и ChatGPT на компьютере, и Codex CLI. Перезапустите приложение.',
            code: `[mcp_servers.pecado]\nurl = "${url}"\nhttp_headers = { "Authorization" = "Bearer ${key}" }`,
        }),
    },
    {
        id: 'claude-code',
        name: 'Claude Code',
        icon: 'Claude',
        region: 'США',
        kind: 'Агент в терминале · нужна подписка Claude Pro или выше',
        install: {
            windows: { command: 'irm https://claude.ai/install.ps1 | iex', shell: 'PowerShell' },
            macos: { command: 'curl -fsSL https://claude.ai/install.sh | bash', shell: 'Терминал' },
            linux: { command: 'curl -fsSL https://claude.ai/install.sh | bash', shell: 'Терминал' },
        },
        connect: ({ url, key }) => ({
            where: 'Выполните команду один раз — сервер появится во всех сессиях.',
            code: `claude mcp add --transport http pecado ${url} --header "Authorization: Bearer ${key}"`,
        }),
    },
    {
        id: 'claude-desktop',
        name: 'Claude',
        icon: 'Claude',
        region: 'США',
        kind: 'Приложение для компьютера · подключение через мост, нужен Node.js',
        install: {
            windows: { url: 'https://claude.com/download' },
            macos: { url: 'https://claude.com/download' },
        },
        connect: ({ url, key, os }) => ({
            where: `Файл ${claudeDesktopConfigPath[os] ?? claudeDesktopConfigPath.macos}. Нужен Node.js (nodejs.org). Перезапустите Claude.`,
            code: json({
                mcpServers: {
                    pecado: {
                        command: 'npx',
                        args: ['mcp-remote', url, '--header', 'Authorization:${AUTH_HEADER}'],
                        env: { AUTH_HEADER: `Bearer ${key}` },
                    },
                },
            }),
        }),
    },
    {
        id: 'cursor',
        name: 'Cursor',
        icon: 'Cursor',
        region: 'США',
        kind: 'Редактор с ИИ-агентом',
        install: {
            windows: { url: 'https://cursor.com/download' },
            macos: { url: 'https://cursor.com/download' },
            linux: { url: 'https://cursor.com/download' },
        },
        connect: ({ url, key }) => ({
            where: 'Файл ~/.cursor/mcp.json (для всех проектов) или .cursor/mcp.json в папке проекта.',
            code: json({ mcpServers: { pecado: { url, headers: { Authorization: `Bearer ${key}` } } } }),
        }),
    },
    {
        id: 'vscode-copilot',
        name: 'VS Code + Copilot',
        icon: 'Copilot',
        region: 'США',
        kind: 'Редактор с агентом GitHub Copilot',
        install: {
            windows: { url: 'https://code.visualstudio.com/download' },
            macos: { url: 'https://code.visualstudio.com/download' },
            linux: { url: 'https://code.visualstudio.com/download' },
        },
        connect: ({ url }) => ({
            where: 'Файл .vscode/mcp.json. Ключ VS Code спросит при первом подключении и сохранит в защищённом хранилище.',
            code: json({
                inputs: [{ type: 'promptString', id: 'pecado-key', description: 'Ключ Pecado', password: true }],
                servers: { pecado: { type: 'http', url, headers: { Authorization: 'Bearer ${input:pecado-key}' } } },
            }),
        }),
    },
    {
        id: 'gemini-cli',
        name: 'Gemini CLI',
        icon: 'Gemini',
        region: 'США',
        kind: 'Агент в терминале · нужен Node.js',
        install: {
            windows: { command: 'npm install -g @google/gemini-cli', shell: 'PowerShell' },
            macos: { command: 'npm install -g @google/gemini-cli', shell: 'Терминал' },
            linux: { command: 'npm install -g @google/gemini-cli', shell: 'Терминал' },
        },
        connect: ({ url, key }) => ({
            where: 'Выполните команду один раз.',
            code: `gemini mcp add --transport http --header "Authorization: Bearer ${key}" pecado ${url}`,
        }),
    },
    {
        id: 'qwen-code',
        name: 'Qwen Code',
        icon: 'Qwen',
        region: 'Китай',
        kind: 'Агент в терминале',
        install: {
            windows: { command: 'irm https://qwen-code-assets.oss-cn-hangzhou.aliyuncs.com/installation/install-qwen-standalone.ps1 | iex', shell: 'PowerShell' },
            macos: { command: 'curl -fsSL https://qwen-code-assets.oss-cn-hangzhou.aliyuncs.com/installation/install-qwen-standalone.sh | bash', shell: 'Терминал' },
            linux: { command: 'curl -fsSL https://qwen-code-assets.oss-cn-hangzhou.aliyuncs.com/installation/install-qwen-standalone.sh | bash', shell: 'Терминал' },
        },
        connect: ({ url, key }) => ({
            where: 'Выполните команду один раз.',
            code: `qwen mcp add --transport http pecado ${url} --header "Authorization: Bearer ${key}"`,
        }),
    },
    {
        id: 'kimi-code',
        name: 'Kimi Code',
        icon: 'Kimi',
        region: 'Китай',
        kind: 'Агент в терминале',
        install: {
            windows: { command: 'irm https://code.kimi.com/kimi-code/install.ps1 | iex', shell: 'PowerShell · нужен Git для Windows' },
            macos: { command: 'curl -fsSL https://code.kimi.com/kimi-code/install.sh | bash', shell: 'Терминал' },
            linux: { command: 'curl -fsSL https://code.kimi.com/kimi-code/install.sh | bash', shell: 'Терминал' },
        },
        connect: ({ url, key }) => ({
            where: 'Файл ~/.kimi-code/mcp.json.',
            code: json({ mcpServers: { pecado: { url, headers: { Authorization: `Bearer ${key}` } } } }),
        }),
    },
    {
        id: 'yandex-ai-studio',
        name: 'Yandex AI Studio',
        icon: 'Yandex AI Studio',
        region: 'Россия',
        kind: 'Конструктор агентов в браузере · для тех, кто собирает своего агента',
        install: {
            windows: { url: 'https://aistudio.yandex.ru', label: 'Открыть' },
            macos: { url: 'https://aistudio.yandex.ru', label: 'Открыть' },
            linux: { url: 'https://aistudio.yandex.ru', label: 'Открыть' },
        },
        connect: ({ url, key }) => ({
            where: 'Agent Atelier → MCP-серверы → «Внешний MCP-сервер».',
            code: `Адрес: ${url}\nТранспорт: Streamable HTTP\nАвторизация: «Токен доступа»\n  заголовок: Authorization\n  значение:  Bearer ${key}`,
        }),
    },
];

/**
 * ОС посетителя по браузеру. Ошибка не страшна — пользователь переключит вручную.
 */
export function detectOs() {
    if (typeof navigator === 'undefined') {
        return 'windows';
    }

    const ua = navigator.userAgent.toLowerCase();
    const platform = (navigator.userAgentData?.platform ?? navigator.platform ?? '').toLowerCase();

    // iPadOS представляется «Macintosh», но у него есть касание.
    if (/iphone|ipad|ipod/.test(ua) || (ua.includes('macintosh') && navigator.maxTouchPoints > 1)) {
        return 'ios';
    }

    if (ua.includes('android')) {
        return 'android';
    }

    if (platform.includes('win') || ua.includes('windows')) {
        return 'windows';
    }

    if (platform.includes('mac') || ua.includes('mac os')) {
        return 'macos';
    }

    if (platform.includes('linux') || ua.includes('linux')) {
        return 'linux';
    }

    return 'windows';
}
