export function validateTaxId(value, country) {
    const tax = String(value ?? '').trim();
    if (!tax) {
        return 'ИНН обязателен.';
    }

    switch (String(country ?? '').toUpperCase()) {
        case 'RU':
            return validateRu(tax);
        case 'BY':
            return validateBy(tax);
        case 'KZ':
            return validateKz(tax);
        default:
            return /^[A-Za-z0-9-]{4,32}$/.test(tax)
                ? null
                : 'Введите корректный ИНН/налоговый номер.';
    }
}

function validateRu(value) {
    if (!/^(\d{10}|\d{12})$/.test(value)) {
        return 'ИНН России должен содержать 10 цифр (юрлицо) или 12 цифр (ИП/физлицо).';
    }

    const digits = value.split('').map(Number);

    if (digits.length === 10) {
        const w = [2, 4, 10, 3, 5, 9, 4, 6, 8];
        const sum = w.reduce((acc, k, i) => acc + k * digits[i], 0);
        return ((sum % 11) % 10) === digits[9]
            ? null
            : 'Некорректная контрольная сумма ИНН России.';
    }

    const w1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
    const w2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
    const sum1 = w1.reduce((acc, k, i) => acc + k * digits[i], 0);
    const sum2 = w2.reduce((acc, k, i) => acc + k * digits[i], 0);
    const ok = ((sum1 % 11) % 10) === digits[10] && ((sum2 % 11) % 10) === digits[11];
    return ok ? null : 'Некорректная контрольная сумма ИНН России.';
}

function validateBy(value) {
    return /^\d{9}$/.test(value) ? null : 'УНП Беларуси должен содержать 9 цифр.';
}

function validateKz(value) {
    if (!/^\d{12}$/.test(value)) {
        return 'БИН/ИИН Казахстана должен содержать 12 цифр.';
    }

    const digits = value.split('').map(Number);
    const w1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
    let sum = 0;
    for (let i = 0; i < 11; i++) sum += w1[i] * digits[i];
    let check = sum % 11;

    if (check === 10) {
        const w2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];
        sum = 0;
        for (let i = 0; i < 11; i++) sum += w2[i] * digits[i];
        check = sum % 11;
        if (check === 10) {
            return 'Некорректная контрольная сумма БИН/ИИН Казахстана.';
        }
    }

    return check === digits[11]
        ? null
        : 'Некорректная контрольная сумма БИН/ИИН Казахстана.';
}
