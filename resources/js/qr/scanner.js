// Сканер QR во весь экран — перенос QrCameraScanner из ZIGZAG, где его доводили до работы на старых телефонах.
// Что нельзя ломать (там это стоило недель):
//  • разрешение просим через `ideal`, не `min`: жёсткое требование уводит часть телефонов на сверхширокую линзу;
//  • кадр не режется — код ищется по всему кадру, рамка на экране только подсказка глазу;
//  • следующий кадр запрашивается ПЕРВОЙ строкой такта, до разбора; разбор один за раз, лишние кадры пропускаются;
//  • сторож перезапускает цикл, если requestVideoFrameCallback молча перестал приходить;
//  • автофокус просим всегда, даже если браузер про режимы фокуса молчит (иначе фикс-фокус и «расплывчато»);
//  • после 12 пустых кадров — снимок полного разрешения (ImageCapture.takePhoto) отдельной задачей с тайм-аутом:
//    «не читается» почти всегда значит «нерезко», а снимок идёт через настоящий автофокус;
//  • номер сессии отсекает хвосты прошлого открытия.
// Сверх ZIGZAG возвращена цепочка повторов getUserMedia при OverconstrainedError / NotFoundError.
import { decodeQrFrame, decodeQrNative, decodeQrPhoto, loadQrDetector } from './decoder';
import { createQrScanBudget, fitAnalysisSize } from './budget';
import { haptic } from './haptics';

const CAPTURE_WIDTH = 2560;
const CAPTURE_HEIGHT = 1440;
const PHOTO_RESCUE_AFTER_FRAMES = 12;
const PHOTO_TIMEOUT_MS = 2500;
const PHOTO_RESCUE_COOLDOWN_MS = 1500;
const PUMP_STALL_MS = 1500;
const PUMP_WATCHDOG_EVERY_MS = 1000;
const SEARCH_HINT_AFTER_MS = 6000;

function withTimeout(promise, ms) {
    return new Promise((resolve) => {
        const timer = setTimeout(() => resolve(null), ms);
        promise.then((v) => { clearTimeout(timer); resolve(v); }, () => { clearTimeout(timer); resolve(null); });
    });
}

function readCapabilities(track) {
    try { return track?.getCapabilities?.() ?? null; } catch { return null; }
}

async function requestAutofocus(track, capabilities) {
    if (!track?.applyConstraints) return;
    const advertised = capabilities?.focusMode ?? [];
    const modes = ['continuous', 'single-shot'].sort((a, b) => Number(advertised.includes(b)) - Number(advertised.includes(a)));
    for (const mode of modes) {
        try { await track.applyConstraints({ advanced: [{ focusMode: mode }] }); return; } catch {}
    }
}

async function listBackCameras() {
    if (!navigator.mediaDevices?.enumerateDevices) return [];
    try {
        const cameras = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
        const back = cameras.filter((d) => !/front|фронт|selfie|user/i.test(d.label));
        return back.length ? back : cameras;
    } catch {
        return [];
    }
}

function permissionHint() {
    const ua = navigator.userAgent;
    if (/iPhone|iPad|iPod/i.test(ua) && /Safari/i.test(ua) && !/CriOS|FxiOS|EdgiOS|YaBrowser/i.test(ua)) {
        return 'В Safari нажмите «аА» в адресной строке → «Настройки веб-сайта» → Камера → Разрешить';
    }
    if (/Chrome|CriOS|EdgA|YaBrowser|OPR/i.test(ua)) return 'Нажмите на значок слева от адреса сайта и разрешите камеру';
    return 'Разрешите камеру для этого сайта в настройках браузера';
}

function cameraError(error) {
    switch (error?.name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
        case 'SecurityError':
            return { message: 'Нет доступа к камере', hint: permissionHint() };
        case 'NotFoundError':
        case 'DevicesNotFoundError':
            return { message: 'Камера не найдена', hint: null };
        case 'NotReadableError':
        case 'TrackStartError':
            return { message: 'Камера занята', hint: 'Закройте другие приложения с камерой и попробуйте снова' };
        default:
            return { message: 'Камера не открылась', hint: null };
    }
}

// Ограничения по очереди: у части телефонов строгие отвергаются (OverconstrainedError), у части нет «задней» камеры.
function constraintChain(deviceId) {
    const size = { width: { ideal: CAPTURE_WIDTH }, height: { ideal: CAPTURE_HEIGHT } };
    if (deviceId) return [{ deviceId: { exact: deviceId }, ...size }, { facingMode: { ideal: 'environment' }, ...size }, true];
    return [{ facingMode: { ideal: 'environment' }, ...size }, { facingMode: { ideal: 'environment' } }, size, true];
}

