// Клиент разбора QR (ZIGZAG qr-decoder.ts): сначала системный BarcodeDetector (Android, быстро), основной путь —
// zxing-wasm в воркере, запасной — он же на главном потоке. Один кадр в работе, на каждое задание — ответ.
import { decodeBlob, decodeFrameData, prepareQrEngine } from './engine';
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';

// Двенадцать секунд, а не четыре: тщательный разбор на слабом телефоне идёт секундами, и короткий срок снимал
// живой воркер — то есть возвращал заморозку экрана ровно там, ради чего воркер заводился.
const WORKER_JOB_TIMEOUT_MS = 12000;
const WORKER_READY_TIMEOUT_MS = 4000;
// Одно истечение — телефон задумался, два подряд — воркер не отвечает.
const WORKER_TIMEOUTS_BEFORE_RETIRE = 2;

export class QrWorkerTimeoutError extends Error {
    constructor() { super('Воркер не ответил за отведённое время'); this.name = 'QrWorkerTimeoutError'; }
}

let worker; // undefined — ещё не пробовали, null — воркера нет, живём на главном потоке
let workerReady = null;
let workerStarting = null; // обещание создания: два быстрых вызова не поднимут два воркера
let consecutiveTimeouts = 0;
let nextJobId = 1;
const pendingJobs = new Map();
let nativeDetector;

function settleJob(jobId, text) {
    const job = pendingJobs.get(jobId);
    if (!job) return;
    pendingJobs.delete(jobId);
    clearTimeout(job.timer);
    consecutiveTimeouts = 0;
    job.resolve(text);
}

function failJob(jobId, error) {
    const job = pendingJobs.get(jobId);
    if (!job) return;
    pendingJobs.delete(jobId);
    clearTimeout(job.timer);
    job.reject(error);
}

function retireWorker() {
    const dying = worker;
    worker = null;
    workerReady = null;
    workerStarting = null;
    consecutiveTimeouts = 0;
    dying?.terminate();
    for (const jobId of [...pendingJobs.keys()]) failJob(jobId, new QrWorkerTimeoutError());
}

async function startWorker() {
    if (typeof Worker === 'undefined') return null;
    try {
        const { default: DecodeWorker } = await import('./decode.worker.js?worker');
        const created = new DecodeWorker();
        created.onmessage = (event) => { if (event.data.type === 'result') settleJob(event.data.jobId, event.data.text); };
        created.onerror = () => retireWorker();
        workerReady = new Promise((resolve) => {
            const onReady = (event) => { if (event.data.type === 'ready') { created.removeEventListener('message', onReady); resolve(); } };
            created.addEventListener('message', onReady);
            setTimeout(resolve, WORKER_READY_TIMEOUT_MS); // прогрев не обязан успеть
        });
        created.postMessage({ type: 'init', wasmUrl });
        await workerReady;
        return created;
    } catch {
        return null;
    }
}

async function ensureWorker() {
    if (worker !== undefined) {
        if (worker && workerReady) await workerReady;
        return worker;
    }
    if (workerStarting === null) {
        workerStarting = startWorker().then((created) => {
            if (workerStarting === null) { created?.terminate(); return null; }
            worker = created;
            return created;
        });
    }
    return workerStarting;
}

// Прогрев движка при открытии сканера — параллельно с камерой.
export async function loadQrDetector() {
    const active = await ensureWorker();
    if (!active) await prepareQrEngine(wasmUrl);
}

function askWorker(active, request, transfer) {
    return new Promise((resolve, reject) => {
        if (worker !== active) { reject(new QrWorkerTimeoutError()); return; }
        const timer = setTimeout(() => {
            pendingJobs.delete(request.jobId);
            consecutiveTimeouts += 1;
            if (consecutiveTimeouts >= WORKER_TIMEOUTS_BEFORE_RETIRE) retireWorker();
            reject(new QrWorkerTimeoutError());
        }, WORKER_JOB_TIMEOUT_MS);
        pendingJobs.set(request.jobId, { resolve, reject, timer });
        active.postMessage(request, transfer);
    });
}

// Кадр в родном разрешении, без кропа: рамка на экране — только подсказка глазу. Буфер уезжает в воркер без
// копирования (передачей владения): копия кадра 2560×1440 — 14,7 МБ на каждый разбор.
export async function decodeQrFrame(frame, options = {}) {
    const active = await ensureWorker();
    if (!active) {
        await prepareQrEngine(wasmUrl);
        return decodeFrameData(frame, options);
    }
    const buffer = frame.data.buffer;
    return askWorker(active, { type: 'frame', jobId: nextJobId++, buffer, width: frame.width, height: frame.height,
        thorough: Boolean(options.thorough), tryHarder: options.tryHarder !== false }, [buffer]);
}

// Снимок полного разрешения с автофокусом (ImageCapture.takePhoto) — лекарство от нерезкого живого потока.
export async function decodeQrPhoto(photo) {
    const active = await ensureWorker();
    if (!active) {
        await prepareQrEngine(wasmUrl);
        return decodeBlob(photo);
    }
    return askWorker(active, { type: 'photo', jobId: nextJobId++, blob: photo }, []);
}

function resolveNativeDetector() {
    if (nativeDetector !== undefined) return nativeDetector;
    const Native = globalThis.BarcodeDetector;
    try { nativeDetector = Native ? new Native({ formats: ['qr_code'] }) : null; } catch { nativeDetector = null; }
    return nativeDetector;
}

// Системный детектор — только первым проходом: на iOS его нет, на Huawei без сервисов Google тоже.
export async function decodeQrNative(source) {
    const detector = resolveNativeDetector();
    if (!detector) return null;
    try {
        for (const r of await detector.detect(source)) {
            const text = (r.rawValue ?? '').trim();
            if (text) return text;
        }
        return null;
    } catch {
        return null; // падает, пока догружается модуль Play Services
    }
}
