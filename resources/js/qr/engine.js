// Движок разбора QR — zxing-cpp (zxing-wasm), вызываемый напрямую. Перенесён из ZIGZAG (apps/web/src/lib/
// qr-decode-engine.ts), где его отлаживали на старых телефонах: один и тот же код работает и в воркере, и на
// главном потоке. Ничего про DOM: кадр приходит объектом {data, width, height} — ровно то, что читает readBarcodes.
import { prepareZXingModule, readBarcodes } from 'zxing-wasm/reader';

// Быстрый такт: код ищется по всему кадру, без поворота и инверсии (они умножают работу на каждом слое пирамиды),
// первый найденный код.
export const FAST_OPTIONS = {
    formats: ['QRCode', 'MicroQRCode', 'rMQRCode'],
    tryHarder: true, tryRotate: false, tryInvert: false, maxNumberOfSymbols: 1, textMode: 'Plain',
};

// Тщательный такт — для кадра, который быстрым не дался: поворот, инверсия (тёмная тема экрана, блик), несколько кодов.
export const THOROUGH_OPTIONS = {
    formats: ['QRCode', 'MicroQRCode', 'rMQRCode'],
    tryHarder: true, tryRotate: true, tryInvert: true, maxNumberOfSymbols: 4, textMode: 'Plain',
};

let wasmReady = null;

// wasm — локальный файл (по умолчанию пакет тянет его с jsDelivr). Адрес приходит параметром: `?url` внутри воркера
// положил бы в сборку вторую копию на мегабайт.
export function prepareQrEngine(wasmUrl) {
    if (wasmReady === null) {
        wasmReady = (async () => {
            prepareZXingModule({ overrides: { locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : `${prefix}${path}`) } });
            // Прогрев: иначе первый кадр платит загрузкой модуля ровно тогда, когда человек навёл камеру.
            try { await readBarcodes({ data: new Uint8ClampedArray(4), width: 1, height: 1 }, FAST_OPTIONS); } catch {}
        })();
    }
    return wasmReady;
}

function firstText(results) {
    for (const r of results) {
        const text = (r.text ?? r.rawValue ?? '').trim();
        if (text) return text;
    }
    return null;
}

export async function decodeFrameData(frame, options = {}) {
    const base = options.thorough ? THOROUGH_OPTIONS : FAST_OPTIONS;
    const readerOptions = options.tryHarder === undefined ? base : { ...base, tryHarder: options.tryHarder };
    return firstText(await readBarcodes(frame, readerOptions));
}

// Снимок (спасательный кадр) — всегда тщательно.
export async function decodeBlob(photo) {
    return firstText(await readBarcodes(photo, THOROUGH_OPTIONS));
}
