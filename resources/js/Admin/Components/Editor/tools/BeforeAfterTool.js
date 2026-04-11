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
        
        const createGroup = (prefix) => {
            const group = document.createElement('div');
            group.style.cssText = 'margin-bottom:12px;';
            
            const title = document.createElement('div');
            title.textContent = prefix === 'before' ? 'Изображение "До"' : 'Изображение "После"';
            title.style.cssText = 'font-size:13px;font-weight:600;margin-bottom:6px;';
            
            const urlInp = document.createElement('input');
            urlInp.value = this.data[`${prefix}Url`];
            urlInp.placeholder = 'URL картинки';
            urlInp.style.cssText = 'width:100%;border:1px solid #d1d5db;border-radius:4px;padding:6px;margin-bottom:6px;font-size:12px;';
            urlInp.addEventListener('input', () => { this.data[`${prefix}Url`] = urlInp.value; });
            
            const labelInp = document.createElement('input');
            labelInp.value = this.data[`${prefix}Label`];
            labelInp.placeholder = 'Подпись (ярлык)';
            labelInp.style.cssText = 'width:100%;border:1px solid #d1d5db;border-radius:4px;padding:6px;font-size:12px;';
            labelInp.addEventListener('input', () => { this.data[`${prefix}Label`] = labelInp.value; });
            
            group.append(title, urlInp, labelInp);
            return group;
        };

        this.wrapper.append(createGroup('before'), createGroup('after'));
        return this.wrapper;
    }

    save() {
        return this.data;
    }
}
