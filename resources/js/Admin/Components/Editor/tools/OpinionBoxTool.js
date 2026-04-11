/**
 * OpinionBoxTool — мнение эксперта с фото и должностью.
 */
export default class OpinionBoxTool {
    static get toolbox() {
        return {
            title: 'Мнение эксперта',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><circle cx="5" cy="5" r="4" fill="currentColor"/><line x1="10" y1="3" x2="17" y2="3" stroke="currentColor" stroke-width="1.5"/><line x1="10" y1="7" x2="15" y2="7" stroke="currentColor" stroke-width="1.5"/><line x1="0" y1="13" x2="17" y2="13" stroke="currentColor" stroke-width="1.5"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            name: data.name || '',
            title: data.title || '',
            photo: data.photo || '',
            text: data.text || '',
        };
    }

    render() {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'border:1px solid #e5e7eb;padding:16px;border-radius:12px;margin:8px 0;background:#fafafa;';

        const label = document.createElement('div');
        label.textContent = '💬 Мнение эксперта';
        label.style.cssText = 'font-size:13px;color:#888;margin-bottom:10px;font-weight:500;';
        wrapper.appendChild(label);

        const fields = [
            { key: 'photo', placeholder: 'URL фото эксперта...', type: 'input' },
            { key: 'name', placeholder: 'Имя эксперта...', type: 'input' },
            { key: 'title', placeholder: 'Должность...', type: 'input' },
            { key: 'text', placeholder: 'Текст мнения...', type: 'textarea' },
        ];

        fields.forEach(f => {
            const el = document.createElement(f.type === 'textarea' ? 'textarea' : 'input');
            el.value = this.data[f.key];
            el.placeholder = f.placeholder;
            el.style.cssText = `width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;font-size:14px;font-family:inherit;${f.type === 'textarea' ? 'min-height:80px;resize:vertical;line-height:1.6;' : ''}`;
            el.addEventListener('input', () => { this.data[f.key] = el.value; });
            wrapper.appendChild(el);
        });

        return wrapper;
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
