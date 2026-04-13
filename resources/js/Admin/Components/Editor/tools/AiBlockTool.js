/**
 * AiBlockTool — ИИ-генератор контентных блоков.
 *
 * Пользователь вводит промт, выбирает тип блока из выпадающего списка,
 * нажимает «Сгенерировать» → бэкенд обращается к OpenRouter (Gemini 2.5 Flash),
 * возвращает JSON-данные блока → инструмент вставляет готовый блок
 * вместо себя в редактор.
 */
export default class AiBlockTool {
    static get toolbox() {
        return {
            title: '✨ AI Блок',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg>',
        };
    }

    static get BLOCK_TYPES() {
        return [
            { value: 'paragraph', label: 'Абзац текста', icon: '📝' },
            { value: 'header', label: 'Заголовок', icon: '🔤' },
            { value: 'list', label: 'Список', icon: '📋' },
            { value: 'faq', label: 'FAQ (вопрос-ответ)', icon: '❓' },
            { value: 'table', label: 'Таблица', icon: '📊' },
            { value: 'steps', label: 'Шаги / Инструкция', icon: '🪜' },
            { value: 'tabs', label: 'Вкладки (табы)', icon: '📑' },
            { value: 'iconFeature', label: 'Иконки-фичи', icon: '⭐' },
            { value: 'stats', label: 'Статистика (цифры)', icon: '📈' },
            { value: 'timeline', label: 'Хронология', icon: '📅' },
            { value: 'comparison', label: 'Сравнение', icon: '⚖️' },
            { value: 'reviews', label: 'Отзывы', icon: '⭐' },
            { value: 'callToAction', label: 'CTA-блок', icon: '🎯' },
            { value: 'alertBanner', label: 'Баннер-уведомление', icon: '📢' },
            { value: 'pullQuote', label: 'Крупная цитата', icon: '❝' },
            { value: 'opinionBox', label: 'Мнение эксперта', icon: '💬' },
            { value: 'pricingTable', label: 'Тарифы', icon: '💰' },
            { value: 'imageText', label: 'Фото + текст', icon: '🖼️' },
            { value: 'warning', label: 'Предупреждение', icon: '⚠️' },
            { value: 'quote', label: 'Цитата', icon: '💬' },
        ];
    }

    constructor({ data, api, config }) {
        this.api = api;
        this.config = config || {};
        this.data = {
            prompt: data.prompt || '',
            blockType: data.blockType || 'paragraph',
            generated: data.generated || false,
        };
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:2px solid #9e1b32;border-radius:12px;padding:20px;background:linear-gradient(135deg, #fdf2f8 0%, #faf5ff 50%, #eff6ff 100%);margin:8px 0;';

        if (this.data.generated) {
            this._renderDone();
        } else {
            this._renderForm();
        }

        return this.wrapper;
    }

    _renderForm() {
        this.wrapper.innerHTML = '';

        // Header
        const header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:16px;';
        header.innerHTML = '<span style="font-size:20px;">✨</span><span style="font-size:15px;font-weight:700;color:#9e1b32;">AI-генератор блоков</span>';
        this.wrapper.appendChild(header);

        // Block type selector
        const typeRow = document.createElement('div');
        typeRow.style.cssText = 'margin-bottom:12px;';

        const typeLabel = document.createElement('label');
        typeLabel.textContent = 'Тип блока:';
        typeLabel.style.cssText = 'display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:4px;';
        typeRow.appendChild(typeLabel);

        const select = document.createElement('select');
        select.style.cssText = 'width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;background:#fff;cursor:pointer;appearance:auto;';
        AiBlockTool.BLOCK_TYPES.forEach(bt => {
            const opt = document.createElement('option');
            opt.value = bt.value;
            opt.textContent = `${bt.icon}  ${bt.label}`;
            if (bt.value === this.data.blockType) opt.selected = true;
            select.appendChild(opt);
        });
        select.addEventListener('change', () => { this.data.blockType = select.value; });
        typeRow.appendChild(select);
        this.wrapper.appendChild(typeRow);

        // Prompt
        const promptLabel = document.createElement('label');
        promptLabel.textContent = 'Промт (что сгенерировать):';
        promptLabel.style.cssText = 'display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:4px;';
        this.wrapper.appendChild(promptLabel);

        const promptArea = document.createElement('textarea');
        promptArea.value = this.data.prompt;
        promptArea.placeholder = 'Например: «Напиши 5 преимуществ работы с Pecado для B2B-партнёров» или «Создай FAQ из 4 вопросов про доставку и оплату»...';
        promptArea.style.cssText = 'width:100%;min-height:80px;padding:12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;font-family:inherit;line-height:1.5;box-sizing:border-box;';
        promptArea.addEventListener('input', () => { this.data.prompt = promptArea.value; });
        this.wrapper.appendChild(promptArea);

        // Generate button
        const btnRow = document.createElement('div');
        btnRow.style.cssText = 'display:flex;gap:8px;margin-top:12px;';

        const genBtn = document.createElement('button');
        genBtn.type = 'button';
        genBtn.innerHTML = '✨ Сгенерировать';
        genBtn.style.cssText = 'flex:1;padding:12px 20px;background:linear-gradient(135deg,#9e1b32,#c2185b);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;font-weight:600;transition:opacity 0.2s;';
        genBtn.addEventListener('mouseenter', () => { genBtn.style.opacity = '0.9'; });
        genBtn.addEventListener('mouseleave', () => { genBtn.style.opacity = '1'; });
        genBtn.addEventListener('click', () => this._generate());
        btnRow.appendChild(genBtn);

        this.wrapper.appendChild(btnRow);

        // Hint
        const hint = document.createElement('div');
        hint.textContent = '💡 ИИ сгенерирует контент и вставит готовый блок в статью';
        hint.style.cssText = 'font-size:11px;color:#888;margin-top:8px;text-align:center;';
        this.wrapper.appendChild(hint);
    }

