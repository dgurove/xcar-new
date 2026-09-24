// Воркер разбора QR: кадр 2560×1440 с tryHarder — сотни миллисекунд, на слабом телефоне секунды; на главном потоке
// вместе с ним замирал весь экран. Инварианты (из ZIGZAG): на каждое задание есть ответ, даже если движок бросил;
// кадр не режется; адрес wasm приходит сообщением.
import { decodeBlob, decodeFrameData, prepareQrEngine } from './engine';

self.onmessage = (event) => {
    const message = event.data;
    if (message.type === 'init') {
        prepareQrEngine(message.wasmUrl).then(() => self.postMessage({ type: 'ready' }));
        return;
    }
    const startedAt = performance.now();
    const finish = (text) => self.postMessage({ type: 'result', jobId: message.jobId, text, ms: performance.now() - startedAt });
    if (message.type === 'frame') {
        decodeFrameData({ data: new Uint8ClampedArray(message.buffer), width: message.width, height: message.height },
            { thorough: message.thorough, tryHarder: message.tryHarder }).then(finish, () => finish(null));
    } else if (message.type === 'photo') {
        decodeBlob(message.blob).then(finish, () => finish(null));
    }
};
