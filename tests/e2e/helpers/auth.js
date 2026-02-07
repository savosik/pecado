/**
 * Authentication helper for Playwright E2E tests
 */

/**
 * Login as admin user
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {Object} credentials - Optional credentials
 * @param {string} credentials.email - Admin email (default: admin@pecado.test)
 * @param {string} credentials.password - Admin password (default: password)
 */
export async function loginAsAdmin(page, credentials = {}) {
    const email = credentials.email || 'admin@pecado.test';
    const password = credentials.password || 'password';

    // Navigate to login page
    await page.goto('/login');

    // Wait for the DOM to load (not networkidle due to Vite HMR)
    await page.waitForLoadState('domcontentloaded');

    // Fill in credentials
    await page.fill('input[name="email"], input[type="email"]', email);
    await page.fill('input[name="password"], input[type="password"]', password);

    // Submit the form
    await page.click('button[type="submit"]');

    // Wait for redirect to admin area
    await page.waitForURL('**/admin**', { timeout: 10000 });

    // Wait for the admin page DOM to load
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Check if user is authenticated
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<boolean>} True if authenticated
 */
export async function isAuthenticated(page) {
    const url = page.url();
    return url.includes('/admin') && !url.includes('/login');
}
