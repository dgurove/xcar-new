// Иконки трёх приложений и экраны запуска iOS. node scripts/icons.mjs — один раз, результат лежит в git.
//
// Исходники — resources/icons/{site,crm,park}.svg: чёрный квадрат 1500×1500, знак белым и лаймом.
// Иконка отдаётся квадратом во весь холст без скруглений, бликов и теней — на iOS 26 стекло
// (Liquid Glass) кладёт сама система, на Android маску кладёт лаунчер. Для лаунчера отдельно
// maskable (знак в безопасной зоне 80 %), для тем Android 13 и бейджа уведомлений — белый силуэт.
// Ярлыки долгого нажатия — иконки кита из icon.blade.php. Экран запуска iOS — горизонтальный
// логотип на цвете полосы, под каждый iPhone своя картинка (iOS требует точный размер).
import sharp from 'sharp';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const at = (p) => new URL(p, import.meta.url).pathname;
const BLACK = '#000000';
const SURFACES = ['site', 'crm', 'park'];

// Ярлыки манифеста (PwaController) → иконки кита.
const SHORTCUT_ICONS = ['car', 'deal', 'bell', 'photo', 'cart', 'flag', 'park'];

// Экраны iPhone в точках и плотности; тот же список — в components/ui/startup.blade.php.
const SCREENS = [
    [440, 956, 3], [402, 874, 3], [430, 932, 3], [393, 852, 3], [428, 926, 3], [420, 912, 3],
    [390, 844, 3], [375, 812, 3], [414, 896, 3], [414, 896, 2], [414, 736, 3], [375, 667, 2],
];

// ICO из PNG: заголовок, записи и сами PNG (браузеры понимают PNG внутри ICO).
function ico(pngs) {
    const head = Buffer.alloc(6 + 16 * pngs.length);
    head.writeUInt16LE(1, 2);
    head.writeUInt16LE(pngs.length, 4);
    let offset = head.length;
    pngs.forEach(({ size, buf }, i) => {
        const e = 6 + 16 * i;
        head[e] = size % 256; head[e + 1] = size % 256;
        head.writeUInt16LE(1, e + 4); head.writeUInt16LE(32, e + 6);
        head.writeUInt32LE(buf.length, e + 8); head.writeUInt32LE(offset, e + 12);
        offset += buf.length;
    });
    return Buffer.concat([head, ...pngs.map((p) => p.buf)]);
}

// Заливки кита: блок $filled из icon.blade.php.
function kitIcons() {
    const blade = readFileSync(at('../resources/views/components/ui/icon.blade.php'), 'utf8');
    const block = blade.slice(blade.indexOf('$filled = ['));
    const paths = {};
    for (const [, name, d] of block.matchAll(/'([\w-]+)' => '([^']+)'/g)) paths[name] ??= d;
    return paths;
}

const kit = kitIcons();

