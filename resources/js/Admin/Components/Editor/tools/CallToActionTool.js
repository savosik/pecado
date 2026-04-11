/**
 * CallToActionTool — CTA-блок с заголовком, текстом и кнопкой.
 */
export default class CallToActionTool {
    static get toolbox() {
        return {
            title: 'CTA кнопка',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><rect x="1" y="3" width="15" height="9" rx="2" stroke="currentColor" stroke-width="1.5" fill="none"/><line x1="5" y1="7.5" x2="12" y2="7.5" stroke="currentColor" stroke-width="1.5"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            title: data.title || '',
            text: data.text || '',
            buttonText: data.buttonText || 'Подробнее',
            buttonUrl: data.buttonUrl || '',
            style: data.style || 'primary',
        };
    }

    render() {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'border:2px solid #7c3aed;padding:16px;border-radius:12px;margin:8px 0;background:#faf5ff;';

        const titleInput = document.createElement('input');
        titleInput.value = this.data.title;
        titleInput.placeholder = 'Заголовок CTA...';
        titleInput.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;font-size:16px;font-weight:600;';
        titleInput.addEventListener('input', () => { this.data.title = titleInput.value; });
        wrapper.appendChild(titleInput);

        const textInput = document.createElement('textarea');
        textInput.value = this.data.text;
        textInput.placeholder = 'Описание (необязательно)...';
        textInput.style.cssText = 'width:100%;min-height:50px;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;resize:vertical;font-size:14px;font-family:inherit;';
        textInput.addEventListener('input', () => { this.data.text = textInput.value; });
        wrapper.appendChild(textInput);

        const row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:8px;';

        const btnTextInput = document.createElement('input');
        btnTextInput.value = this.data.buttonText;
        btnTextInput.placeholder = 'Текст кнопки...';
        btnTextInput.style.cssText = 'flex:1;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;';
        btnTextInput.addEventListener('input', () => { this.data.buttonText = btnTextInput.value; });
        row.appendChild(btnTextInput);

        const urlInput = document.createElement('input');
        urlInput.value = this.data.buttonUrl;
        urlInput.placeholder = 'URL ссылки...';
        urlInput.style.cssText = 'flex:2;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;';
        urlInput.addEventListener('input', () => { this.data.buttonUrl = urlInput.value; });
        row.appendChild(urlInput);

        wrapper.appendChild(row);
        return wrapper;
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
