/**
 * CoverImageTool — обложка (полноширинное изображение с оверлеем текста).
 */
export default class CoverImageTool {
    static get toolbox() {
        return {
            title: 'Обложка',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><rect x="0" y="0" width="17" height="15" rx="2" fill="currentColor" opacity="0.3"/><line x1="3" y1="8" x2="14" y2="8" stroke="currentColor" stroke-width="2"/><line x1="5" y1="11" x2="12" y2="11" stroke="currentColor" stroke-width="1.5"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            url: data.url || '',
            title: data.title || '',
            subtitle: data.subtitle || '',
            overlayColor: data.overlayColor || 'rgba(0,0,0,0.4)',
        };
    }

    render() {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'border:1px dashed #ccc;padding:12px;border-radius:8px;margin:8px 0;';

        const label = document.createElement('div');
        label.textContent = '🖼 Обложка';
        label.style.cssText = 'font-size:13px;color:#888;margin-bottom:8px;font-weight:500;';
        wrapper.appendChild(label);

        const fields = [
            { key: 'url', placeholder: 'URL фонового изображения...' },
            { key: 'title', placeholder: 'Заголовок на обложке...' },
            { key: 'subtitle', placeholder: 'Подзаголовок (необязательно)...' },
        ];

        fields.forEach(f => {
            const el = document.createElement('input');
            el.value = this.data[f.key];
            el.placeholder = f.placeholder;
            el.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;font-size:14px;';
            el.addEventListener('input', () => { this.data[f.key] = el.value; });
            wrapper.appendChild(el);
        });

        return wrapper;
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
