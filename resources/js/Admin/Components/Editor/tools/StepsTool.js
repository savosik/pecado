export default class StepsTool {
    static get toolbox() {
        return {
            title: 'Шаги',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12l4 4 4-4m-4 4V4m8 8l4 4 4-4m-4 4V4"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            steps: data.steps || [
                { title: 'Шаг 1', text: 'Описание' },
                { title: 'Шаг 2', text: 'Описание' }
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

        this.data.steps.forEach((step, i) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;';

            const num = document.createElement('div');
            num.textContent = i + 1;
            num.style.cssText = 'width:28px;height:28px;border-radius:50%;background:#9e1b32;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;';

            const content = document.createElement('div');
            content.style.cssText = 'flex:1;display:flex;flex-direction:column;gap:4px;';
            
            const titleInp = document.createElement('input');
            titleInp.value = step.title;
            titleInp.placeholder = 'Заголовок шага';
            titleInp.style.cssText = 'border:1px solid #d1d5db;border-radius:4px;padding:4px 8px;font-size:13px;font-weight:600;';
            titleInp.addEventListener('input', () => { this.data.steps[i].title = titleInp.value; });

            const textInp = document.createElement('textarea');
            textInp.value = step.text;
            textInp.placeholder = 'Описание шага';
            textInp.style.cssText = 'border:1px solid #d1d5db;border-radius:4px;padding:4px 8px;font-size:12px;min-height:40px;resize:vertical;';
            textInp.addEventListener('input', () => { this.data.steps[i].text = textInp.value; });

            content.append(titleInp, textInp);

            const del = document.createElement('button');
            del.textContent = '✕';
            del.style.cssText = 'background:none;border:none;color:#ef4444;cursor:pointer;padding:4px;';
            del.addEventListener('click', () => { this.data.steps.splice(i, 1); this._renderUI(); });

            row.append(num, content, del);
            list.appendChild(row);
        });

        this.wrapper.appendChild(list);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить шаг';
        addBtn.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;width:100%;';
        addBtn.addEventListener('click', () => {
            this.data.steps.push({ title: '', text: '' });
            this._renderUI();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() {
        return this.data;
    }
}
