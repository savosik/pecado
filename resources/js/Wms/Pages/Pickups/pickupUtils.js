// Общие помощники экрана выдачи (pick-07, pick-11).

let audioCtx = null;

/** Короткий сигнал: успех — высокий, ошибка — низкий двойной. У стойки на экран смотрят не всегда. */
export function beep(kind = 'ok') {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        const tones = kind === 'ok' ? [[880, 0, 0.12]] : [[220, 0, 0.18], [180, 0.22, 0.25]];
        tones.forEach(([freq, start, length]) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.frequency.value = freq;
            osc.type = kind === 'ok' ? 'sine' : 'square';
            gain.gain.value = 0.15;
            osc.connect(gain).connect(audioCtx.destination);
            osc.start(audioCtx.currentTime + start);
            osc.stop(audioCtx.currentTime + start + length);
        });
    } catch {
        /* звук — удобство, а не обязательная часть */
    }
}

/** «35 мин», «2 ч 10 мин», «со вчера», «3 дн». */
export function waitingText(iso) {
    if (!iso) return '';
    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (minutes < 60) return `${minutes} мин`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} ч ${minutes % 60} мин`;
    const days = Math.floor(hours / 24);
    return days === 1 ? 'со вчера' : `${days} дн`;
}

export function timeText(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const sameDay = date.toDateString() === new Date().toDateString();
    const time = date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    return sameDay ? time : `${date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })} ${time}`;
}

export function placesText(count) {
    const n = Number(count) || 0;
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return `${n} место`;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return `${n} места`;
    return `${n} мест`;
}

export function errorMessage(error, fallback = 'Не удалось выполнить действие') {
    const data = error?.response?.data;
    if (data?.message) return data.message;
    const first = data?.errors && Object.values(data.errors)[0];
    return (Array.isArray(first) ? first[0] : first) || fallback;
}
