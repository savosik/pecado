/**
 * ImageTextTool — блок «изображение + текст» (два столбца).
 */
import { createImageField } from './editorUpload';

export default class ImageTextTool {
    static get toolbox() {
        return {
            title: 'Фото + текст',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><rect x="0" y="0" width="7" height="15" rx="1" fill="currentColor"/><line x1="10" y1="2" x2="17" y2="2" stroke="currentColor" stroke-width="1.5"/><line x1="10" y1="6" x2="17" y2="6" stroke="currentColor" stroke-width="1.5"/><line x1="10" y1="10" x2="15" y2="10" stroke="currentColor" stroke-width="1.5"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            imageUrl: data.imageUrl || '',
            text: data.text || '',
            imagePosition: data.imagePosition || 'left',
            title: data.title || '',
        };
    }

    render() {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'border:1px dashed #ccc;padding:12px;border-radius:8px;margin:8px 0;';

        // Позиция изображения
        const posRow = document.createElement('div');
        posRow.style.cssText = 'margin-bottom:8px;display:flex;gap:8px;align-items:center;';
        const posLabel = document.createElement('span');
        posLabel.textContent = 'Фото:';
        posLabel.style.cssText = 'font-size:13px;color:#888;';
        posRow.appendChild(posLabel);

        ['left', 'right'].forEach(pos => {
            const btn = document.createElement('button');
            btn.textContent = pos === 'left' ? '← Слева' : 'Справа →';
            btn.type = 'button';
            const active = this.data.imagePosition === pos;
            btn.style.cssText = `padding:4px 10px;border-radius:4px;border:1px solid ${active ? '#7c3aed' : '#ddd'};background:${active ? '#7c3aed' : '#fff'};color:${active ? '#fff' : '#333'};cursor:pointer;font-size:12px;`;
            btn.addEventListener('click', () => {
                this.data.imagePosition = pos;
                posRow.querySelectorAll('button').forEach((b, i) => {
                    const a = (pos === 'left' && i === 0) || (pos === 'right' && i === 1);
                    b.style.borderColor = a ? '#7c3aed' : '#ddd';
                    b.style.background = a ? '#7c3aed' : '#fff';
                    b.style.color = a ? '#fff' : '#333';
                });
            });
            posRow.appendChild(btn);
        });
        wrapper.appendChild(posRow);

        // Изображение (URL + загрузка)
        wrapper.appendChild(createImageField({
            value: this.data.imageUrl,
            placeholder: 'URL изображения или загрузите файл →',
            onChange: (url) => { this.data.imageUrl = url; },
            previewHeight: '120px',
        }));

        // Заголовок
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.value = this.data.title;
        titleInput.placeholder = 'Заголовок (необязательно)...';
        titleInput.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;font-size:14px;';
        titleInput.addEventListener('input', () => { this.data.title = titleInput.value; });
        wrapper.appendChild(titleInput);

        // Текст
        const textarea = document.createElement('textarea');
        textarea.value = this.data.text;
        textarea.placeholder = 'Текстовое описание...';
        textarea.style.cssText = 'width:100%;min-height:80px;padding:8px;border:1px solid #e5e7eb;border-radius:6px;resize:vertical;font-size:14px;line-height:1.6;font-family:inherit;';
        textarea.addEventListener('input', () => { this.data.text = textarea.value; });
        wrapper.appendChild(textarea);

        return wrapper;
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
