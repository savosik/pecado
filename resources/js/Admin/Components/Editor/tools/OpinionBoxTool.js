/**
 * OpinionBoxTool — мнение эксперта с фото и должностью.
 */
import { createImageField } from './editorUpload';

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

        // Фото эксперта (URL + загрузка)
        wrapper.appendChild(createImageField({
            value: this.data.photo,
            placeholder: 'URL фото эксперта или загрузите →',
            onChange: (url) => { this.data.photo = url; },
            previewHeight: '80px',
        }));

        // Имя
        const nameInput = document.createElement('input');
        nameInput.value = this.data.name;
        nameInput.placeholder = 'Имя эксперта...';
        nameInput.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;font-size:14px;';
        nameInput.addEventListener('input', () => { this.data.name = nameInput.value; });
        wrapper.appendChild(nameInput);

        // Должность
        const titleInput = document.createElement('input');
        titleInput.value = this.data.title;
        titleInput.placeholder = 'Должность...';
        titleInput.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;font-size:14px;';
        titleInput.addEventListener('input', () => { this.data.title = titleInput.value; });
        wrapper.appendChild(titleInput);

        // Текст
        const textArea = document.createElement('textarea');
        textArea.value = this.data.text;
        textArea.placeholder = 'Текст мнения...';
        textArea.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;font-size:14px;font-family:inherit;min-height:80px;resize:vertical;line-height:1.6;';
        textArea.addEventListener('input', () => { this.data.text = textArea.value; });
        wrapper.appendChild(textArea);

        return wrapper;
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
