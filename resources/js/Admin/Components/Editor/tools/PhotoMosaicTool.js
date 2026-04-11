/**
 * PhotoMosaicTool — мозаичная сетка фотографий.
 */
export default class PhotoMosaicTool {
    static get toolbox() {
        return {
            title: 'Фотомозаика',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><rect x="0" y="0" width="5" height="7" rx="1" fill="currentColor"/><rect x="6" y="0" width="5" height="4" rx="1" fill="currentColor"/><rect x="12" y="0" width="5" height="7" rx="1" fill="currentColor"/><rect x="0" y="8" width="8" height="7" rx="1" fill="currentColor"/><rect x="9" y="5" width="8" height="10" rx="1" fill="currentColor"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            images: data.images || [],
            layout: data.layout || 'masonry',
        };
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px dashed #ccc;padding:12px;border-radius:8px;margin:8px 0;';
        this._rebuild();
        return this.wrapper;
    }

    _rebuild() {
        this.wrapper.innerHTML = '';

        const label = document.createElement('div');
        label.textContent = '🧩 Фотомозаика';
        label.style.cssText = 'font-size:13px;color:#888;margin-bottom:8px;font-weight:500;';
        this.wrapper.appendChild(label);

        if (this.data.images.length > 0) {
            const grid = document.createElement('div');
            grid.style.cssText = 'display:grid;grid-template-columns:repeat(3,1fr);gap:4px;margin-bottom:8px;';
            this.data.images.forEach((img, idx) => {
                const cell = document.createElement('div');
                cell.style.cssText = 'position:relative;aspect-ratio:1;border-radius:4px;overflow:hidden;border:1px solid #e5e7eb;';
                cell.innerHTML = `<img src="${img.url}" style="width:100%;height:100%;object-fit:cover;"/>`;
                const rm = document.createElement('button');
                rm.textContent = '✕';
                rm.type = 'button';
                rm.style.cssText = 'position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,0.6);color:#fff;border:none;cursor:pointer;font-size:10px;';
                rm.addEventListener('click', () => {
                    this.data.images.splice(idx, 1);
                    this._rebuild();
                });
                cell.appendChild(rm);
                grid.appendChild(cell);
            });
            this.wrapper.appendChild(grid);
        }

        const addRow = document.createElement('div');
        addRow.style.cssText = 'display:flex;gap:8px;';
        const urlIn = document.createElement('input');
        urlIn.placeholder = 'URL изображения...';
        urlIn.style.cssText = 'flex:1;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;';
        addRow.appendChild(urlIn);
        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить';
        addBtn.type = 'button';
        addBtn.style.cssText = 'padding:8px 16px;background:#7c3aed;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;';
        addBtn.addEventListener('click', () => {
            if (urlIn.value.trim()) {
                this.data.images.push({ url: urlIn.value.trim() });
                this._rebuild();
            }
        });
        addRow.appendChild(addBtn);
        this.wrapper.appendChild(addRow);
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
