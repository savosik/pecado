import { createImageField } from './editorUpload';

export default class BeforeAfterTool {
    static get toolbox() {
        return {
            title: 'До / После',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v18M4 12V8h4M20 12v4h-4"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            beforeUrl: data.beforeUrl || '',
            beforeLabel: data.beforeLabel || 'До',
            afterUrl: data.afterUrl || '',
            afterLabel: data.afterLabel || 'После',
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;';

        const grid = document.createElement('div');
        grid.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:16px;';

        grid.appendChild(this._createGroup('before'));
        grid.appendChild(this._createGroup('after'));

        this.wrapper.appendChild(grid);
        return this.wrapper;
    }

    _createGroup(prefix) {
        const group = document.createElement('div');

        const title = document.createElement('div');
        title.textContent = prefix === 'before' ? '📷 Изображение "До"' : '📷 Изображение "После"';
        title.style.cssText = 'font-size:13px;font-weight:600;margin-bottom:6px;';
        group.appendChild(title);

        // Изображение с загрузкой
        group.appendChild(createImageField({
            value: this.data[`${prefix}Url`],
            placeholder: 'URL или загрузите файл →',
            onChange: (url) => { this.data[`${prefix}Url`] = url; },
            previewHeight: '100px',
        }));

        const labelInp = document.createElement('input');
        labelInp.value = this.data[`${prefix}Label`];
        labelInp.placeholder = 'Подпись (ярлык)';
        labelInp.style.cssText = 'width:100%;border:1px solid #d1d5db;border-radius:4px;padding:6px;font-size:12px;';
        labelInp.addEventListener('input', () => { this.data[`${prefix}Label`] = labelInp.value; });
        group.appendChild(labelInp);

        return group;
    }

    save() {
        return this.data;
    }
}
