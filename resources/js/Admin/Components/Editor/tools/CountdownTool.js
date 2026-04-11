/**
 * CountdownTool — таймер обратного отсчёта.
 * Админ устанавливает дату/время и заголовок, фронтенд считает в реальном времени.
 */
export default class CountdownTool {
    static get toolbox() {
        return {
            title: 'Таймер',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3L2 6"/><path d="M22 6l-3-3"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            title: data.title || 'До конца акции осталось',
            targetDate: data.targetDate || this._defaultDate(),
            style: data.style || 'default',
        };
    }

    _defaultDate() {
        const d = new Date();
        d.setDate(d.getDate() + 7);
        return d.toISOString().slice(0, 16);
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:20px;text-align:center;';

        // Заголовок
        const titleInput = document.createElement('input');
        titleInput.value = this.data.title;
        titleInput.placeholder = 'Заголовок таймера';
        titleInput.style.cssText = 'width:100%;border:none;font-weight:700;font-size:16px;text-align:center;outline:none;margin-bottom:12px;';
        titleInput.addEventListener('input', () => { this.data.title = titleInput.value; });

        // Дата
        const dateRow = document.createElement('div');
        dateRow.style.cssText = 'display:flex;gap:10px;align-items:center;justify-content:center;margin-bottom:12px;';
        const label = document.createElement('span');
        label.textContent = 'Дата окончания:';
        label.style.cssText = 'font-size:13px;font-weight:500;';
        const dateInput = document.createElement('input');
        dateInput.type = 'datetime-local';
        dateInput.value = this.data.targetDate;
        dateInput.style.cssText = 'border:1px solid #d1d5db;border-radius:4px;padding:6px 10px;font-size:14px;';
        dateInput.addEventListener('input', () => { this.data.targetDate = dateInput.value; });
        dateRow.append(label, dateInput);

        // Стиль
        const styleRow = document.createElement('div');
        styleRow.style.cssText = 'display:flex;gap:8px;align-items:center;justify-content:center;margin-bottom:12px;';
        styleRow.innerHTML = '<span style="font-size:13px;font-weight:500;">Стиль:</span>';
        ['default', 'accent', 'dark'].forEach(s => {
            const btn = document.createElement('button');
            btn.textContent = s === 'default' ? 'Обычный' : s === 'accent' ? 'Акцент' : 'Тёмный';
            btn.style.cssText = `padding:4px 12px;border-radius:4px;border:1px solid #d1d5db;cursor:pointer;font-size:12px;${this.data.style === s ? 'background:#9e1b32;color:#fff;border-color:#9e1b32;' : 'background:#fff;'}`;
            btn.addEventListener('click', () => { this.data.style = s; this.render(); });
            styleRow.appendChild(btn);
        });

        // Превью
        const preview = document.createElement('div');
        preview.style.cssText = 'display:flex;gap:16px;justify-content:center;margin-top:8px;';
        ['Дней', 'Часов', 'Минут', 'Секунд'].forEach(label => {
            const box = document.createElement('div');
            box.style.cssText = 'background:#f3f4f6;border-radius:6px;padding:12px 16px;min-width:65px;';
            box.innerHTML = `<div style="font-size:24px;font-weight:800;color:#1a1a1a;">00</div><div style="font-size:11px;color:#666;margin-top:2px;">${label}</div>`;
            preview.appendChild(box);
        });

        this.wrapper.append(titleInput, dateRow, styleRow, preview);
        return this.wrapper;
    }

    save() {
        return this.data;
    }
}
