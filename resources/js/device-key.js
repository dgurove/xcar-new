// Ключ этого браузера (07.10.2026, замок менеджера): ECDSA P-256 из WebCrypto. Закрытый создаётся неизвлекаемым —
// его не отдаёт даже свой же код, он лежит в IndexedDB и только подписывает. Сервер хранит открытый и проверяет им
// каждое подтверждение (Access\DeviceKey): скопированная на другое устройство cookie подтвердиться не может.
// IndexedDB нет или Safari её стёр — ключ создаётся заново, и браузер подтверждается как новое устройство.
const DB = 'xcar-device';
let pending = null;

function store(mode, run) {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(DB, 1);
        open.onupgradeneeded = () => open.result.createObjectStore('keys');
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
            const tx = open.result.transaction('keys', mode);
            const request = run(tx.objectStore('keys'));
            tx.oncomplete = () => { open.result.close(); resolve(request.result); };
            tx.onerror = () => { open.result.close(); reject(tx.error); };
        };
    });
}

async function load() {
    let pair = null;
    try { pair = await store('readonly', (s) => s.get('device')); } catch {}
    if (pair?.privateKey && pair?.publicKey) return pair;
    pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
    try { await store('readwrite', (s) => s.put(pair, 'device')); } catch {}
    return pair;
}

const base64url = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

// Подпись строки сервера ключом браузера: { key — открытый JWK, sig — r‖s base64url }.
export async function proof(nonce) {
    pending ??= load().catch((e) => { pending = null; throw e; });
    const { privateKey, publicKey } = await pending;
    const { kty, crv, x, y } = await crypto.subtle.exportKey('jwk', publicKey);
    const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, privateKey, new TextEncoder().encode(nonce));
    return { key: JSON.stringify({ kty, crv, x, y }), sig: base64url(sig) };
}