async function openStream(deviceId) {
    let last;
    for (const video of constraintChain(deviceId)) {
        try {
            return await navigator.mediaDevices.getUserMedia({ video, audio: false });
        } catch (error) {
            last = error;
            if (!['OverconstrainedError', 'ConstraintNotSatisfiedError', 'NotFoundError', 'DevicesNotFoundError'].includes(error?.name)) throw error;
        }
    }
    throw last;
}

const ICONS = {
    x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    torch: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2h8l-1 6H9L8 2ZM9 8l1 14h4l1-14"/></svg>',
    lens: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    cameraOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M9.5 5h5l1.5 2h3a2 2 0 0 1 2 2v8.5M17.5 19H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h2M9.9 10.4a3 3 0 0 0 4.1 4.2"/></svg>',
};
// Отклик «нашёл»: окно вспыхивает лаймом с галкой, сканер закрывается после.
const FOUND_FLASH_MS = 320;

export class QrScanner {
    constructor({ onScan, onClose = () => {}, title = 'Сканировать QR' } = {}) {
        this.onScan = onScan;
        this.onClose = onClose;
        this.title = title;
        this.session = 0;
        this.generation = 0;
        this.budget = createQrScanBudget();
    }

    open() {
        if (this.root) return;
        this.build();
        this.lockPage(true);
        // «Назад» закрывает сканер, а не уходит со страницы — как у шторки (sheet.js): у записи страницы на время
        // снимается ключ turbo, иначе Turbo сделал бы restore-визит и форма выдачи потеряла бы введённое.
        const { turbo, ...rest } = history.state || {};
        this.pageTurbo = turbo;
        history.replaceState(rest, '');
        history.pushState({ ...rest, qrScanner: true }, '');
        this.onPop = () => {
            if (this.pageTurbo !== undefined) history.replaceState({ ...(history.state || {}), turbo: this.pageTurbo }, '');
            window.removeEventListener('popstate', this.onPop);
            this.close(true);
        };
        window.addEventListener('popstate', this.onPop);
        this.onKey = (e) => { if (e.key === 'Escape') this.close(); };
        window.addEventListener('keydown', this.onKey);
        this.onVisibility = () => { if (document.visibilityState === 'visible') this.acquireWakeLock(); };
        document.addEventListener('visibilitychange', this.onVisibility);
        this.acquireWakeLock();
        this.start();
    }

    close(fromHistory = false) {
        if (!this.root) return;
        this.stop();
        clearInterval(this.watchdog);
        clearTimeout(this.hintTimer);
        window.removeEventListener('keydown', this.onKey);
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.wakeLock?.release?.().catch(() => {});
        this.wakeLock = null;
        this.root.remove();
        this.root = null;
        this.lockPage(false);
        // Закрыли крестиком или кодом — снимаем свою запись; ключ turbo вернёт обработчик popstate.
        if (!fromHistory && history.state?.qrScanner) history.back();
        else window.removeEventListener('popstate', this.onPop);
        this.onClose();
    }

    build() {
        const root = document.createElement('div');
        root.className = 'qr-scanner';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-label', 'Сканер QR-кода');
        root.innerHTML = `
            <video class="qr-scanner-video" autoplay muted playsinline></video>
            <canvas hidden></canvas>
            <div class="qr-scanner-guide" aria-hidden="true"><i></i><i></i><i></i><i></i>${ICONS.check}</div>
            <div class="qr-scanner-top">
                <button type="button" class="qr-scanner-btn" data-act="close" aria-label="Закрыть">${ICONS.x}</button>
                <span class="qr-scanner-title"></span>
            </div>
            <p class="qr-scanner-hint">Открываю камеру…</p>
            <div class="qr-scanner-error" hidden>
                <span class="qr-scanner-error-icon">${ICONS.cameraOff}</span>
                <p class="qr-scanner-error-title"></p>
                <p class="qr-scanner-error-hint"></p>
                <div class="qr-scanner-error-acts">
                    <button type="button" class="btn btn-quiet" data-act="close">Закрыть</button>
                    <button type="button" class="btn btn-accent" data-act="retry">Повторить</button>
                </div>
            </div>
            <div class="qr-scanner-bottom">
                <label class="qr-scanner-tool" data-tool="torch" hidden><button type="button" class="qr-scanner-btn" data-act="torch" aria-label="Фонарик">${ICONS.torch}</button>Фонарик</label>
                <label class="qr-scanner-tool" data-tool="lens" hidden><button type="button" class="qr-scanner-btn" data-act="lens" aria-label="Другая камера">${ICONS.lens}</button>Камера</label>
            </div>`;
        root.querySelector('.qr-scanner-title').textContent = this.title;
        root.addEventListener('click', (e) => {
            const act = e.target.closest('[data-act]')?.dataset.act;
            if (act === 'close') this.close();
            if (act === 'retry') this.start();
            if (act === 'torch') this.toggleTorch();
            if (act === 'lens') this.switchLens();
        });
        // Щипок — зум: аппаратный, если камера умеет, иначе только превью (пикселей это не добавляет).
        this.pointers = new Map();
        root.addEventListener('pointerdown', (e) => {
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (this.pointers.size === 2) this.pinch = { start: this.pinchDistance(), zoom: this.zoom ?? 1 };
        });
        root.addEventListener('pointermove', (e) => {
            if (!this.pointers.has(e.pointerId)) return;
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            const d = this.pinchDistance();
            if (this.pinch && this.pointers.size >= 2 && this.pinch.start && d) this.applyZoom((this.pinch.zoom * d) / this.pinch.start);
        });
        const up = (e) => { this.pointers.delete(e.pointerId); if (this.pointers.size < 2) this.pinch = null; };
        root.addEventListener('pointerup', up);
        root.addEventListener('pointercancel', up);
        document.body.appendChild(root);
        this.root = root;
        this.video = root.querySelector('video');
        this.canvas = root.querySelector('canvas');
        this.hint = root.querySelector('.qr-scanner-hint');
    }

