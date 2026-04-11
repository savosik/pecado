export default class TimelineTool {
    static get toolbox() {
        return {
            title: 'Хронология',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M8 6h8M8 12h8M8 18h8"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            events: data.events || [
                { date: '2023', title: 'Событие 1', text: '' },
                { date: '2024', title: 'Событие 2', text: '' }
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
        const list = document.createElement('div');
        list.style.cssText = 'display:flex;flex-direction:column;gap:8px;margin-bottom:12px;';

        this.data.events.forEach((ev, i) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;';

            const dateInp = document.createElement('input');
            dateInp.value = ev.date;
            dateInp.placeholder = 'Дата/Год';
            dateInp.style.cssText = 'width:80px;border:1px solid #d1d5db;border-radius:4px;padding:4px 8px;font-size:13px;font-weight:600;flex-shrink:0;';
            dateInp.addEventListener('input', () => { this.data.events[i].date = dateInp.value; });

            const content = document.createElement('div');
            content.style.cssText = 'flex:1;display:flex;flex-direction:column;gap:4px;';
            
            const titleInp = document.createElement('input');
            titleInp.value = ev.title;
            titleInp.placeholder = 'Заголовок';
            titleInp.style.cssText = 'border:1px solid #d1d5db;border-radius:4px;padding:4px 8px;font-size:13px;font-weight:600;';
            titleInp.addEventListener('input', () => { this.data.events[i].title = titleInp.value; });

            const textInp = document.createElement('textarea');
            textInp.value = ev.text;
            textInp.placeholder = 'Описание';
            textInp.style.cssText = 'border:1px solid #d1d5db;border-radius:4px;padding:4px 8px;font-size:12px;min-height:40px;resize:vertical;';
            textInp.addEventListener('input', () => { this.data.events[i].text = textInp.value; });

            content.append(titleInp, textInp);

            const del = document.createElement('button');
            del.textContent = '✕';
            del.style.cssText = 'background:none;border:none;color:#ef4444;cursor:pointer;padding:4px;';
            del.addEventListener('click', () => { this.data.events.splice(i, 1); this._renderUI(); });

            row.append(dateInp, content, del);
            list.appendChild(row);
        });

        this.wrapper.appendChild(list);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить событие';
        addBtn.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;width:100%;';
        addBtn.addEventListener('click', () => {
            this.data.events.push({ date: '', title: '', text: '' });
            this._renderUI();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() {
        return this.data;
    }
}
