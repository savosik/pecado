/**
 * ColumnsTool — блок с 2 или 3 колонками текста.
 */
export default class ColumnsTool {
    static get toolbox() {
        return {
            title: 'Колонки',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><rect x="0" y="0" width="7" height="15" rx="1" fill="currentColor"/><rect x="10" y="0" width="7" height="15" rx="1" fill="currentColor"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            columns: data.columns || ['', ''],
            layout: data.layout || '2',
        };
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px dashed #ccc;padding:12px;border-radius:8px;margin:8px 0;';

        this._renderColumns();
        return this.wrapper;
    }

    _renderColumns() {
        this.wrapper.innerHTML = '';

        // Переключатель 2/3 колонки
        const controls = document.createElement('div');
        controls.style.cssText = 'margin-bottom:8px;display:flex;gap:8px;align-items:center;';

        const label = document.createElement('span');
        label.textContent = 'Колонок:';
        label.style.cssText = 'font-size:13px;color:#888;';
        controls.appendChild(label);

        ['2', '3'].forEach(num => {
            const btn = document.createElement('button');
            btn.textContent = num;
            btn.type = 'button';
            btn.style.cssText = `padding:4px 12px;border-radius:4px;border:1px solid ${this.data.layout === num ? '#7c3aed' : '#ddd'};background:${this.data.layout === num ? '#7c3aed' : '#fff'};color:${this.data.layout === num ? '#fff' : '#333'};cursor:pointer;font-size:13px;`;
            btn.addEventListener('click', () => {
                this.data.layout = num;
                while (this.data.columns.length < parseInt(num)) this.data.columns.push('');
                this._renderColumns();
            });
            controls.appendChild(btn);
        });

        this.wrapper.appendChild(controls);

        const grid = document.createElement('div');
        const colCount = parseInt(this.data.layout);
        grid.style.cssText = `display:grid;grid-template-columns:repeat(${colCount},1fr);gap:12px;`;

        for (let i = 0; i < colCount; i++) {
            const textarea = document.createElement('textarea');
            textarea.value = this.data.columns[i] || '';
            textarea.placeholder = `Колонка ${i + 1}...`;
            textarea.style.cssText = 'width:100%;min-height:100px;padding:8px;border:1px solid #e5e7eb;border-radius:6px;resize:vertical;font-size:14px;line-height:1.6;font-family:inherit;';
            textarea.addEventListener('input', () => {
                this.data.columns[i] = textarea.value;
            });
            grid.appendChild(textarea);
        }

        this.wrapper.appendChild(grid);
    }

    save() {
        return {
            columns: this.data.columns.slice(0, parseInt(this.data.layout)),
            layout: this.data.layout,
        };
    }

    static get isReadOnlySupported() { return true; }
}