for (const name of SURFACES) {
    const svg = readFileSync(at(`../resources/icons/${name}.svg`), 'utf8');
    const buf = Buffer.from(svg);
    const dir = at(`../public/pwa/${name}/`);
    mkdirSync(dir, { recursive: true });
    const out = (file) => dir + file;

    writeFileSync(out('favicon.svg'), svg);
    for (const size of [192, 512, 1024]) await sharp(buf).resize(size, size).png().toFile(out(`icon-${size}.png`));
    await sharp(buf).resize(180, 180).png().toFile(out('apple-touch-icon.png'));
    writeFileSync(out('favicon.ico'), ico(await Promise.all([16, 32].map(async (size) => ({ size, buf: await sharp(buf).resize(size, size).png().toBuffer() })))));

    // Maskable: знак обрезается по фону, вписывается диагональю в круг 80 % (410 из 512).
    const mark = await sharp(buf).trim().toBuffer({ resolveWithObject: true });
    const scale = 410 / Math.hypot(mark.info.width, mark.info.height);
    const inner = await sharp(mark.data).resize(Math.round(mark.info.width * scale), Math.round(mark.info.height * scale)).png().toBuffer();
    await sharp({ create: { width: 512, height: 512, channels: 4, background: BLACK } })
        .composite([{ input: inner, gravity: 'centre' }]).png().toFile(out('icon-maskable-512.png'));

    // Силуэт: без фона, всё белым.
    const mono = svg.replace(/<rect[^>]*\/>\s*/, '').replace(/fill="(white|black|#97BF0D)"/g, 'fill="white"');
    await sharp(Buffer.from(mono)).resize(512, 512).png().toFile(out('icon-mono-512.png'));

    // Ярлыки: заливка кита в безопасной зоне на чёрном.
    for (const icon of SHORTCUT_ICONS) {
        const d = kit[icon];
        if (!d) throw new Error(`нет заливки «${icon}» в icon.blade.php`);
        const s = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="96" height="96"><rect width="24" height="24" fill="${BLACK}"/><g transform="translate(5.4 5.4) scale(.55)"><path fill="white" d="${d}"/></g></svg>`;
        await sharp(Buffer.from(s)).png().toFile(out(`shortcut-${icon}.png`));
    }
}

// Знаки CRM и P для шапки: без фона, обрезаны по знаку, в двух цветах (как xcar.svg / xcar-white.svg).
for (const name of ['crm', 'park']) {
    const svg = readFileSync(at(`../resources/icons/${name}.svg`), 'utf8');
    const { info } = await sharp(Buffer.from(svg)).trim().toBuffer({ resolveWithObject: true });
    const box = `${-info.trimOffsetLeft} ${-info.trimOffsetTop} ${info.width} ${info.height}`;
    const cropped = svg.replace(/<rect[^>]*\/>\s*/, '').replace(/viewBox="[^"]*"/, `viewBox="${box}"`).replace(/width="\d+" height="\d+"/, `width="${info.width}" height="${info.height}"`);
    writeFileSync(at(`../public/images/${name}-white.svg`), cropped);
    writeFileSync(at(`../public/images/${name}.svg`), cropped.replaceAll('fill="white"', 'fill="black"'));
}

// Общий favicon.ico в корне — сайт.
writeFileSync(at('../public/favicon.ico'), readFileSync(at('../public/pwa/site/favicon.ico')));

// Экраны запуска: логотип шириной 40 % экрана по центру, светлый и тёмный — цвета полосы.
const splashDir = at('../public/pwa/splash/');
mkdirSync(splashDir, { recursive: true });
const logos = {
    '': { svg: readFileSync(at('../public/images/xcar.svg')), bg: '#ffffff' },
    '-dark': { svg: readFileSync(at('../public/images/xcar-white.svg')), bg: '#121212' },
};
for (const [w, h, r] of SCREENS) {
    const [W, H] = [w * r, h * r];
    for (const [suffix, { svg, bg }] of Object.entries(logos)) {
        const logo = await sharp(svg).resize(Math.round(W * 0.4)).png().toBuffer();
        await sharp({ create: { width: W, height: H, channels: 3, background: bg } })
            .composite([{ input: logo, gravity: 'centre' }]).png({ compressionLevel: 9 }).toFile(`${splashDir}splash-${W}x${H}${suffix}.png`);
    }
}
// Водяной знак для фото оффера с запретом шеринга (App\Media\Watermark кладёт его решёткой через GD,
// а GD не читает SVG). Двухтональный: светло-серое тело поверх тёмного размытого ореола — читается
// и на светлом, и на тёмном кузове, и его нельзя «вычесть» как один шаблон. Прозрачность здесь.
const WM = { width: 1200, pad: 16, body: '#DCDCDC', bodyAlpha: 0.38, haloBlur: 3, haloAlpha: 0.3 };
{
    const src = readFileSync(at('../public/images/xcar.svg'), 'utf8').replace(/width="\d+" height="\d+"/, `width="${WM.width}" height="${Math.round(WM.width * 512 / 2054)}"`);
    const shape = (fill, alpha) => src.replace(/fill="(black|#97BF0D)"/g, `fill="${fill}" fill-opacity="${alpha}"`);
    const size = { width: WM.width + WM.pad * 2, height: Math.round(WM.width * 512 / 2054) + WM.pad * 2 };
    const halo = await sharp(Buffer.from(shape('black', 1))).extend({ top: WM.pad, bottom: WM.pad, left: WM.pad, right: WM.pad, background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .blur(WM.haloBlur).ensureAlpha().linear([1, 1, 1, WM.haloAlpha], [0, 0, 0, 0]).png().toBuffer();
    const body = await sharp(Buffer.from(shape(WM.body, WM.bodyAlpha))).png().toBuffer();
    mkdirSync(at('../resources/images/'), { recursive: true });
    await sharp({ create: { ...size, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } } })
        .composite([{ input: halo, left: 0, top: 0 }, { input: body, left: WM.pad, top: WM.pad }])
        .png({ compressionLevel: 9 }).toFile(at('../resources/images/watermark.png'));
}

console.log('иконки, экраны запуска и водяной знак готовы');
