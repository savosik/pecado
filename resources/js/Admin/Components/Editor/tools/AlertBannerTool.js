export default class AlertBannerTool {
    static get toolbox() {
        return {
            title: 'Баннер',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 14h14M5 10h14M3 6h18M3 18h18"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            text: data.text || 'Текст баннера',
            style: data.style || 'promo', // promo, info, warning
            btnText: data.btnText || '',
            btnUrl: data.btnUrl || '',
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;';
        this._renderUI();
        return this.wrapper;
    }

    _renderUI() {
        this.wrapper.innerHTML = '';
        
        // Style selector
        const styleRow = document.createElement('div');
        styleRow.style.cssText = 'display:flex;gap:8px;margin-bottom:12px;justify-content:center;';
        [
            {v: 'promo', l: 'Промо (акцент)', c: '#9e1b32'},
            {v: 'info', l: 'Инфо (синий)', c: '#2563eb'},
            {v: 'warning', l: 'Внимание (жёлтый)', c: '#fbbf24'}
        ].forEach(s => {
            const btn = document.createElement('button');
            btn.textContent = s.l;
            btn.style.cssText = `padding:4px 10px;font-size:12px;border-radius:4px;border:1px solid ${s.c};cursor:pointer;`;
            if (this.data.style === s.v) {
                btn.style.background = s.c;
                btn.style.color = s.v === 'warning' ? '#000' : '#fff';
            } else {
                btn.style.background = '#fff';
                btn.style.color = '#333';
            }
            btn.addEventListener('click', () => { this.data.style = s.v; this._renderUI(); });
            styleRow.appendChild(btn);
        });
        
        const preview = document.createElement('div');
        preview.style.cssText = `padding:16px 20px;border-radius:6px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;`;
        if (this.data.style === 'promo') { preview.style.background = '#9e1b32'; preview.style.color = '#fff'; }
        else if (this.data.style === 'info') { preview.style.background = '#e0f2fe'; preview.style.color = '#0369a1'; }
        else { preview.style.background = '#fef3c7'; preview.style.color = '#b45309'; }

        const textInput = document.createElement('input');
        textInput.value = this.data.text;
        textInput.style.cssText = 'background:transparent;border:none;color:inherit;font-weight:600;font-size:14px;outline:none;flex:1;';
        textInput.addEventListener('input', () => { this.data.text = textInput.value; });
        preview.appendChild(textInput);

        this.wrapper.append(styleRow, preview);

        // Button settings
        const btnRow = document.createElement('div');
        btnRow.style.cssText = 'display:flex;gap:8px;';
        
        const btnTextInp = document.createElement('input');
        btnTextInp.value = this.data.btnText;
        btnTextInp.placeholder = 'Текст кнопки (пусто = без кнопки)';
        btnTextInp.style.cssText = 'flex:1;border:1px solid #d1d5db;border-radius:4px;padding:6px;font-size:13px;';
        btnTextInp.addEventListener('input', () => { this.data.btnText = btnTextInp.value; });
        
        const btnUrlInp = document.createElement('input');
        btnUrlInp.value = this.data.btnUrl;
        btnUrlInp.placeholder = 'URL кнопки';
        btnUrlInp.style.cssText = 'flex:2;border:1px solid #d1d5db;border-radius:4px;padding:6px;font-size:13px;';
        btnUrlInp.addEventListener('input', () => { this.data.btnUrl = btnUrlInp.value; });

        btnRow.append(btnTextInp, btnUrlInp);
        this.wrapper.appendChild(btnRow);
    }

    save() {
        return this.data;
    }
}
