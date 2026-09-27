/**
 * Zustand-стор помощника: открыт ли диалог, тред, ходы, опрос сервера,
 * подтверждения, вложения, реплики иконки.
 *
 * Живёт поверх переходов Inertia: иконка и диалог не перерисовываются с нуля
 * на каждой странице. Всё общение с сервером — здесь, компоненты только
 * рендерят состояние и зовут действия.
 */

import { create } from 'zustand';

const BUBBLE_KEY = 'assistant:bubbles';
const INTRO_KEY = 'assistant:intro-seen';

const readJson = (key, fallback) => {
    try {
        const raw = window.localStorage.getItem(key);
        return raw ? JSON.parse(raw) : fallback;
    } catch {
        return fallback;
    }
};

const writeJson = (key, value) => {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // приватный режим — реплики просто не запоминаются
    }
};

const mergeMessages = (current, incoming) => {
    const byId = new Map(current.map((m) => [m.id, m]));
    incoming.forEach((m) => byId.set(m.id, m));
    return Array.from(byId.values()).sort((a, b) => a.id - b.id);
};

const errorMessage = (error, fallback) =>
    error?.response?.data?.message || error?.response?.data?.errors?.file?.[0] || fallback;

export const useAssistantStore = create((set, get) => ({
    /** Id пользователя, чей тред в сторе: смена аккаунта — сброс. */
    ownerId: null,

    open: false,
    thread: null,
    messages: [],
    confirmations: [],
    busy: false,
    quota: null,
    loading: false,
    error: null,

    /** Контекст текущей страницы (см. pageContext.js). */
    page: null,

    /** Вложения, загруженные, но ещё не отправленные. */
    pendingAttachments: [],
    uploading: false,

    /** Реплика иконки сейчас на экране. */
    bubble: null,

    pollTimer: null,

    setOwner(ownerId) {
        if (get().ownerId !== ownerId) {
            get().stopPolling();
            set({ ownerId, thread: null, messages: [], confirmations: [], busy: false, quota: null, pendingAttachments: [], bubble: null, open: false });
        }
    },

    setPage(page) {
        set({ page });
    },

    /**
     * Тред нужен только когда клиент что-то пишет или прикладывает: заводим
     * его лениво, чтобы простое открытие страницы не оставляло пустых тредов.
     */
    async ensureThread() {
        if (get().thread) return get().thread;

        const { data } = await window.axios.post('/cabinet/assistant/threads', { page: get().page });
        get().applyState(data, true);
        get().startPolling();

        return get().thread;
    },

    async openDialog({ fresh = false, prefill = null } = {}) {
        set({ open: true, error: null, prefill });

        if (get().thread && !fresh) {
            get().startPolling();
            return;
        }

        set({ loading: true });

        try {
            const { data } = await window.axios.post('/cabinet/assistant/threads', { page: get().page, fresh });
            get().applyState(data, true);
            get().startPolling();
        } catch (error) {
            set({ error: errorMessage(error, 'Не удалось открыть помощника.') });
        } finally {
            set({ loading: false });
        }
    },

    closeDialog() {
        set({ open: false });
        if (!get().busy) {
            get().stopPolling();
        }
    },

    async switchThread(threadId) {
        set({ loading: true, error: null });

        try {
            const { data } = await window.axios.get(`/cabinet/assistant/threads/${threadId}`);
            get().applyState(data, true);
            get().startPolling();
        } catch (error) {
            set({ error: errorMessage(error, 'Не удалось открыть разговор.') });
        } finally {
            set({ loading: false });
        }
    },

    applyState(data, replace = false) {
        const messages = replace ? (data.messages || []) : mergeMessages(get().messages, data.messages || []);

        set({
            thread: data.thread || get().thread,
            messages,
            confirmations: data.confirmations || [],
            busy: Boolean(data.busy),
            quota: data.quota || null,
        });
    },

    lastId() {
        const done = get().messages.filter((m) => m.status === 'done' || m.status === 'failed');
        return done.length ? done[done.length - 1].id : 0;
    },

    async poll() {
        const thread = get().thread;
        if (!thread) return;

        try {
            const { data } = await window.axios.get(`/cabinet/assistant/threads/${thread.id}`, { params: { after: get().lastId() } });
            get().applyState(data);
        } catch (error) {
            if (error?.response?.status === 404) {
                get().stopPolling();
                set({ thread: null, messages: [], open: false });
            }
        }
    },

    startPolling() {
        if (get().pollTimer) return;
        const timer = window.setInterval(() => {
            const { busy, open } = get();
            if (busy || open) get().poll();
        }, 1000);
        set({ pollTimer: timer });
    },

    stopPolling() {
        const timer = get().pollTimer;
        if (timer) {
            window.clearInterval(timer);
            set({ pollTimer: null });
        }
    },

    async send(text) {
        set({ error: null });

        try {
            const thread = await get().ensureThread();
            const { pendingAttachments, page } = get();
            if (!thread) return false;

            const { data } = await window.axios.post(`/cabinet/assistant/threads/${thread.id}/messages`, {
                text,
                attachments: pendingAttachments.map((a) => a.id),
                page,
                after: get().lastId(),
            });
            get().applyState(data);
            set({ pendingAttachments: [] });
            get().startPolling();
            return true;
        } catch (error) {
            if (error?.response?.status === 409) {
                set({ error: error.response.data?.message || 'Подождите.' });
                if (error.response.data?.refused === 'busy') get().poll();
                return false;
            }
            set({ error: errorMessage(error, 'Не удалось отправить сообщение.') });
            return false;
        }
    },

    async decide(confirmationId, approve) {
        set({ error: null });

        try {
            const { data } = await window.axios.post(`/cabinet/assistant/confirmations/${confirmationId}`, { approve, after: get().lastId() });
            get().applyState(data);
            get().startPolling();
        } catch (error) {
            set({ error: errorMessage(error, 'Не удалось передать ответ.') });
        }
    },

    async upload(file) {
        set({ uploading: true, error: null });
        let thread;

        try {
            thread = await get().ensureThread();
        } catch (error) {
            set({ uploading: false, error: errorMessage(error, 'Не удалось открыть помощника.') });
            return;
        }

        if (!thread) {
            set({ uploading: false });
            return;
        }

        const form = new FormData();
        form.append('file', file);

        try {
            const { data } = await window.axios.post(`/cabinet/assistant/threads/${thread.id}/attachments`, form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            set({ pendingAttachments: [...get().pendingAttachments, data.attachment] });
        } catch (error) {
            set({ error: errorMessage(error, 'Не удалось загрузить файл.') });
        } finally {
            set({ uploading: false });
        }
    },

    async removeAttachment(id) {
        set({ pendingAttachments: get().pendingAttachments.filter((a) => a.id !== id) });
        try {
            await window.axios.delete(`/cabinet/assistant/attachments/${id}`);
        } catch {
            // уже отвязан или удалён — не важно
        }
    },

    async newThread() {
        get().stopPolling();
        set({ thread: null, messages: [], confirmations: [], busy: false, quota: null, pendingAttachments: [] });
        await get().openDialog({ fresh: true });
    },

    async closeThread() {
        const thread = get().thread;
        if (!thread) return;
        try {
            await window.axios.post(`/cabinet/assistant/threads/${thread.id}/close`);
        } catch {
            // ничего: следующий opendialog заведёт новый
        }
        get().stopPolling();
        set({ thread: null, messages: [], confirmations: [], busy: false, quota: null });
    },

    event(event, extra = {}) {
        const thread = get().thread;
        window.axios.post('/cabinet/assistant/events', { event, thread_id: thread?.id ?? null, ...extra }).catch(() => {});
    },

    /* ---------- реплики иконки ---------- */

    bubbleState() {
        return readJson(BUBBLE_KEY, { views: 0, lastAt: 0, sessionCount: 0, dismissals: 0, snoozedUntil: 0, shown: [] });
    },

    /**
     * Переход на страницу: решаем, спрашивать ли сервер о реплике, и показываем её.
     * Правила частоты — из shared prop `assistant.bubbles`.
     */
    async onNavigate(page, rules) {
        set({ page });

        if (!rules?.enabled || get().open) return;

        const state = get().bubbleState();
        const now = Date.now();
        state.views += 1;

        if (state.snoozedUntil > now) {
            writeJson(BUBBLE_KEY, state);
            return;
        }

        const intro = !readJson(INTRO_KEY, false);
        const dueByViews = state.views >= (rules.min_page_views_between || 3);
        const dueByTime = now - state.lastAt >= (rules.min_seconds_between || 90) * 1000;
        const underCap = state.sessionCount < (rules.max_per_session || 6);

        if (!intro && !(dueByViews && dueByTime && underCap)) {
            writeJson(BUBBLE_KEY, state);
            return;
        }

        try {
            const { data } = await window.axios.get('/cabinet/assistant/bubble', { params: { ...page, intro: intro ? 1 : 0, shown: state.shown.slice(-12).join(',') } });

            if (!data?.bubble) {
                writeJson(BUBBLE_KEY, state);
                return;
            }

            state.views = 0;
            state.lastAt = now;
            state.sessionCount += 1;
            state.shown = [...state.shown.slice(-19), data.bubble.key];
            writeJson(BUBBLE_KEY, state);

            if (data.bubble.key === 'intro') writeJson(INTRO_KEY, true);

            set({ bubble: { ...data.bubble, shownAt: now } });
            get().event('bubble_shown', { page: page?.type, prompt_key: data.bubble.key });

            window.setTimeout(() => {
                const current = get().bubble;
                if (current && current.shownAt === now) set({ bubble: null });
            }, (rules.show_seconds || 8) * 1000);
        } catch {
            writeJson(BUBBLE_KEY, state);
        }
    },

    dismissBubble() {
        const bubble = get().bubble;
        if (!bubble) return;

        const state = get().bubbleState();
        state.dismissals += 1;

        const rules = get().bubbleRules || {};
        if (state.dismissals >= (rules.snooze_after_dismissals || 3)) {
            state.dismissals = 0;
            state.snoozedUntil = Date.now() + (rules.snooze_hours || 24) * 3600 * 1000;
        }

        writeJson(BUBBLE_KEY, state);
        get().event('bubble_dismissed', { page: get().page?.type, prompt_key: bubble.key });
        set({ bubble: null });
    },

    async clickBubble() {
        const bubble = get().bubble;
        if (!bubble) return;

        const state = get().bubbleState();
        state.dismissals = 0;
        writeJson(BUBBLE_KEY, state);

        get().event('bubble_clicked', { page: get().page?.type, prompt_key: bubble.key });
        set({ bubble: null });
        await get().openDialog({ prefill: bubble.question || bubble.text });
    },

    setBubbleRules(rules) {
        set({ bubbleRules: rules });
    },
}));
