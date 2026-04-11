/**
 * PullQuoteTool — крупная центрированная цитата.
 */
export default class PullQuoteTool {
    static get toolbox() {
        return {
            title: 'Крупная цитата',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><text x="1" y="13" font-size="16" font-weight="bold" fill="currentColor">❝</text></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            text: data.text || '',
            caption: data.caption || '',
        };
    }

    render() {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'text-align:center;padding:20px;margin:8px 0;border-top:2px solid #ddd;border-bottom:2px solid #ddd;';

        const textInput = document.createElement('textarea');
        textInput.value = this.data.text;
        textInput.placeholder = 'Текст крупной цитаты...';
        textInput.style.cssText = 'width:100%;min-height:60px;padding:8px;border:none;text-align:center;font-size:20px;font-style:italic;line-height:1.6;resize:vertical;font-family:inherit;outline:none;';
        textInput.addEventListener('input', () => { this.data.text = textInput.value; });
        wrapper.appendChild(textInput);

        const captionInput = document.createElement('input');
        captionInput.value = this.data.caption;
        captionInput.placeholder = 'Автор / источник...';
        captionInput.style.cssText = 'width:60%;padding:6px;border:none;border-top:1px solid #eee;text-align:center;font-size:13px;color:#888;margin-top:8px;outline:none;';
        captionInput.addEventListener('input', () => { this.data.caption = captionInput.value; });
        wrapper.appendChild(captionInput);

        return wrapper;
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
