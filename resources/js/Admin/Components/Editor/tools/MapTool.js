/**
 * MapTool — блок Яндекс Карты с точкой и масштабом.
 */
export default class MapTool {
    static get toolbox() {
        return {
            title: 'Карта',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            lat: data.lat ?? 55.7558,
            lng: data.lng ?? 37.6173,
            zoom: data.zoom ?? 12,
            marker: data.marker ?? '',
            height: data.height ?? 400,
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;';

        const title = document.createElement('div');
        title.textContent = '📍 Яндекс Карта';
        title.style.cssText = 'font-size:14px;font-weight:700;margin-bottom:12px;';
        this.wrapper.appendChild(title);

        const grid = document.createElement('div');
        grid.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;';

        grid.appendChild(this._field('Широта (lat)', 'lat', this.data.lat, 'number'));
        grid.appendChild(this._field('Долгота (lng)', 'lng', this.data.lng, 'number'));
        grid.appendChild(this._field('Масштаб (1–18)', 'zoom', this.data.zoom, 'number'));

        this.wrapper.appendChild(grid);

        const row2 = document.createElement('div');
        row2.style.cssText = 'display:grid;grid-template-columns:2fr 1fr;gap:8px;margin-bottom:12px;';

        row2.appendChild(this._field('Текст маркера', 'marker', this.data.marker, 'text'));
        row2.appendChild(this._field('Высота (px)', 'height', this.data.height, 'number'));

        this.wrapper.appendChild(row2);

        // Подсказка
        const hint = document.createElement('div');
        hint.innerHTML = '💡 Координаты можно скопировать из <a href="https://yandex.ru/maps" target="_blank" style="color:#9e1b32;">Яндекс Карт</a> — нажмите правой кнопкой → «Что здесь?»';
        hint.style.cssText = 'font-size:12px;color:#888;';
        this.wrapper.appendChild(hint);

        return this.wrapper;
    }

    _field(label, key, value, type) {
        const wrap = document.createElement('div');

        const lbl = document.createElement('label');
        lbl.textContent = label;
        lbl.style.cssText = 'display:block;font-size:11px;color:#666;margin-bottom:3px;font-weight:500;';

        const input = document.createElement('input');
        input.type = type;
        input.value = value;
        input.step = key === 'lat' || key === 'lng' ? '0.0001' : '1';
        input.style.cssText = 'width:100%;border:1px solid #d1d5db;border-radius:4px;padding:6px 8px;font-size:13px;box-sizing:border-box;';
        input.addEventListener('input', () => {
            if (type === 'number') {
                this.data[key] = key === 'zoom' || key === 'height'
                    ? parseInt(input.value) || this.data[key]
                    : parseFloat(input.value) || this.data[key];
            } else {
                this.data[key] = input.value;
            }
        });

        wrap.append(lbl, input);
        return wrap;
    }

    save() {
        return {
            lat: this.data.lat,
            lng: this.data.lng,
            zoom: Math.max(1, Math.min(18, this.data.zoom)),
            marker: this.data.marker,
            height: this.data.height,
        };
    }
}
