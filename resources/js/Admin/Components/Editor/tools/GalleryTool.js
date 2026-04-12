/**
 * GalleryTool — галерея из нескольких изображений.
 */
import { createImageField, uploadImage } from './editorUpload';

export default class GalleryTool {
    static get toolbox() {
        return {
            title: 'Галерея',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><rect x="0" y="0" width="7" height="7" rx="1" fill="currentColor"/><rect x="9" y="0" width="8" height="7" rx="1" fill="currentColor"/><rect x="0" y="9" width="17" height="6" rx="1" fill="currentColor"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            images: data.images || [],
            caption: data.caption || '',
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

        // Превью
        if (this.data.images.length > 0) {
            const grid = document.createElement('div');
            grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-bottom:8px;';
            this.data.images.forEach((img, idx) => {
                const imgEl = document.createElement('div');
                imgEl.style.cssText = 'position:relative;aspect-ratio:1;border-radius:6px;overflow:hidden;border:1px solid #e5e7eb;';
                imgEl.innerHTML = `<img src="${img.url}" style="width:100%;height:100%;object-fit:cover;"/>`;
                const removeBtn = document.createElement('button');
                removeBtn.textContent = '✕';
                removeBtn.type = 'button';
                removeBtn.style.cssText = 'position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:rgba(0,0,0,0.6);color:#fff;border:none;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;';
                removeBtn.addEventListener('click', () => {
                    this.data.images.splice(idx, 1);
                    this._rebuild();
                });
                imgEl.appendChild(removeBtn);
                grid.appendChild(imgEl);
            });
            this.wrapper.appendChild(grid);
        }

        // Добавить — URL ввод + загрузка файла
        const addRow = document.createElement('div');
        addRow.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;';

        const urlInput = document.createElement('input');
        urlInput.placeholder = 'URL изображения...';
        urlInput.style.cssText = 'flex:1;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;';
        addRow.appendChild(urlInput);

        // Кнопка загрузки файла
        const uploadBtn = document.createElement('label');
        uploadBtn.textContent = '📁';
        uploadBtn.title = 'Загрузить файл';
        uploadBtn.style.cssText = 'padding:8px 12px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:16px;display:flex;align-items:center;';
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*';
        fileInput.multiple = true;
        fileInput.style.display = 'none';
        fileInput.addEventListener('change', async () => {
            for (const file of fileInput.files) {
                try {
                    const url = await uploadImage(file);
                    this.data.images.push({ url, caption: '' });
                } catch (e) {
                    console.error(e);
                }
            }
            this._rebuild();
            fileInput.value = '';
        });
        uploadBtn.appendChild(fileInput);
        addRow.appendChild(uploadBtn);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить';
        addBtn.type = 'button';
        addBtn.style.cssText = 'padding:8px 16px;background:#7c3aed;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;';
        addBtn.addEventListener('click', () => {
            if (urlInput.value.trim()) {
                this.data.images.push({ url: urlInput.value.trim(), caption: '' });
                this._rebuild();
            }
        });
        addRow.appendChild(addBtn);
        this.wrapper.appendChild(addRow);

        // Подпись
        const captionInput = document.createElement('input');
        captionInput.value = this.data.caption;
        captionInput.placeholder = 'Общая подпись к галерее...';
        captionInput.style.cssText = 'width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;color:#666;';
        captionInput.addEventListener('input', () => { this.data.caption = captionInput.value; });
        this.wrapper.appendChild(captionInput);
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