    lockPage(on) {
        const { body, documentElement } = document;
        if (on) {
            this.saved = [body.style.overflow, documentElement.style.overflow, body.style.touchAction];
            body.style.overflow = 'hidden';
            documentElement.style.overflow = 'hidden';
            body.style.touchAction = 'none';
        } else if (this.saved) {
            [body.style.overflow, documentElement.style.overflow, body.style.touchAction] = this.saved;
        }
    }

    async acquireWakeLock() {
        try { if (document.visibilityState === 'visible') this.wakeLock = await navigator.wakeLock?.request('screen'); } catch {}
    }

    showError(error) {
        const { message, hint } = cameraError(error);
        const box = this.root?.querySelector('.qr-scanner-error');
        if (!box) return;
        box.querySelector('.qr-scanner-error-title').textContent = message;
        box.querySelector('.qr-scanner-error-hint').textContent = hint ?? '';
        box.hidden = false;
        this.hint.hidden = true;
    }

    async start() {
        this.stop();
        const session = ++this.session;
        this.root.querySelector('.qr-scanner-error').hidden = true;
        this.hint.hidden = false;
        this.hint.textContent = 'Открываю камеру…';
        if (!navigator.mediaDevices?.getUserMedia) {
            this.showError({ name: window.isSecureContext ? 'NotFoundError' : 'SecurityError' });
            return;
        }
        const detectorReady = loadQrDetector(); // wasm греется параллельно с камерой
        let stream;
        try {
            stream = await openStream(this.deviceId);
        } catch (error) {
            if (session === this.session) this.showError(error);
            return;
        }
        if (session !== this.session || !this.root) { stream.getTracks().forEach((t) => t.stop()); return; }
        this.stream = stream;
        this.video.srcObject = stream;
        this.video.style.transform = '';
        await this.video.play().catch(() => {});
        const track = this.track();
        const capabilities = readCapabilities(track);
        await requestAutofocus(track, capabilities);
        this.root.querySelector('[data-tool="torch"]').hidden = !capabilities?.torch;
        this.torchOn = false;
        const zoom = capabilities?.zoom;
        this.zoomRange = zoom && zoom.max > zoom.min ? { min: zoom.min, max: zoom.max } : null;
        this.zoom = 1;
        this.cameras = await listBackCameras();
        this.root.querySelector('[data-tool="lens"]').hidden = this.cameras.length < 2;
        try { this.imageCapture = window.ImageCapture && track ? new window.ImageCapture(track) : null; } catch { this.imageCapture = null; }
        await detectorReady;
        if (session !== this.session) return;
        this.hint.textContent = 'Наведите на QR-код';
        clearTimeout(this.hintTimer);
        this.hintTimer = setTimeout(() => { if (this.hint) this.hint.textContent = 'Не читается? Поднесите ближе, уберите блик с экрана'; }, SEARCH_HINT_AFTER_MS);
        this.misses = 0;
        this.frames = 0;
        this.scanned = false;
        this.tickAt = 0;
        this.budget.reset();
        this.loop(session);
        clearInterval(this.watchdog);
        this.watchdog = setInterval(() => {
            if (this.scanned || this.tickAt === 0) return;
            if (Date.now() - this.tickAt > PUMP_STALL_MS) this.loop(this.session); // подача кадров встала — перезапуск
        }, PUMP_WATCHDOG_EVERY_MS);
    }

