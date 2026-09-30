// Сжатые копии сборки рядом с файлами (.br и .gz на максимуме): Caddy отдаёт их готовыми (`precompressed`),
// а не жмёт на лету при каждой отдаче. Запускается при сборке образа после `npm run build`.
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { brotliCompressSync, gzipSync, constants } from 'node:zlib';

const walk = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(join(dir, e.name)) : [join(dir, e.name)]));
let n = 0;
for (const file of walk(process.argv[2] ?? 'public/build')) {
    if (!/\.(js|mjs|css|svg|json|wasm|map|txt|bcmap|pfb|ttf)$/.test(file)) continue;
    const body = readFileSync(file);
    if (body.length < 1024) continue;
    writeFileSync(`${file}.br`, brotliCompressSync(body, { params: { [constants.BROTLI_PARAM_QUALITY]: 11, [constants.BROTLI_PARAM_SIZE_HINT]: body.length } }));
    writeFileSync(`${file}.gz`, gzipSync(body, { level: 9 }));
    n++;
}
console.log(`precompress: ${n} файлов`);
