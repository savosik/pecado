/**
 * ReviewsTool — блок отзывов / testimonials.
 */
export default class ReviewsTool {
    static get toolbox() {
        return {
            title: 'Отзывы',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15"><text x="1" y="12" font-size="13" fill="currentColor">★★★</text></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            items: data.items && data.items.length > 0 ? data.items : [{ author: '', text: '', rating: 5 }],
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

        const title = document.createElement('div');
        title.textContent = '⭐ Отзывы';
        title.style.cssText = 'font-size:13px;color:#888;margin-bottom:8px;font-weight:500;';
        this.wrapper.appendChild(title);

        this.data.items.forEach((item, idx) => {
            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-bottom:8px;position:relative;';

            // Автор + рейтинг
            const topRow = document.createElement('div');
            topRow.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';

            const authorInput = document.createElement('input');
            authorInput.value = item.author;
            authorInput.placeholder = 'Автор отзыва...';
            authorInput.style.cssText = 'flex:1;padding:6px;border:1px solid #e5e7eb;border-radius:4px;font-size:13px;';
            authorInput.addEventListener('input', () => { item.author = authorInput.value; });
            topRow.appendChild(authorInput);

            const ratingSelect = document.createElement('select');
            ratingSelect.style.cssText = 'padding:6px;border:1px solid #e5e7eb;border-radius:4px;font-size:13px;';
            [5, 4, 3, 2, 1].forEach(r => {
                const opt = document.createElement('option');
                opt.value = r;
                opt.textContent = '★'.repeat(r);
                opt.selected = item.rating === r;
                ratingSelect.appendChild(opt);
            });
            ratingSelect.addEventListener('change', () => { item.rating = parseInt(ratingSelect.value); });
            topRow.appendChild(ratingSelect);

            card.appendChild(topRow);

            const textInput = document.createElement('textarea');
            textInput.value = item.text;
            textInput.placeholder = 'Текст отзыва...';
            textInput.style.cssText = 'width:100%;min-height:40px;padding:6px;border:1px solid #e5e7eb;border-radius:4px;resize:vertical;font-size:13px;line-height:1.5;font-family:inherit;';
            textInput.addEventListener('input', () => { item.text = textInput.value; });
            card.appendChild(textInput);

            if (this.data.items.length > 1) {
                const rmBtn = document.createElement('button');
                rmBtn.textContent = '✕';
                rmBtn.type = 'button';
                rmBtn.style.cssText = 'position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:#ef4444;color:#fff;border:none;cursor:pointer;font-size:11px;';
                rmBtn.addEventListener('click', () => {
                    this.data.items.splice(idx, 1);
                    this._rebuild();
                });
                card.appendChild(rmBtn);
            }

            this.wrapper.appendChild(card);
        });

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить отзыв';
        addBtn.type = 'button';
        addBtn.style.cssText = 'padding:6px 16px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:13px;color:#374151;';
        addBtn.addEventListener('click', () => {
            this.data.items.push({ author: '', text: '', rating: 5 });
            this._rebuild();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() { return this.data; }
    static get isReadOnlySupported() { return true; }
}