    stop() {
        this.session += 1;
        clearInterval(this.watchdog);
        this.stream?.getTracks().forEach((t) => t.stop());
        this.stream = null;
        if (this.video) this.video.srcObject = null;
        this.pointers?.clear();
        this.pinch = null;
    }

    track() {
        return this.stream?.getVideoTracks()[0] ?? null;
    }

    loop(session) {
        const generation = ++this.generation;
        const tick = () => {
            if (session !== this.session || this.scanned || generation !== this.generation) return;
            this.tickAt = Date.now();
            const video = this.video;
            // Следующий кадр — первой строкой, до разбора.
            if (typeof video?.requestVideoFrameCallback === 'function') video.requestVideoFrameCallback(tick);
            else requestAnimationFrame(tick);
            if (!video || video.videoWidth <= 0 || this.busy) return;
            this.busy = true;
            (async () => {
                const startedAt = performance.now();
                try {
                    let text = await decodeQrNative(video);
                    if (!text) {
                        const plan = this.budget.plan();
                        const size = fitAnalysisSize(video.videoWidth, video.videoHeight, plan.maxEdge);
                        if (this.canvas.width !== size.width || this.canvas.height !== size.height) {
                            this.canvas.width = size.width;
                            this.canvas.height = size.height;
                        }
                        const ctx = this.canvas.getContext('2d', { willReadFrequently: true });
                        if (ctx) {
                            ctx.drawImage(video, 0, 0, this.canvas.width, this.canvas.height);
                            const frame = ctx.getImageData(0, 0, this.canvas.width, this.canvas.height);
                            this.frames += 1;
                            text = await decodeQrFrame(frame, { thorough: this.frames % plan.thoroughEvery === 0, tryHarder: plan.tryHarder });
                            this.budget.observe(performance.now() - startedAt);
                        }
                    }
                    if (text) { this.deliver(session, text); return; }
                    this.misses += 1;
                    if (this.misses % PHOTO_RESCUE_AFTER_FRAMES === 0) this.photoRescue(session);
                } catch {
                    // воркер задумался или кадр битый — следующий такт попробует снова
                } finally {
                    this.busy = false;
                }
            })();
        };
        tick();
    }

    photoRescue(session) {
        const capture = this.imageCapture;
        if (!capture || this.photoBusy || Date.now() - (this.photoAt ?? 0) < PHOTO_RESCUE_COOLDOWN_MS) return;
        this.photoAt = Date.now();
        this.photoBusy = true;
        (async () => {
            try {
                const photo = await withTimeout(capture.takePhoto(), PHOTO_TIMEOUT_MS);
                if (!photo || session !== this.session || this.scanned) return;
                const text = await decodeQrPhoto(photo);
                if (text) this.deliver(session, text);
            } catch {} finally {
                this.photoBusy = false;
            }
        })();
    }

    deliver(session, text) {
        if (session !== this.session || this.scanned) return;
        this.scanned = true;
        haptic('tap');
        const onScan = this.onScan;
        const root = this.root;
        root?.classList.add('is-found');
        if (this.hint) this.hint.hidden = true;
        setTimeout(() => {
            if (this.root !== root) return; // закрыли во время вспышки — код не нужен
            this.close();
            onScan?.(text);
        }, FOUND_FLASH_MS);
    }

    async toggleTorch() {
        const track = this.track();
        if (!track) return;
        try {
            await track.applyConstraints({ advanced: [{ torch: !this.torchOn }] });
            this.torchOn = !this.torchOn;
            this.root.querySelector('[data-act="torch"]').classList.toggle('is-on', this.torchOn);
        } catch {}
    }

    switchLens() {
        if (this.cameras.length < 2) return;
        const current = this.track()?.getSettings?.().deviceId;
        const i = this.cameras.findIndex((c) => c.deviceId === (this.deviceId ?? current));
        this.deviceId = this.cameras[(i + 1) % this.cameras.length].deviceId;
        this.start();
    }

    pinchDistance() {
        const p = [...this.pointers.values()];
        return p.length < 2 ? 0 : Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
    }

    async applyZoom(next) {
        const range = this.zoomRange;
        const clamped = Math.max(1, Math.min(range ? range.max / range.min : 4, next));
        this.zoom = clamped;
        const track = this.track();
        if (!range || !track) {
            this.video.style.transform = clamped <= 1.02 ? '' : `scale(${clamped})`;
            return;
        }
        try { await track.applyConstraints({ advanced: [{ zoom: range.min * clamped }] }); } catch {}
    }
}
