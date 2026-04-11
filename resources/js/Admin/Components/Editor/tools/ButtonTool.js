export default class ButtonTool {
    static get toolbox() {
        return {
            title: 'Кнопка',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="7" width="18" height="10" rx="3"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            text: data.text || 'Нажать',
            url: data.url || '',
            align: data.align || 'center', // left, center, right
            style: data.style || 'solid', // solid, outline
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;text-align:center;';
        this._renderUI();
        return this.wrapper;
    }

    _renderUI() {
        this.wrapper.innerHTML = '';

        const settingsRow = document.createElement('div');
        settingsRow.style.cssText = 'display:flex;gap:8px;justify-content:center;margin-bottom:12px;';

        ['left', 'center', 'right'].forEach(a => {
            const btn = document.createElement('button');
            btn.innerHTML = a === 'left' ? '⇦' : a === 'center' ? '⇨⇦' : '⇨';
            btn.title = `Выравнивание: ${a}`;
            btn.style.cssText = `padding:4px 8px;border-radius:4px;border:1px solid #d1d5db;cursor:pointer;${this.data.align === a ? 'background:#e5e7eb;' : 'background:#fff;'}`;
            btn.addEventListener('click', () => { this.data.align = a; this._renderUI(); });
            settingsRow.appendChild(btn);
        });

        const styleSelect = document.createElement('select');
        styleSelect.style.cssText = 'padding:4px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;margin-left:12px;';
        styleSelect.innerHTML = `<option value="solid" ${this.data.style==='solid'?'selected':''}>Заливка</option><option value="outline" ${this.data.style==='outline'?'selected':''}>Контур</option>`;
        styleSelect.addEventListener('change', () => { this.data.style = styleSelect.value; this._renderUI(); });
        settingsRow.appendChild(styleSelect);

        this.wrapper.appendChild(settingsRow);

        const btnPreview = document.createElement('div');
        btnPreview.style.cssText = `display:flex;justify-content:${this.data.align === 'left' ? 'flex-start' : this.data.align === 'right' ? 'flex-end' : 'center'};margin-bottom:12px;`;
        
        const btnInput = document.createElement('input');
        btnInput.value = this.data.text;
        btnInput.style.cssText = `border:${this.data.style === 'outline' ? '2px solid #9e1b32' : 'none'};background:${this.data.style === 'outline'? 'transparent' : '#9e1b32'};color:${this.data.style === 'outline'? '#9e1b32' : '#fff'};border-radius:6px;padding:8px 24px;font-weight:600;font-size:14px;text-align:center;outline:none;`;
        btnInput.addEventListener('input', () => { this.data.text = btnInput.value; });
        btnPreview.appendChild(btnInput);
        this.wrapper.appendChild(btnPreview);

        const urlInput = document.createElement('input');
        urlInput.value = this.data.url;
        urlInput.placeholder = 'URL ссылки';
        urlInput.style.cssText = 'width:100%;border:1px solid #d1d5db;border-radius:4px;padding:6px;font-size:13px;';
        urlInput.addEventListener('input', () => { this.data.url = urlInput.value; });
        this.wrapper.appendChild(urlInput);
    }

    save() {
        return this.data;
    }
}
