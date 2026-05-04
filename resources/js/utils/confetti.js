/**
 * Короткий праздничный всплеск конфетти из точки (x, y) на canvas.
 *
 * Без observer'ов, без listener'ов, без зависимостей.
 * Один вызов — один canvas, который удаляется после анимации.
 *
 * @param {number} x - центр всплеска в координатах viewport
 * @param {number} y - центр всплеска в координатах viewport
 * @param {{ count?: number, duration?: number }} [opts]
 */
const COLORS = ['#fb923c', '#22c55e', '#fbbf24', '#3b82f6', '#a855f7', '#ec4899'];

export function burstConfetti(x, y, opts = {}) {
    if (typeof document === 'undefined') return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

    const count = opts.count ?? 70;
    const maxFrames = opts.duration ?? 90;

    const canvas = document.createElement('canvas');
    canvas.style.cssText = `
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        pointer-events: none;
        z-index: 10000;
    `;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = window.innerWidth * dpr;
    canvas.height = window.innerHeight * dpr;
    document.body.appendChild(canvas);

    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    const pieces = Array.from({ length: count }, () => {
        const angle = Math.random() * Math.PI * 2;
        const speed = 4 + Math.random() * 7;
        return {
            x, y,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed - 3,
            size: 5 + Math.random() * 6,
            rot: Math.random() * Math.PI,
            vrot: (Math.random() - 0.5) * 0.4,
            color: COLORS[Math.floor(Math.random() * COLORS.length)],
            life: 0,
        };
    });

    let frame = 0;
    const tick = () => {
        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
        let alive = false;
        for (const p of pieces) {
            if (p.life >= maxFrames) continue;
            p.life++;
            p.vy += 0.28;
            p.vx *= 0.99;
            p.x += p.vx;
            p.y += p.vy;
            p.rot += p.vrot;
            const opacity = 1 - p.life / maxFrames;
            ctx.save();
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rot);
            ctx.globalAlpha = Math.max(0, opacity);
            ctx.fillStyle = p.color;
            ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.55);
            ctx.restore();
            alive = true;
        }
        frame++;
        if (alive && frame < maxFrames) {
            requestAnimationFrame(tick);
        } else {
            canvas.remove();
        }
    };

    requestAnimationFrame(tick);
}
