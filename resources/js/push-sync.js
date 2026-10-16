import { post } from './lib/http';

// Пуш не умирает молча (16.10.2026): подписку браузер и iOS меняют сами (истекла, переустановили приложение,
// pushsubscriptionchange в sw.js), а сервер узнавал о ней только с тумблера в настройках — уведомления переставали
// приходить, и никто об этом не знал. При запуске: разрешение есть — подписка (или новая, если человек включал
// уведомления на этом телефоне) уходит на сервер раз в день. Выключил на другом устройстве — сервер не включит (sync).
export const vapidKey = (base64) => {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
};

const user = () => document.querySelector('meta[name="user-id"]')?.content ?? '';
const store = (key, value) => { try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch { return null; } return null; };

// Тумблер настроек отмечает, что на этом телефоне уведомления включали: пропавшую подписку можно вернуть самим.
export const markPush = (on) => store(`push:on:${user()}`, on ? '1' : '0');

export async function keepPush() {
    if (!user() || !('serviceWorker' in navigator) || !('PushManager' in window) || Notification.permission !== 'granted') return;
    try {
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            const key = document.querySelector('meta[name="vapid-key"]')?.content;
            if (store(`push:on:${user()}`) !== '1' || !key) return;
            sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKey(key) });
        }
        const mark = `${sub.endpoint}|${new Date().toDateString()}`;
        if (store(`push:sync:${user()}`) === mark) return;
        if (await post('/push/subscription', { ...sub.toJSON(), sync: true }, { quiet: true })) store(`push:sync:${user()}`, mark);
    } catch {}
}