    _renderLoading() {
        this.wrapper.innerHTML = '';
        const loader = document.createElement('div');
        loader.style.cssText = 'text-align:center;padding:30px;';

        const spinner = document.createElement('div');
        spinner.style.cssText = 'display:inline-block;width:32px;height:32px;border:3px solid #e5e7eb;border-top-color:#9e1b32;border-radius:50%;animation:aiBlockSpin 0.8s linear infinite;';
        loader.appendChild(spinner);

        // CSS animation
        if (!document.getElementById('ai-block-spin-style')) {
            const style = document.createElement('style');
            style.id = 'ai-block-spin-style';
            style.textContent = '@keyframes aiBlockSpin { to { transform: rotate(360deg) } }';
            document.head.appendChild(style);
        }

        const text = document.createElement('div');
        text.textContent = '✨ Генерация контента...';
        text.style.cssText = 'font-size:14px;color:#9e1b32;font-weight:600;margin-top:12px;';
        loader.appendChild(text);

        const sub = document.createElement('div');
        sub.textContent = 'Обычно занимает 5-15 секунд';
        sub.style.cssText = 'font-size:12px;color:#999;margin-top:4px;';
        loader.appendChild(sub);

        this.wrapper.appendChild(loader);
    }

    _renderDone() {
        this.wrapper.innerHTML = '';
        this.wrapper.style.borderColor = '#38a169';
        this.wrapper.style.background = '#f0fff4';

        const msg = document.createElement('div');
        msg.style.cssText = 'text-align:center;padding:12px;';
        msg.innerHTML = '<div style="font-size:20px;margin-bottom:4px;">✅</div><div style="font-size:13px;color:#38a169;font-weight:600;">Блок успешно сгенерирован и вставлен</div><div style="font-size:11px;color:#888;margin-top:4px;">Можете удалить этот блок</div>';
        this.wrapper.appendChild(msg);
    }

    _renderError(errorMsg) {
        this.wrapper.innerHTML = '';
        this._renderForm();

        const errEl = document.createElement('div');
        errEl.style.cssText = 'background:#fee2e2;color:#b91c1c;padding:10px 14px;border-radius:6px;font-size:13px;margin-top:12px;';
        errEl.textContent = `❌ ${errorMsg}`;
        this.wrapper.appendChild(errEl);
    }

    async _generate() {
        const prompt = this.data.prompt?.trim();
        if (!prompt) {
            this._renderError('Введите промт для генерации');
            return;
        }

        this._renderLoading();

        try {
            // Получаем CSRF-токен
            const csrfMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
            const csrfToken = csrfMatch ? decodeURIComponent(csrfMatch[1]) : '';

            const response = await fetch('/admin/api/ai-generate-block', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    prompt: prompt,
                    block_type: this.data.blockType,
                }),
            });

            if (!response.ok) {
                const errData = await response.json().catch(() => ({}));
                throw new Error(errData.message || `Ошибка сервера (${response.status})`);
            }

            const result = await response.json();

            if (!result.block || !result.block.type || !result.block.data) {
                throw new Error('Некорректный ответ от ИИ. Попробуйте ещё раз.');
            }

            // Определяем текущий индекс этого блока
            const currentIndex = this.api.blocks.getCurrentBlockIndex();

            // Вставляем сгенерированный блок ПОСЛЕ текущего
            this.api.blocks.insert(
                result.block.type,
                result.block.data,
                {},
                currentIndex + 1,
            );

            // Помечаем текущий блок как «готово»
            this.data.generated = true;
            this._renderDone();

            // Удаляем AI блок через небольшую задержку
            setTimeout(() => {
                try {
                    // Пересчитываем индекс т.к. мог сдвинуться
                    const idx = this.api.blocks.getCurrentBlockIndex();
                    this.api.blocks.delete(idx);
                } catch (e) {
                    // Если не получилось удалить — не критично, пользователь удалит вручную
                }
            }, 1500);

        } catch (err) {
            console.error('AI Block generation failed:', err);
            this._renderError(err.message || 'Произошла ошибка при генерации');
        }
    }

    save() {
        if (this.data.generated) {
            // Не сохраняем AI-блок после генерации (он temporary)
            return undefined;
        }
        return this.data;
    }
}
