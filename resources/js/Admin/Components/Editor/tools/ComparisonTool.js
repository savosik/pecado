export default class ComparisonTool {
    static get toolbox() {
        return {
            title: 'Сравнение',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            col1Title: data.col1Title || 'Другие',
            col2Title: data.col2Title || 'Pecado',
            rows: data.rows || [
                { feature: 'Опция 1', col1Text: 'Нет', col1Icon: 'cross', col2Text: 'Да', col2Icon: 'check' },
            ],
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;overflow-x:auto;';
        this._renderUI();
        return this.wrapper;
    }

    _renderIconsSelect(currentValue, onChange) {
        const sel = document.createElement('select');
        sel.style.cssText = 'padding:2px 4px;border:1px solid #d1d5db;border-radius:3px;font-size:12px;margin-right:4px;';
        [
            {v: 'none', l: '—'},
            {v: 'check', l: '✓ (Зелёный)'},
            {v: 'cross', l: '✕ (Красный)'},
            {v: 'text', l: 'Только текст'}
        ].forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.v;
            opt.textContent = o.l;
            if (o.v === currentValue) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => onChange(sel.value));
        return sel;
    }

    _renderUI() {
        this.wrapper.innerHTML = '';
        
        const table = document.createElement('table');
        table.style.cssText = 'width:100%;border-collapse:collapse;min-width:500px;font-size:13px;';
        
        // Header
        const thead = document.createElement('thead');
        const trH = document.createElement('tr');
        trH.innerHTML = '<th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;">Характеристика</th>';
        
        ['col1Title', 'col2Title'].forEach(col => {
            const th = document.createElement('th');
            th.style.cssText = 'padding:8px;border-bottom:2px solid #e5e7eb;text-align:center;';
            const input = document.createElement('input');
            input.value = this.data[col];
            input.style.cssText = 'width:100%;text-align:center;font-weight:700;border:1px solid transparent;background:transparent;outline:none;';
            input.addEventListener('input', () => { this.data[col] = input.value; });
            th.appendChild(input);
            trH.appendChild(th);
        });
        trH.innerHTML += '<th style="width:30px;border-bottom:2px solid #e5e7eb;"></th>';
        thead.appendChild(trH);
        table.appendChild(thead);

        // Body
        const tbody = document.createElement('tbody');
        this.data.rows.forEach((row, i) => {
            const tr = document.createElement('tr');
            
            // Feature
            const tdF = document.createElement('td');
            tdF.style.cssText = 'padding:8px;border-bottom:1px solid #e5e7eb;';
            const inF = document.createElement('input');
            inF.value = row.feature;
            inF.style.cssText = 'width:100%;border:1px solid #d1d5db;border-radius:4px;padding:4px 6px;font-size:12px;';
            inF.addEventListener('input', () => { this.data.rows[i].feature = inF.value; });
            tdF.appendChild(inF);

            // Col1
            const td1 = document.createElement('td');
            td1.style.cssText = 'padding:8px;border-bottom:1px solid #e5e7eb;text-align:center;white-space:nowrap;';
            td1.appendChild(this._renderIconsSelect(row.col1Icon, (v) => { this.data.rows[i].col1Icon = v; }));
            const in1 = document.createElement('input');
            in1.value = row.col1Text;
            in1.style.cssText = 'width:80px;border:1px solid #d1d5db;border-radius:4px;padding:4px;font-size:12px;';
            in1.addEventListener('input', () => { this.data.rows[i].col1Text = in1.value; });
            td1.appendChild(in1);

            // Col2
            const td2 = document.createElement('td');
            td2.style.cssText = 'padding:8px;border-bottom:1px solid #e5e7eb;text-align:center;white-space:nowrap;';
            td2.appendChild(this._renderIconsSelect(row.col2Icon, (v) => { this.data.rows[i].col2Icon = v; }));
            const in2 = document.createElement('input');
            in2.value = row.col2Text;
            in2.style.cssText = 'width:80px;border:1px solid #d1d5db;border-radius:4px;padding:4px;font-size:12px;';
            in2.addEventListener('input', () => { this.data.rows[i].col2Text = in2.value; });
            td2.appendChild(in2);

            // Del
            const tdD = document.createElement('td');
            tdD.style.cssText = 'padding:8px;border-bottom:1px solid #e5e7eb;text-align:center;';
            const delBtn = document.createElement('button');
            delBtn.textContent = '✕';
            delBtn.style.cssText = 'background:none;border:none;color:#ef4444;cursor:pointer;';
            delBtn.addEventListener('click', () => { this.data.rows.splice(i, 1); this._renderUI(); });
            tdD.appendChild(delBtn);

            tr.append(tdF, td1, td2, tdD);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        this.wrapper.appendChild(table);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить строку';
        addBtn.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;margin-top:12px;width:100%;';
        addBtn.addEventListener('click', () => {
            this.data.rows.push({ feature: '', col1Text: '', col1Icon: 'none', col2Text: '', col2Icon: 'none' });
            this._renderUI();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() {
        return this.data;
    }
}
