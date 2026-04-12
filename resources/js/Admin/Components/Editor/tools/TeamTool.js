/**
 * TeamTool — блок «Наша команда».
 */
import { createImageField } from './editorUpload';

export default class TeamTool {
    static get toolbox() {
        return {
            title: 'Команда',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            columns: data.columns || '3',
            members: data.members || [
                { photo: '', name: 'Имя Фамилия', role: 'Должность', bio: '' },
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

        // Колонки
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
        grid.style.cssText = `display:grid;grid-template-columns:repeat(${this.data.columns},1fr);gap:12px;margin-bottom:12px;`;

        this.data.members.forEach((m, i) => {
            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;text-align:center;';

            // Фото с загрузкой
            card.appendChild(createImageField({
                value: m.photo,
                placeholder: 'URL фото или загрузите →',
                onChange: (url) => { this.data.members[i].photo = url; },
                previewHeight: '80px',
            }));

            // Имя
            const nameInput = document.createElement('input');
            nameInput.value = m.name;
            nameInput.placeholder = 'Имя';
            nameInput.style.cssText = 'width:100%;border:none;font-weight:700;font-size:14px;text-align:center;outline:none;margin-bottom:4px;';
            nameInput.addEventListener('input', () => { this.data.members[i].name = nameInput.value; });

            // Должность
            const roleInput = document.createElement('input');
            roleInput.value = m.role;
            roleInput.placeholder = 'Должность';
            roleInput.style.cssText = 'width:100%;border:none;font-size:12px;text-align:center;outline:none;color:#9e1b32;margin-bottom:4px;';
            roleInput.addEventListener('input', () => { this.data.members[i].role = roleInput.value; });

            // Описание
            const bioInput = document.createElement('textarea');
            bioInput.value = m.bio || '';
            bioInput.placeholder = 'Краткое описание';
            bioInput.style.cssText = 'width:100%;border:none;font-size:11px;text-align:center;outline:none;resize:vertical;min-height:30px;color:#666;';
            bioInput.addEventListener('input', () => { this.data.members[i].bio = bioInput.value; });

            // Удалить
            const delBtn = document.createElement('button');
            delBtn.textContent = '✕ Удалить';
            delBtn.style.cssText = 'background:none;border:none;color:#ef4444;cursor:pointer;font-size:11px;margin-top:4px;';
            delBtn.addEventListener('click', () => { this.data.members.splice(i, 1); this._renderUI(); });

            card.append(nameInput, roleInput, bioInput, delBtn);
            grid.appendChild(card);
        });

        this.wrapper.appendChild(grid);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить участника';
        addBtn.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;width:100%;';
        addBtn.addEventListener('click', () => {
            this.data.members.push({ photo: '', name: '', role: '', bio: '' });
            this._renderUI();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() {
        return this.data;
    }
}
