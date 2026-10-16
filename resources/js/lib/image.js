// Кадр ужимается в телефоне до отправки (16.10.2026: лента фото делала это сама, чат слал оригиналы по 5–10 МБ).
// shrink(file, { max, quality }) → File: длинная сторона не больше max (1600 px), JPEG 0,85. Маленький и лёгкий кадр,
// не картинка, GIF, то, что телефон не декодирует (HEIC на Android) — как есть.
// Декодирование по одному кадру за раз: три полноразмерных снимка 48 Мп сразу iOS может не пережить и выгрузить
// приложение посреди загрузки.
let line = Promise.resolve();

export function shrink(file, { max = 1600, quality = 0.85 } = {}) {
    const run = line.then(() => decode(file, max, quality));
    line = run.catch(() => {});
    return run;
}

async function decode(file, max, quality) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
    try {
        const bitmap = await createImageBitmap(file);
        const long = Math.max(bitmap.width, bitmap.height);
        if (long <= max && file.size < 600_000) { bitmap.close(); return file; }
        const scale = Math.min(1, max / long);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', quality));
        canvas.width = canvas.height = 0;
        if (!blob) return file;
        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}
