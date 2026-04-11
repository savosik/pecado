/**
 * ProductCarouselTool — карусель товаров (ввод ID товаров).
 */
export default class ProductCarouselTool {
    static get toolbox() {
        return {
            title: 'Карусель товаров',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><rect x="0" y="1" width="5" height="13" rx="1" fill="currentColor" opacity="0.4"/><rect x="6" y="0" width="5" height="15" rx="1" fill="currentColor"/><rect x="12" y="1" width="5" height="13" rx="1" fill="currentColor" opacity="0.4"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            title: data.title || '',
            productIds: data.productIds || [],
        };
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:2px solid #e5e7eb;padding:16px;border-radius:12px;margin:8px 0;background:#f9fafb;';
        this._rebuild();
        return this.wrapper;
    }

    _rebuild() {
        this.wrapper.innerHTML = '';

        const label = document.createElement('div');
        label.textContent = '🛒 Карусель товаров';
        label.style.cssText = 'font-size:13px;color:#888;margin-bottom:10px;font-weight:500;';
        this.wrapper.appendChild(label);

        const titleInput = document.createElement('input');
        titleInput.value = this.data.title;
        titleInput.placeholder = 'Заголовок секции (напр. «Рекомендуемые товары»)...';
        titleInput.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:10px;font-size:14px;';
        titleInput.addEventListener('input', () => { this.data.title = titleInput.value; });
        this.wrapper.appendChild(titleInput);

        // Текущие ID
        if (this.data.productIds.length > 0) {
            const tags = document.createElement('div');
            tags.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;';
            this.data.productIds.forEach((id, idx) => {
                const tag = document.createElement('span');
                tag.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#e0e7ff;border-radius:16px;font-size:13px;color:#3730a3;';
                tag.textContent = `ID: ${id}`;
                const rm = document.createElement('button');
                rm.textContent = '✕';
                rm.type = 'button';
                rm.style.cssText = 'background:none;border:none;cursor:pointer;color:#6366f1;font-size:12px;padding:0;';
                rm.addEventListener('click', () => {
                    this.data.productIds.splice(idx, 1);
                    this._rebuild();
                });
                tag.appendChild(rm);
                tags.appendChild(tag);
            });
            this.wrapper.appendChild(tags);
        }

        const addRow = document.createElement('div');
        addRow.style.cssText = 'display:flex;gap:8px;';
        const idInput = document.createElement('input');
        idInput.type = 'number';
        idInput.placeholder = 'ID товара...';
        idInput.style.cssText = 'flex:1;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;';
        addRow.appendChild(idInput);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить товар';
        addBtn.type = 'button';
        addBtn.style.cssText = 'padding:8px 16px;background:#4f46e5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;white-space:nowrap;';
        addBtn.addEventListener('click', () => {
            const id = parseInt(idInput.value);
            if (id && !this.data.productIds.includes(id)) {
                this.data.productIds.push(id);
                idInput.value = '';
                this._rebuild();
            }
        });
        addRow.appendChild(addBtn);
        this.wrapper.appendChild(addRow);

        const hint = document.createElement('div');
        hint.textContent = 'Введите ID товаров из каталога. На фронтенде они отобразятся как карусель с карточками.';
        hint.style.cssText = 'font-size:12px;color:#9ca3af;margin-top:8px;';
        this.wrapper.appendChild(hint);
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
