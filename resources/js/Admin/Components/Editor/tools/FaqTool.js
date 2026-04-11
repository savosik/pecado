/**
 * FaqTool — блок вопрос-ответ (аккордеон).
 */
export default class FaqTool {
    static get toolbox() {
        return {
            title: 'FAQ',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><text x="4" y="13" font-size="14" font-weight="bold" fill="currentColor">?</text></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            items: data.items && data.items.length > 0 ? data.items : [{ question: '', answer: '' }],
        };
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px dashed #ccc;padding:12px;border-radius:8px;margin:8px 0;';
        this._rebuild();
        return this.wrapper;
    }

    _rebuild() {
        this.wrapper.innerHTML = '';

        const title = document.createElement('div');
        title.textContent = '❓ FAQ блок';
        title.style.cssText = 'font-size:13px;color:#888;margin-bottom:8px;font-weight:500;';
        this.wrapper.appendChild(title);

        this.data.items.forEach((item, idx) => {
            const itemEl = document.createElement('div');
            itemEl.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:8px;margin-bottom:8px;position:relative;';

            const qInput = document.createElement('input');
            qInput.value = item.question;
            qInput.placeholder = `Вопрос ${idx + 1}...`;
            qInput.style.cssText = 'width:100%;padding:6px;border:none;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:600;margin-bottom:4px;outline:none;';
            qInput.addEventListener('input', () => { item.question = qInput.value; });
            itemEl.appendChild(qInput);

            const aInput = document.createElement('textarea');
            aInput.value = item.answer;
            aInput.placeholder = 'Ответ...';
            aInput.style.cssText = 'width:100%;min-height:50px;padding:6px;border:none;resize:vertical;font-size:14px;line-height:1.5;font-family:inherit;outline:none;';
            aInput.addEventListener('input', () => { item.answer = aInput.value; });
            itemEl.appendChild(aInput);

            if (this.data.items.length > 1) {
                const removeBtn = document.createElement('button');
                removeBtn.textContent = '✕';
                removeBtn.type = 'button';
                removeBtn.style.cssText = 'position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:#ef4444;color:#fff;border:none;cursor:pointer;font-size:11px;';
                removeBtn.addEventListener('click', () => {
                    this.data.items.splice(idx, 1);
                    this._rebuild();
                });
                itemEl.appendChild(removeBtn);
            }

            this.wrapper.appendChild(itemEl);
        });

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить вопрос';
        addBtn.type = 'button';
        addBtn.style.cssText = 'padding:6px 16px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:13px;color:#374151;';
        addBtn.addEventListener('click', () => {
            this.data.items.push({ question: '', answer: '' });
            this._rebuild();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
