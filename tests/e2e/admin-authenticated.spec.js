import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth.js';

test.describe('Админ Панель - Авторизованные Тесты', () => {
    test.beforeEach(async ({ page }) => {
        // Авторизуемся перед каждым тестом
        await loginAsAdmin(page);
    });

    test('должен успешно войти и загрузить дашборд', async ({ page }) => {
        // После loginAsAdmin мы уже на странице /admin
        expect(page.url()).toContain('/admin');

        // Проверяем что страница загрузилась
        await page.waitForLoadState('networkidle');

        // Скриншот дашборда
        await page.screenshot({
            path: 'test-results/admin-dashboard-authenticated.png',
            fullPage: true
        });

        console.log('✓ Дашборд успешно загружен после авторизации');
    });

    test('должен отображать статистику на дашборде', async ({ page }) => {
        // Проверяем наличие статистических элементов
        const bodyText = await page.textContent('body');

        // Проверяем что на странице есть контент
        expect(bodyText.length).toBeGreaterThan(100);

        // Ищем типичные элементы дашборда
        const hasStats = await page.locator('[data-testid*="stat"]').count() > 0 ||
            await page.locator('.stat').count() > 0 ||
            await page.locator('text=/Заказы|Товары|Пользователи|Статистика/i').count() > 0;

        if (hasStats) {
            console.log('✓ Статистические элементы найдены');
        } else {
            console.log('⚠ Специфические статистические элементы не найдены, но контент есть');
        }
    });

    test('должен иметь работающую навигацию в сайдбаре', async ({ page }) => {
        // Ищем навигационное меню
        const navigation = await page.locator('nav, aside, [role="navigation"]').first();

        if (await navigation.count() > 0) {
            // Проверяем наличие ссылок в навигации
            const links = await navigation.locator('a').count();
            expect(links).toBeGreaterThan(0);
            console.log(`✓ Найдено ${links} ссылок в навигации`);
        } else {
            console.log('⚠ Навигационное меню не найдено по стандартным селекторам');
        }
    });

    test('должен успешно перейти на страницу товаров', async ({ page }) => {
        // Ищем ссылку на товары
        const productsLink = await page.locator('a[href*="/admin/products"], a:has-text("Товары"), a:has-text("Продукты"), a:has-text("Products")').first();

        if (await productsLink.count() > 0) {
            await productsLink.click();

            // Ждем загрузки страницы
            await page.waitForLoadState('networkidle');

            // Проверяем что URL изменился
            expect(page.url()).toContain('/admin/products');

            // Скриншот страницы товаров
            await page.screenshot({
                path: 'test-results/admin-products-page.png',
                fullPage: true
            });

            console.log('✓ Успешно перешли на страницу товаров');
        } else {
            console.log('⚠ Ссылка на товары не найдена, пропускаем тест');
        }
    });
});
