// Сколько работы отдавать движку на этом телефоне (ZIGZAG qr-scan-budget.ts). Отдаём по очереди: сначала частоту
// тщательного прохода, потом tryHarder, пиксели — последними и не ниже 1920 (от них дальность, с которой код читается).
export const MIN_ANALYSIS_EDGE = 1920;

const PLANS = {
    0: { maxEdge: null, thoroughEvery: 6, tryHarder: true },
    1: { maxEdge: null, thoroughEvery: 12, tryHarder: true },
    2: { maxEdge: null, thoroughEvery: 20, tryHarder: false },
    3: { maxEdge: MIN_ANALYSIS_EDGE, thoroughEvery: 20, tryHarder: false },
};
const LEVEL_UP_MS = { 0: 250, 1: 450, 2: 900, 3: Infinity };
const LEVEL_DOWN_MS = { 0: 0, 1: 150, 2: 300, 3: 600 };
const RAISE_AFTER = 3;
const LOWER_AFTER = 10; // вниз — только после долгой уверенности, иначе приступы нагрузки
const SMOOTHING = 0.25;

export function createQrScanBudget() {
    let level = 0;
    let average = null;
    let over = 0;
    let under = 0;
    const plan = () => ({ level, ...PLANS[level] });

    return {
        observe(ms) {
            average = average === null ? ms : average + SMOOTHING * (ms - average);
            if (average > LEVEL_UP_MS[level]) {
                over += 1; under = 0;
                if (over >= RAISE_AFTER && level < 3) { level += 1; over = 0; }
            } else if (average < LEVEL_DOWN_MS[level]) {
                under += 1; over = 0;
                if (under >= LOWER_AFTER && level > 0) { level -= 1; under = 0; }
            } else {
                over = 0; under = 0;
            }
            return plan();
        },
        plan,
        reset() { level = 0; average = null; over = 0; under = 0; },
    };
}

// Размер кадра анализа под план; вверх не масштабируем.
export function fitAnalysisSize(width, height, maxEdge) {
    const longEdge = Math.max(width, height);
    if (maxEdge === null || longEdge <= maxEdge) return { width, height };
    const scale = maxEdge / longEdge;
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}
