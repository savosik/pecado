import puppeteer from 'puppeteer-core';
const BASE = 'http://loc.pecado.ru:8085';
const [,, orderId] = process.argv;
const browser = await puppeteer.launch({ executablePath: '/usr/bin/google-chrome', headless: 'new', args: ['--no-sandbox','--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
const wait = (ms) => new Promise(r => setTimeout(r, ms));
const clickText = async (text, sel = 'button, label, [role=radio], div, p, span') => page.evaluate((text, sel) => {
  const els = [...document.querySelectorAll(sel)].filter(e => e.textContent.trim().startsWith(text));
  const el = els.sort((a,b) => a.textContent.length - b.textContent.length)[0];
  if (!el) return false; el.scrollIntoView({ block: 'center' }); el.click(); return true;
}, text, sel);
const scrollTo = async (text, offset = 120) => page.evaluate((text, offset) => {
  const el = [...document.querySelectorAll('h1,h2,h3,p,span,div')].filter(e => e.textContent.trim().startsWith(text)).sort((a,b)=>a.textContent.length-b.textContent.length)[0];
  if (el) window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - offset); return !!el;
}, text, offset);
const shot = async (name) => { await wait(700); await page.screenshot({ path: `${process.env.SHOTS_DIR || 'shots'}/${name}.png` }); console.log('shot', name); };

await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
await page.type('input[type=email], input[name=email]', 'pickup-demo@demo.pecado.ru');
await page.type('input[type=password]', process.env.PICKUP_DEMO_PASSWORD || 'pickup-demo-2026');
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(()=>{}), page.keyboard.press('Enter')]);
await wait(1200);
console.log('cookie', await clickText('Принять', 'button'));

await page.goto(BASE + '/checkout', { waitUntil: 'networkidle2' }); await wait(1000);
console.log('pickup', await clickText('Самовывоз'));
await wait(500); await scrollTo('Самовывоз', 200); await shot('10-checkout-pickup');
console.log('reserve', await clickText('Поставьте в резерв'));
await wait(500); await scrollTo('Итого к оформлению', 60); await shot('11-checkout-reserve');

await page.goto(BASE + '/cabinet/reserves', { waitUntil: 'networkidle2' }); await wait(1000);
await scrollTo('Заказы в резерве', 20); await shot('20-reserves');
console.log('ship', await clickText('В отгрузку', 'button'));
await wait(900); await shot('21-reserves-confirm-dialog');
await page.keyboard.press('Escape');

await page.goto(BASE + '/cabinet/orders/' + orderId, { waitUntil: 'networkidle2' }); await wait(1000);
await page.evaluate(() => window.scrollTo(0, 0)); await shot('30-order-card');
await page.goto(BASE + '/cabinet/orders', { waitUntil: 'networkidle2' }); await wait(1000);
await shot('31-orders-list');
await browser.close();
