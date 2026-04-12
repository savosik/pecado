/**
 * editorUpload.js — утилита загрузки изображений для кастомных Editor.js блоков.
 *
 * Создаёт обёртку с полем URL + кнопкой «Загрузить» (file upload).
 * Использует тот же endpoint, что и стандартный блок image: /admin/api/upload-image
 */

/**
 * Получить XSRF-токен из cookie.
 */
function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Загрузить файл на сервер.
 * @param {File} file
 * @returns {Promise<string>} URL загруженного файла
 */
export async function uploadImage(file) {
    const formData = new FormData();
    formData.append('image', file);

    const res = await fetch('/admin/api/upload-image', {
        method: 'POST',
        headers: {
            'X-XSRF-TOKEN': getCsrfToken(),
            Accept: 'application/json',
        },
        body: formData,
    });

    const json = await res.json();

    if (json.success && json.file?.url) {
        return json.file.url;
    }
    throw new Error('Ошибка загрузки');
}

/**
 * Создать поле ввода URL + кнопку загрузки файла.
 *
 * @param {Object} options
 * @param {string} options.value — текущий URL
 * @param {string} [options.placeholder] — placeholder для инпута
 * @param {function} options.onChange — коллбек при изменении URL
 * @param {string} [options.previewHeight] — высота превью ('60px')
 * @returns {HTMLElement} контейнер с инпутом и кнопкой
 */
export function createImageField({ value, placeholder, onChange, previewHeight }) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin-bottom:8px;';

    // Превью
    const preview = document.createElement('div');
    preview.style.cssText = `margin-bottom:6px;border-radius:4px;overflow:hidden;${value ? '' : 'display:none;'}`;
    if (value) {
        const img = document.createElement('img');
        img.src = value;
        img.style.cssText = `width:100%;height:${previewHeight || '80px'};object-fit:cover;display:block;`;
        preview.appendChild(img);
    }
    wrap.appendChild(preview);

    // Ряд: инпут + кнопка
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:6px;';

    const input = document.createElement('input');
    input.type = 'text';
    input.value = value || '';
    input.placeholder = placeholder || 'URL изображения или загрузите файл →';
    input.style.cssText = 'flex:1;border:1px solid #d1d5db;border-radius:4px;padding:6px 8px;font-size:12px;';
    input.addEventListener('input', () => {
        onChange(input.value);
        _updatePreview(preview, input.value, previewHeight);
    });

    const uploadBtn = document.createElement('label');
    uploadBtn.textContent = '📁';
    uploadBtn.title = 'Загрузить файл';
    uploadBtn.style.cssText = 'padding:6px 10px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;font-size:14px;display:flex;align-items:center;user-select:none;transition:background 0.15s;';
    uploadBtn.addEventListener('mouseenter', () => { uploadBtn.style.background = '#e5e7eb'; });
    uploadBtn.addEventListener('mouseleave', () => { uploadBtn.style.background = '#f3f4f6'; });

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.style.display = 'none';
    fileInput.addEventListener('change', async () => {
        if (!fileInput.files[0]) return;

        uploadBtn.textContent = '⏳';
        uploadBtn.style.pointerEvents = 'none';

        try {
            const url = await uploadImage(fileInput.files[0]);
            input.value = url;
            onChange(url);
            _updatePreview(preview, url, previewHeight);
        } catch (err) {
            console.error('Upload failed:', err);
            alert('Ошибка загрузки изображения');
        } finally {
            uploadBtn.textContent = '📁';
            uploadBtn.style.pointerEvents = 'auto';
            fileInput.value = '';
        }
    });

    uploadBtn.appendChild(fileInput);
    row.append(input, uploadBtn);
    wrap.appendChild(row);

    return wrap;
}

function _updatePreview(previewEl, url, height) {
    if (url) {
        previewEl.style.display = '';
        previewEl.innerHTML = '';
        const img = document.createElement('img');
        img.src = url;
        img.style.cssText = `width:100%;height:${height || '80px'};object-fit:cover;display:block;`;
        previewEl.appendChild(img);
    } else {
        previewEl.style.display = 'none';
    }
}
