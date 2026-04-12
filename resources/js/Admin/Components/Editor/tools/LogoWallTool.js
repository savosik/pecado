import { uploadImage } from './editorUpload';

export default class LogoWallTool {
    static get toolbox() {
        return {
            title: 'Логотипы',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            title: data.title !== undefined ? data.title : 'Наши партнёры',
            marquee: data.marquee !== undefined ? data.marquee : true,
            logos: data.logos || [],
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
        
        const settingsRow = document.createElement('div');
        settingsRow.style.cssText = 'display:flex;gap:12px;align-items:center;margin-bottom:12px;font-size:13px;';
        
        const titleInput = document.createElement('input');
        titleInput.value = this.data.title;
        titleInput.placeholder = 'Заголовок блока';
        titleInput.style.cssText = 'border:1px solid #d1d5db;border-radius:4px;padding:4px 8px;flex:1;';
        titleInput.addEventListener('input', () => { this.data.title = titleInput.value; });
        
        const marqueeLabel = document.createElement('label');
        marqueeLabel.style.cssText = 'display:flex;align-items:center;gap:4px;cursor:pointer;';
        const marqueeCheck = document.createElement('input');
        marqueeCheck.type = 'checkbox';
        marqueeCheck.checked = this.data.marquee;
        marqueeCheck.addEventListener('change', () => { this.data.marquee = marqueeCheck.checked; });
        marqueeLabel.append(marqueeCheck, document.createTextNode('Бегущая строка'));
        
        settingsRow.append(titleInput, marqueeLabel);
        this.wrapper.appendChild(settingsRow);

        const list = document.createElement('div');
        list.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill, minmax(100px, 1fr));gap:8px;margin-bottom:12px;';

        this.data.logos.forEach((logo, i) => {
            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid #e5e7eb;border-radius:4px;padding:8px;text-align:center;position:relative;';

            if (logo.url) {
                const img = document.createElement('img');
                img.src = logo.url;
                img.style.cssText = 'width:100%;height:40px;object-fit:contain;margin-bottom:6px;';
                card.appendChild(img);
            } else {
                const ph = document.createElement('div');
                ph.style.cssText = 'width:100%;height:40px;background:#f3f4f6;font-size:10px;display:flex;align-items:center;justify-content:center;color:#999;margin-bottom:6px;';
                ph.textContent = 'Лого';
                card.appendChild(ph);
            }

            // URL + upload row
            const urlRow = document.createElement('div');
            urlRow.style.cssText = 'display:flex;gap:2px;';

            const urlInput = document.createElement('input');
            urlInput.value = logo.url;
            urlInput.placeholder = 'URL';
            urlInput.style.cssText = 'flex:1;font-size:10px;border:1px solid #d1d5db;border-radius:2px;padding:2px 4px;min-width:0;';
            urlInput.addEventListener('input', () => { this.data.logos[i].url = urlInput.value; });
            urlInput.addEventListener('blur', () => this._renderUI());

            const upBtn = document.createElement('label');
            upBtn.textContent = '📁';
            upBtn.title = 'Загрузить';
            upBtn.style.cssText = 'font-size:12px;cursor:pointer;padding:2px 4px;';
            const fi = document.createElement('input');
            fi.type = 'file';
            fi.accept = 'image/*';
            fi.style.display = 'none';
            fi.addEventListener('change', async () => {
                if (!fi.files[0]) return;
                try {
                    this.data.logos[i].url = await uploadImage(fi.files[0]);
                    this._renderUI();
                } catch (e) { alert('Ошибка загрузки'); }
                fi.value = '';
            });
            upBtn.appendChild(fi);

            urlRow.append(urlInput, upBtn);
            card.appendChild(urlRow);

            const del = document.createElement('button');
            del.textContent = '✕';
            del.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(255,0,0,0.7);color:#fff;border:none;border-radius:2px;cursor:pointer;font-size:9px;width:14px;height:14px;line-height:14px;padding:0;';
            del.addEventListener('click', () => { this.data.logos.splice(i, 1); this._renderUI(); });

            card.appendChild(del);
            list.appendChild(card);
        });

        this.wrapper.appendChild(list);

        // Add button with upload
        const addRow = document.createElement('div');
        addRow.style.cssText = 'display:flex;gap:8px;';

        const addUpload = document.createElement('label');
        addUpload.textContent = '📁 Загрузить логотипы';
        addUpload.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;flex:1;text-align:center;';
        const addFi = document.createElement('input');
        addFi.type = 'file';
        addFi.accept = 'image/*';
        addFi.multiple = true;
        addFi.style.display = 'none';
        addFi.addEventListener('change', async () => {
            for (const file of addFi.files) {
                try {
                    const url = await uploadImage(file);
                    this.data.logos.push({ url });
                } catch(e) { console.error(e); }
            }
            this._renderUI();
            addFi.value = '';
        });
        addUpload.appendChild(addFi);

        const addEmpty = document.createElement('button');
        addEmpty.textContent = '+ Пустой слот';
        addEmpty.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;';
        addEmpty.addEventListener('click', () => {
            this.data.logos.push({ url: '', alt: '' });
            this._renderUI();
        });

        addRow.append(addUpload, addEmpty);
        this.wrapper.appendChild(addRow);
    }

    save() {
        return {
            ...this.data,
            logos: this.data.logos.filter(l => l.url)
        };
    }
}
