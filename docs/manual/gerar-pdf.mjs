// Gera docs/manual-promocoes321.pdf a partir de docs/manual/manual.html.
// Uso: npm run manual   (precisa do Chromium do Playwright: npx playwright install chromium)
// As fontes vêm de public/build (rode npm run build antes, se a pasta não existir).
import { execSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const assets = join(root, 'public', 'build', 'assets');
const out = join(root, 'docs', 'manual-promocoes321.pdf');

const fonts = [
    ['Archivo', 400, 'archivo-400-normal'],
    ['Archivo', 600, 'archivo-600-normal'],
    ['Archivo', 700, 'archivo-700-normal'],
    ['Archivo Black', 400, 'archivo-black-400-normal'],
    ['IBM Plex Mono', 400, 'ibm-plex-mono-400-normal'],
    ['IBM Plex Mono', 600, 'ibm-plex-mono-600-normal'],
];
const files = existsSync(assets) ? readdirSync(assets) : [];
const fontFaces = fonts.map(([family, weight, prefix]) => {
    const file = files.find((f) => f.startsWith(prefix) && f.endsWith('.woff2'));
    return file ? `@font-face{font-family:'${family}';font-weight:${weight};src:url('${pathToFileURL(join(assets, file))}') format('woff2');}` : '';
}).join('');
if (!fontFaces) console.warn('Fontes do site não encontradas em public/build: usando fontes do sistema.');

const now = new Date();
const pad = (n) => String(n).padStart(2, '0');
const commit = execSync('git rev-parse --short HEAD', { cwd: root }).toString().trim();
const html = readFileSync(join(root, 'docs', 'manual', 'manual.html'), 'utf8')
    .replaceAll('{{DATA}}', `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()}`)
    .replaceAll('{{DATA_CURTA}}', `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${String(now.getFullYear()).slice(2)}`)
    .replaceAll('{{COMMIT}}', commit)
    .replace('</style>', `${fontFaces}</style>`);

const browser = await chromium.launch();
const page = await browser.newPage();
// Conteúdo carregado como arquivo local para que as fontes file:// sejam aceitas.
await page.goto(pathToFileURL(join(root, 'docs', 'manual', 'manual.html')).href);
await page.setContent(html, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);

const small = 'font-family:Archivo,Arial,sans-serif;font-size:7.5pt;color:#4A6066;width:100%;padding:0 18mm;display:flex;justify-content:space-between;';
await page.pdf({
    path: out,
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: `<div style="${small}"><span>Promoções 321 · Manual técnico e de operação</span><span>commit ${commit}</span></div>`,
    footerTemplate: `<div style="${small}"><span>Não contém senhas, chaves nem tokens</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`,
});
await browser.close();
console.log(`PDF gerado: ${out}`);
