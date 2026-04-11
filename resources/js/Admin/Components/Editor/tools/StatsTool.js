export default class StatsTool {
    static get toolbox() {
        return {
            title: 'Статистика',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            items: data.items || [
                { value: '5000+', label: 'Товаров в каталоге' },
                { value: '200+', label: 'Активных партнёров' },
                { value: '7 лет', label: 'На рынке' }
            ],
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;';
        this._renderUI();
        return this.wrapper;
    }

    _renderUI() {
        this.wrapper.innerHTML = '';
        
        const grid = document.createElement('div');
        grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px;';

        this.data.items.forEach((item, i) => {
            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;text-align:center;flex:1;min-width:120px;';

            const valInput = document.createElement('input');
            valInput.value = item.value;
            valInput.placeholder = 'Значение (напр. 100+)';
            valInput.style.cssText = 'width:100%;border:none;font-weight:800;font-size:24px;text-align:center;color:#9e1b32;outline:none;margin-bottom:4px;';
            valInput.addEventListener('input', () => { this.data.items[i].value = valInput.value; });

            const lblInput = document.createElement('input');
            lblInput.value = item.label;
            lblInput.placeholder = 'Описание';
            lblInput.style.cssText = 'width:100%;border:none;font-size:12px;text-align:center;color:#666;outline:none;';
            lblInput.addEventListener('input', () => { this.data.items[i].label = lblInput.value; });

            const delBtn = document.createElement('button');
            delBtn.textContent = '✕';
            delBtn.style.cssText = 'background:none;border:none;color:#ef4444;cursor:pointer;font-size:12px;margin-top:8px;';
            delBtn.addEventListener('click', () => { this.data.items.splice(i, 1); this._renderUI(); });

            card.append(valInput, lblInput, delBtn);
            grid.appendChild(card);
        });

        this.wrapper.appendChild(grid);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить показатель';
        addBtn.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;width:100%;';
        addBtn.addEventListener('click', () => {
            this.data.items.push({ value: '0', label: 'Новый' });
            this._renderUI();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() {
        return this.data;
    }
}
