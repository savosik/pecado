export default class SpacerTool {
    static get toolbox() {
        return {
            title: 'Отступ',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14M5 5l14 14M5 19L19 5"/></svg>', // needs better icon but works for now. Or just standard expand icon
        };
    }

    constructor({ data }) {
        this.data = {
            height: data.height || 40,
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px dashed #d1d5db;border-radius:4px;padding:10px;display:flex;align-items:center;justify-content:center;gap:12px;background:#f9fafb;';
        
        const label = document.createElement('span');
        label.textContent = 'Вертикальный отступ (px):';
        label.style.cssText = 'font-size:13px;color:#666;';
        
        const input = document.createElement('input');
        input.type = 'number';
        input.min = 10;
        input.max = 200;
        input.step = 10;
        input.value = this.data.height;
        input.style.cssText = 'width:60px;border:1px solid #d1d5db;border-radius:4px;padding:4px;text-align:center;font-size:13px;';
        input.addEventListener('input', () => { this.data.height = parseInt(input.value) || 40; });

        this.wrapper.append(label, input);
        return this.wrapper;
    }

    save() {
        return this.data;
    }
}
