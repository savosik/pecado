/**
 * TabsTool — блок с табами (вкладками).
 * Каждый таб: заголовок + HTML-контент. Полностью адаптивный.
 */
export default class TabsTool {
    static get toolbox() {
        return {
            title: 'Табы',
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M8 6v4"/><path d="M14 6v4"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = {
            tabs: data.tabs || [
                { title: 'Вкладка 1', content: 'Содержимое первой вкладки' },
                { title: 'Вкладка 2', content: 'Содержимое второй вкладки' },
            ],
        };
        this.activeTab = 0;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:16px;';
        this._renderUI();
        return this.wrapper;
    }

    _renderUI() {
        this.wrapper.innerHTML = '';

        // Tab buttons
        const tabBar = document.createElement('div');
        tabBar.style.cssText = 'display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:12px;';

        this.data.tabs.forEach((tab, i) => {
            const tabBtn = document.createElement('div');
            tabBtn.style.cssText = `padding:8px 16px;cursor:pointer;font-size:13px;font-weight:600;border-bottom:2px solid ${this.activeTab === i ? '#9e1b32' : 'transparent'};margin-bottom:-2px;color:${this.activeTab === i ? '#9e1b32' : '#666'};display:flex;align-items:center;gap:6px;`;

            const titleInput = document.createElement('input');
            titleInput.value = tab.title;
            titleInput.style.cssText = `border:none;outline:none;font-weight:600;font-size:13px;width:${Math.max(60, tab.title.length * 8)}px;color:inherit;background:transparent;`;
            titleInput.addEventListener('input', () => {
                this.data.tabs[i].title = titleInput.value;
                titleInput.style.width = Math.max(60, titleInput.value.length * 8) + 'px';
            });
            titleInput.addEventListener('focus', () => { this.activeTab = i; this._renderUI(); });

            const delBtn = document.createElement('button');
            delBtn.textContent = '✕';
            delBtn.style.cssText = 'background:none;border:none;color:#ef4444;cursor:pointer;font-size:12px;';
            delBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.data.tabs.length > 1) {
                    this.data.tabs.splice(i, 1);
                    if (this.activeTab >= this.data.tabs.length) this.activeTab = this.data.tabs.length - 1;
                    this._renderUI();
                }
            });

            tabBtn.addEventListener('click', () => { this.activeTab = i; this._renderUI(); });
            tabBtn.append(titleInput, delBtn);
            tabBar.appendChild(tabBtn);
        });

        // Add tab button
        const addTab = document.createElement('button');
        addTab.textContent = '+';
        addTab.style.cssText = 'padding:8px 14px;border:none;background:none;cursor:pointer;font-size:16px;color:#999;margin-bottom:-2px;';
        addTab.addEventListener('click', () => {
            this.data.tabs.push({ title: `Вкладка ${this.data.tabs.length + 1}`, content: '' });
            this.activeTab = this.data.tabs.length - 1;
            this._renderUI();
        });
        tabBar.appendChild(addTab);
        this.wrapper.appendChild(tabBar);

        // Tab content
        const contentArea = document.createElement('textarea');
        contentArea.value = this.data.tabs[this.activeTab]?.content || '';
        contentArea.placeholder = 'Содержимое вкладки (HTML поддерживается)';
        contentArea.style.cssText = 'width:100%;min-height:100px;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:14px;resize:vertical;outline:none;line-height:1.6;';
        contentArea.addEventListener('input', () => {
            this.data.tabs[this.activeTab].content = contentArea.value;
        });
        this.wrapper.appendChild(contentArea);
    }

    save() {
        return this.data;
    }
}
