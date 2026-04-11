export default class MapTool {
    static get toolbox() {
        return {
            title: 'Карта',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            embedCode: data.embedCode || '',
            height: data.height || 400,
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;';
        
        const label = document.createElement('div');
        label.textContent = 'Встроенный код карты (iframe от Яндекса/Google):';
        label.style.cssText = 'font-size:13px;font-weight:600;margin-bottom:8px;';

        const textarea = document.createElement('textarea');
        textarea.value = this.data.embedCode;
        textarea.placeholder = '<iframe src="..."></iframe>';
        textarea.style.cssText = 'width:100%;border:1px solid #d1d5db;border-radius:4px;padding:8px;font-size:12px;font-family:monospace;min-height:80px;margin-bottom:12px;';
        textarea.addEventListener('input', () => { this.data.embedCode = textarea.value; });

        const heightRow = document.createElement('div');
        heightRow.style.cssText = 'display:flex;align-items:center;gap:8px;';
        const hLabel = document.createElement('span');
        hLabel.textContent = 'Высота блока (px):';
        hLabel.style.cssText = 'font-size:13px;color:#666;';
        
        const hInput = document.createElement('input');
        hInput.type = 'number';
        hInput.value = this.data.height;
        hInput.style.cssText = 'width:60px;border:1px solid #d1d5db;border-radius:4px;padding:4px;text-align:center;font-size:13px;';
        hInput.addEventListener('input', () => { this.data.height = parseInt(hInput.value) || 400; });
        heightRow.append(hLabel, hInput);

        this.wrapper.append(label, textarea, heightRow);
        return this.wrapper;
    }

    save() {
        return this.data;
    }
}
