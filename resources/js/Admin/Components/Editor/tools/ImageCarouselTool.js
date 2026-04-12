/**
 * ImageCarouselTool — карусель изображений.
 */
import { uploadImage } from './editorUpload';

export default class ImageCarouselTool {
    static get toolbox() {
        return {
            title: 'Карусель',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M9 22l3-3 3 3"/><circle cx="12" cy="12" r="1"/><circle cx="8" cy="12" r="1"/><circle cx="16" cy="12" r="1"/></svg>',
        };
    }

    constructor({ data, config, api }) {
        this.api = api;
        this.config = config || {};
        this.data = {
            slides: data.slides || [],
            autoplay: data.autoplay !== undefined ? data.autoplay : true,
            interval: data.interval || 5,
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

        // Settings
        const settings = document.createElement('div');
        settings.style.cssText = 'display:flex;gap:12px;align-items:center;margin-bottom:12px;font-size:13px;';

        const autoLabel = document.createElement('label');
        autoLabel.style.cssText = 'display:flex;align-items:center;gap:4px;cursor:pointer;';
        const autoCheck = document.createElement('input');
        autoCheck.type = 'checkbox';
        autoCheck.checked = this.data.autoplay;
        autoCheck.addEventListener('change', () => { this.data.autoplay = autoCheck.checked; });
        autoLabel.append(autoCheck, document.createTextNode('Автопрокрутка'));

        const intLabel = document.createElement('label');
        intLabel.style.cssText = 'display:flex;align-items:center;gap:4px;';
        intLabel.textContent = 'Интервал (с): ';
        const intInput = document.createElement('input');
        intInput.type = 'number';
        intInput.min = 1;
        intInput.max = 30;
        intInput.value = this.data.interval;
        intInput.style.cssText = 'width:50px;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;font-size:13px;';
        intInput.addEventListener('input', () => { this.data.interval = parseInt(intInput.value) || 5; });
        intLabel.appendChild(intInput);

        settings.append(autoLabel, intLabel);
        this.wrapper.appendChild(settings);

        // Slides
        const slideList = document.createElement('div');
        slideList.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-bottom:12px;';

        this.data.slides.forEach((slide, i) => {
            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;position:relative;';

            if (slide.url) {
                const img = document.createElement('img');
                img.src = slide.url;
                img.style.cssText = 'width:100%;aspect-ratio:16/9;object-fit:cover;display:block;';
                card.appendChild(img);
            } else {
                const placeholder = document.createElement('div');
                placeholder.style.cssText = 'width:100%;aspect-ratio:16/9;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px;';
                placeholder.textContent = 'Нет изображения';
                card.appendChild(placeholder);
            }

            // URL + upload row
            const urlRow = document.createElement('div');
            urlRow.style.cssText = 'display:flex;border-top:1px solid #e5e7eb;';

            const urlInput = document.createElement('input');
            urlInput.value = slide.url || '';
            urlInput.placeholder = 'URL';
            urlInput.style.cssText = 'flex:1;border:none;padding:6px 8px;font-size:11px;outline:none;min-width:0;';
            urlInput.addEventListener('input', () => { this.data.slides[i].url = urlInput.value; });
            urlInput.addEventListener('blur', () => this._renderUI());

            const upBtn = document.createElement('label');
            upBtn.textContent = '📁';
            upBtn.title = 'Загрузить';
            upBtn.style.cssText = 'padding:4px 8px;cursor:pointer;font-size:13px;border-left:1px solid #e5e7eb;display:flex;align-items:center;background:#f9fafb;';
            const fi = document.createElement('input');
            fi.type = 'file';
            fi.accept = 'image/*';
            fi.style.display = 'none';
            fi.addEventListener('change', async () => {
                if (!fi.files[0]) return;
                try {
                    this.data.slides[i].url = await uploadImage(fi.files[0]);
                    this._renderUI();
                } catch (e) { alert('Ошибка загрузки'); }
                fi.value = '';
            });
            upBtn.appendChild(fi);

            urlRow.append(urlInput, upBtn);
            card.appendChild(urlRow);

            // Caption
            const capInput = document.createElement('input');
            capInput.value = slide.caption || '';
            capInput.placeholder = 'Подпись';
            capInput.style.cssText = 'width:100%;border:none;border-top:1px solid #e5e7eb;padding:6px 8px;font-size:11px;outline:none;color:#666;';
            capInput.addEventListener('input', () => { this.data.slides[i].caption = capInput.value; });

            // Delete
            const del = document.createElement('button');
            del.textContent = '✕';
            del.style.cssText = 'position:absolute;top:4px;right:4px;background:rgba(0,0,0,0.5);color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:11px;line-height:20px;text-align:center;';
            del.addEventListener('click', () => { this.data.slides.splice(i, 1); this._renderUI(); });

            card.append(del, capInput);
            slideList.appendChild(card);
        });

        this.wrapper.appendChild(slideList);

        // Add slide — URL + upload
        const addRow = document.createElement('div');
        addRow.style.cssText = 'display:flex;gap:8px;';

        const addUpload = document.createElement('label');
        addUpload.textContent = '📁 Загрузить слайды';
        addUpload.style.cssText = 'padding:8px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;flex:1;text-align:center;';
        const addFi = document.createElement('input');
        addFi.type = 'file';
        addFi.accept = 'image/*';
        addFi.multiple = true;
        addFi.style.display = 'none';
        addFi.addEventListener('change', async () => {
            for (const file of addFi.files) {
                try {
                    const url = await uploadImage(file);
                    this.data.slides.push({ url, caption: '' });
                } catch (e) { console.error(e); }
            }
            this._renderUI();
            addFi.value = '';
        });
        addUpload.appendChild(addFi);

        const addEmpty = document.createElement('button');
        addEmpty.textContent = '+ Пустой слайд';
        addEmpty.style.cssText = 'padding:8px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;';
        addEmpty.addEventListener('click', () => {
            this.data.slides.push({ url: '', caption: '' });
            this._renderUI();
        });

        addRow.append(addUpload, addEmpty);
        this.wrapper.appendChild(addRow);
    }

    save() {
        return {
            ...this.data,
            slides: this.data.slides.filter(s => s.url),
        };
    }
}
