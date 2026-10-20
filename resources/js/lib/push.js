import { post } from './http';
import { markPush, vapidKey } from '../push-sync';

// Пуши этого устройства — одним местом для тумблера настроек (push_controller) и окна предложения (push_offer_controller):
// умеет ли браузер, подписка, подписаться, пробный пуш, ответ «Пришло?» и где разрешить показ в этой системе.

export const supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

// На iPhone и iPad пуши — только у приложения с экрана «Домой» (iOS 16.4+), в Safari вкладкой их нет.
export const needsHomeScreen = () => /iPhone|iPad/.test(navigator.userAgent) && !navigator.standalone && !matchMedia('(display-mode: standalone)').matches;

// Воркер бывает не готов никогда (регистрацию сорвал сбой сети) — не ждём вечно.
export async function subscription() {
    if (!supported()) return null;
    const reg = await Promise.race([navigator.serviceWorker.ready, new Promise((r) => setTimeout(() => r(null), 4000))]);
    return reg ? reg.pushManager.getSubscription() : null;
}

// 'granted' и подписка на сервере; 'default' — браузер не спросил (Chrome прячет запрос в значок адресной строки);
// 'denied' — запрещено; 'failed' — подписка или сервер не прошли.
export async function subscribe() {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return { state: permission };
    try {
        const reg = await navigator.serviceWorker.ready;
        const key = document.querySelector('meta[name="vapid-key"]')?.content;
        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKey(key) });
        if (!(await post('/push/subscription', sub.toJSON()))) { await sub.unsubscribe().catch(() => {}); return { state: 'failed' }; }
        markPush(true);
        return { state: 'granted', sub };
    } catch {
        return { state: 'failed' };
    }
}

export const test = (sub) => post('/push/test', { endpoint: sub.endpoint }, { quiet: true });
export const confirm = (sub, came) => post('/push/confirm', { endpoint: sub?.endpoint, came: came ? 1 : 0 }, { quiet: true });

// Браузер — как его зовут в системных настройках.
export function browserName(agent = navigator.userAgent) {
    return /YaBrowser/.test(agent) ? 'Яндекс Браузер' : /Edg\//.test(agent) ? 'Microsoft Edge' : /Firefox\//.test(agent) ? 'Firefox' : 'Google Chrome';
}

// Где разрешить показ уведомлений браузера: шаги этой системы. Сайт не видит, что система их прячет, — только спросить.
export function fixSteps(agent = navigator.userAgent) {
    const b = browserName(agent);
    if (/iPhone|iPad/.test(agent)) return ['Настройки → Уведомления → XCar', 'Включите «Допуск уведомлений»'];
    if (/Android/.test(agent)) return ['Настройки телефона → Приложения', `${b} → Уведомления`, 'Разрешите уведомления'];
    if (/Mac OS X/.test(agent)) return ['Системные настройки → Уведомления', `${b}: разрешить, стиль «Предупреждения»`, 'Выключите «Фокусирование», если включено', `${b} должен быть открыт, можно свернуть`];
    if (/Windows/.test(agent)) return ['Параметры → Система → Уведомления', `${b}: включить`, 'Выключите «Не беспокоить»', `${b} должен быть открыт, можно свернуть`];
    return [`Разрешите уведомления для ${b} в настройках системы`];
}

// Одна строка для тесных мест (строка настроек и карточки).
export const hintFor = (agent) => fixSteps(agent).join('. ');
