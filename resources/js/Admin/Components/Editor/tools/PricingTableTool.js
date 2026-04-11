export default class PricingTableTool {
    static get toolbox() {
        return {
            title: 'Тарифы',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h7"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            plans: data.plans || [
                { title: 'Базовый', price: '0 ₽', btnText: 'Выбрать', btnUrl: '', features: ['Фича 1', 'Фича 2'], isPopular: false },
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
        
        const grid = document.createElement('div');
        grid.style.cssText = 'display:flex;gap:16px;overflow-x:auto;padding-bottom:8px;margin-bottom:12px;';

        this.data.plans.forEach((plan, i) => {
            const card = document.createElement('div');
            card.style.cssText = `border:1px solid ${plan.isPopular ? '#9e1b32' : '#e5e7eb'};border-radius:8px;padding:16px;min-width:220px;flex:1;position:relative;`;

            const popLabel = document.createElement('label');
            popLabel.style.cssText = 'display:flex;align-items:center;gap:4px;font-size:11px;margin-bottom:8px;color:#9e1b32;font-weight:600;';
            const popCheck = document.createElement('input');
            popCheck.type = 'checkbox';
            popCheck.checked = plan.isPopular;
            popCheck.addEventListener('change', () => { this.data.plans[i].isPopular = popCheck.checked; this._renderUI(); });
            popLabel.append(popCheck, document.createTextNode('Выделить'));
            card.appendChild(popLabel);

            const titleInp = document.createElement('input');
            titleInp.value = plan.title;
            titleInp.placeholder = 'Название тарифа';
            titleInp.style.cssText = 'width:100%;font-size:16px;font-weight:700;border:1px solid #d1d5db;border-radius:4px;padding:4px;margin-bottom:8px;';
            titleInp.addEventListener('input', () => { this.data.plans[i].title = titleInp.value; });

            const priceInp = document.createElement('input');
            priceInp.value = plan.price;
            priceInp.placeholder = 'Цена (напр. 990 ₽/мес)';
            priceInp.style.cssText = 'width:100%;font-size:18px;font-weight:800;border:1px solid #d1d5db;border-radius:4px;padding:4px;margin-bottom:12px;color:#1a1a1a;';
            priceInp.addEventListener('input', () => { this.data.plans[i].price = priceInp.value; });

            const btnTextInp = document.createElement('input');
            btnTextInp.value = plan.btnText;
            btnTextInp.placeholder = 'Текст кнопки';
            btnTextInp.style.cssText = 'width:100%;font-size:12px;border:1px solid #d1d5db;border-radius:4px;padding:4px;margin-bottom:4px;';
            btnTextInp.addEventListener('input', () => { this.data.plans[i].btnText = btnTextInp.value; });

            const btnUrlInp = document.createElement('input');
            btnUrlInp.value = plan.btnUrl;
            btnUrlInp.placeholder = 'URL ссылки';
            btnUrlInp.style.cssText = 'width:100%;font-size:12px;border:1px solid #d1d5db;border-radius:4px;padding:4px;margin-bottom:12px;';
            btnUrlInp.addEventListener('input', () => { this.data.plans[i].btnUrl = btnUrlInp.value; });

            const featTitle = document.createElement('div');
            featTitle.textContent = 'Опции (каждая с новой строки):';
            featTitle.style.cssText = 'font-size:11px;color:#666;margin-bottom:4px;';

            const featText = document.createElement('textarea');
            featText.value = plan.features.join('\n');
            featText.placeholder = 'Опция 1\nОпция 2';
            featText.style.cssText = 'width:100%;font-size:12px;border:1px solid #d1d5db;border-radius:4px;padding:4px;min-height:80px;resize:vertical;';
            featText.addEventListener('input', () => { 
                this.data.plans[i].features = featText.value.split('\n').filter(s => s.trim() !== '');
            });

            const delBtn = document.createElement('button');
            delBtn.textContent = '✕ Удалить';
            delBtn.style.cssText = 'margin-top:12px;width:100%;padding:4px;background:#fee2e2;color:#ef4444;border:none;border-radius:4px;cursor:pointer;font-size:12px;';
            delBtn.addEventListener('click', () => { this.data.plans.splice(i, 1); this._renderUI(); });

            card.append(titleInp, priceInp, btnTextInp, btnUrlInp, featTitle, featText, delBtn);
            grid.appendChild(card);
        });

        this.wrapper.appendChild(grid);

        const addBtn = document.createElement('button');
        addBtn.textContent = '+ Добавить тариф';
        addBtn.style.cssText = 'padding:6px 16px;border:1px dashed #d1d5db;border-radius:4px;background:none;cursor:pointer;font-size:13px;color:#666;width:100%;';
        addBtn.addEventListener('click', () => {
            this.data.plans.push({ title: '', price: '', btnText: 'Выбрать', btnUrl: '', features: [], isPopular: false });
            this._renderUI();
        });
        this.wrapper.appendChild(addBtn);
    }

    save() {
        return this.data;
    }
}
