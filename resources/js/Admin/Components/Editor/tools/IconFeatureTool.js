/**
 * IconFeatureTool — блок с иконками для лендингов.
 * Сетка из карточек: иконка (эмодзи/текст) + заголовок + описание.
 */
export default class IconFeatureTool {
    static get toolbox() {
        return {
            title: 'Иконки-фичи',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            columns: data.columns || '3',
            items: data.items || [
                { icon: '🚀', title: 'Заголовок', text: 'Описание' },
                { icon: '⭐', title: 'Заголовок', text: 'Описание' },
                { icon: '💎', title: 'Заголовок', text: 'Описание' },
            ],
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

        // Кол-во колонок
        const colRow = document.createElement('div');
        colRow.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:12px;';
        colRow.innerHTML = '<span style="font-size:13px;font-weight:600;">Колонок:</span>';
        ['2', '3', '4'].forEach(n => {
            const btn = document.createElement('button');
            btn.textContent = n;
            btn.style.cssText = `padding:4px 12px;border-radius:4px;border:1px solid #d1d5db;cursor:pointer;font-size:13px;${this.data.columns === n ? 'background:#9e1b32;color:#fff;border-color:#9e1b32;' : 'background:#fff;'}`;
            btn.addEventListener('click', () => { this.data.columns = n; this._renderUI(); });
            colRow.appendChild(btn);
        });
        this.wrapper.appendChild(colRow);

        // Карточки
        const grid = document.createElement('div');
        grid.style.cssText = `display:grid;grid-template-columns:repeat(${this.data.columns},1fr);gap:12px;`;

        this.data.items.forEach((item, i) => {
            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;text-align:center;';

            const iconInput = document.createElement('input');
            iconInput.value = item.icon;
            iconInput.placeholder = 'Иконка/эмодзи';
            iconInput.style.cssText = 'width:60px;text-align:center;font-size:28px;border:1px solid #e5e7eb;border-radius:4px;padding:4px;margin-bottom:6px;';
            iconInput.addEventListener('input', () => { this.data.items[i].icon = iconInput.value; });

            const titleInput = document.createElement('input');
            titleInput.value = item.title;
            titleInput.placeholder = 'Заголовок';
            titleInput.style.cssText = 'width:100%;border:none;font-weight:700;font-size:14px;text-align:center;outline:none;margin-bottom:4px;';
            titleInput.addEventListener('input', () => { this.data.items[i].title = titleInput.value; });

            const textInput = document.createElement('textarea');
            textInput.value = item.text;
            textInput.placeholder = 'Описание';
            textInput.style.cssText = 'width:100%;border:none;font-size:13px;text-align:center;outline:none;resize:vertical;min-height:40px;color:#666;';
            textInput.addEventListener('input', () => { this.data.items[i].text = textInput.value; });

            const delBtn = document.createElement('button');
            delBtn.textContent = '✕';
            delBtn.style.cssText = 'background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px;margin-top:4px;';
            delBtn.addEventListener('click', () => { this.data.items.splice(i, 1); this._renderUI(); });

            card.append(iconInput, titleInput, textInput, delBtn);
            grid.appendChild(card);
        });

        this.wrapper.appendChild(grid);

        // Кнопка добавления
        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить';
        addBtn.style.cssText = 'margin-top:10px;padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;';
        addBtn.addEventListener('click', () => {
            this.data.items.push({ icon: '✨', title: 'Заголовок', text: 'Описание' });
            this._renderUI();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() {
        return this.data;
    }
}
