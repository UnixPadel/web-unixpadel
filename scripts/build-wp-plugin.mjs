// Packs the site as a WordPress plugin ZIP (Plugins → Add New → Upload Plugin), for hosts where only the WP admin
// is available. Builds into dist-wp/ with /_astro served from the plugin folder, turns redirects.json into real 301s
// (redirects.php), checks the reference shells against the plugin (src/lib/upx-contract.mjs) and writes
// wp-plugin-build/unixpadel-static-<version>.zip.
// Usage: npm run build:wp                     (build + pack)
//        npm run build:wp -- --skip-build     (pack the existing dist-wp/)
//        npm run build:wp -- --unpacked       (plugin folder wp-plugin-build/unixpadel-static/ instead of a ZIP, for a
//                                              local WordPress; set UPX_ASSETS_ORIGIN=http://127.0.0.1:9400 to load
//                                              CSS/JS from it)
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { zipSync } from 'fflate';
import { BAND_FIELDS, BAND_ITEMS, BLOCKS, CONTRACT, HOME_REFS, M, SLOTS, T, TEMPLATES, VISIBLE, bandToken, langToken } from '../src/lib/upx-contract.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT_DIR = path.join(ROOT, 'dist-wp');
const BUILD_DIR = path.join(ROOT, 'wp-plugin-build');
const SLUG = 'unixpadel-static';
const ASSETS_ORIGIN = process.env.UPX_ASSETS_ORIGIN || 'https://www.unixpadel.com';
const ASSETS_PREFIX = `${ASSETS_ORIGIN}/wp-content/plugins/${SLUG}/dist`;
const UNPACKED = process.argv.includes('--unpacked');

if (ASSETS_ORIGIN !== 'https://www.unixpadel.com' && !UNPACKED) {
  console.error('UPX_ASSETS_ORIGIN is for local test builds (--unpacked); the ZIP must load its assets from www.unixpadel.com.');
  process.exit(1);
}

const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
const redirects = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/data/redirects.json'), 'utf8'));

if (!process.argv.includes('--skip-build')) {
  const astro = path.join(ROOT, 'node_modules/astro/bin/astro.mjs');
  const run = spawnSync(process.execPath, [astro, 'build', '--outDir', OUT_DIR], {
    cwd: ROOT,
    stdio: 'inherit',
    env: { ...process.env, ASSETS_PREFIX },
  });
  if (run.status !== 0) process.exit(run.status ?? 1);
}

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
  e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]);

// Astro's meta-refresh pages for redirects are replaced by PHP 301s. 404.html is served by the plugin for English misses.
const skip = new Set(Object.keys(redirects).map((from) => `${from.slice(1)}index.html`));
const SHELL = /^(?:[a-z]{2}\/)?upx-shell\//;
const files = {};
const problems = [];
let pages = 0;
for (const abs of walk(OUT_DIR)) {
  const rel = path.relative(OUT_DIR, abs).split(path.sep).join('/');
  if (skip.has(rel)) continue;
  const data = fs.readFileSync(abs);
  if (/\.(html|css|js)$/.test(rel)) {
    const body = data.toString('utf8');
    // Every /_astro reference must carry the plugin prefix, or CSS/fonts/scripts 404 on the WordPress host
    // (JS too: a Vite preload list or a dynamic import() would carry root-relative chunk paths).
    if (/(["'(\s,=])\/_astro\//.test(body)) problems.push(rel);
    if (rel.endsWith('index.html') && !SHELL.test(rel)) pages++;
  }
  files[`${SLUG}/dist/${rel}`] = data;
}
if (problems.length) {
  console.error(`Unprefixed /_astro/ references in ${problems.length} file(s), e.g. ${problems.slice(0, 5).join(', ')}`);
  process.exit(1);
}

// Plugin PHP and admin assets (wp-plugin/includes, wp-plugin/assets).
const pluginFiles = {};
for (const dir of ['includes', 'assets']) {
  const base = path.join(ROOT, 'wp-plugin', dir);
  if (!fs.existsSync(base)) continue;
  for (const abs of walk(base)) pluginFiles[`${dir}/${path.relative(base, abs).split(path.sep).join('/')}`] = fs.readFileSync(abs);
}

// ---------- Contract between the reference shells and the plugin (src/lib/upx-contract.mjs) ----------
// The plugin fills tokens and markers it expects in the build output; a missing or renamed one would break pages
// silently on the live site, so the build stops here instead.
const LANGS = ['tr', 'de', 'fr', 'ar', 'ru']; // keep in sync with src/i18n/index.ts
const ALL_LANGS = ['en', ...LANGS];
const contract = [];
const expect = (ok, msg) => {
  if (!ok) contract.push(msg);
};
const read = (rel) => {
  const file = path.join(OUT_DIR, rel);
  return fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null;
};
const at = (lang, rel) => (lang === 'en' ? rel : `${lang}/${rel}`);
const count = (s, needle) => s.split(needle).length - 1;
const soleContent = (html, token) => new RegExp(`<([a-z][a-z0-9]*)\\b[^>]*>${token}</\\1>`).test(html);
const templates = (html) => Object.fromEntries([...html.matchAll(/<template\s+data-upx="([a-z-]+)"[^>]*>([\s\S]*?)<\/template>/g)].map((m) => [m[1], m[2]]));
const articleClass = (html) => /<article class="([^"]*)"/.exec(html)?.[1];

const CARD_MARK = /<!--upx:card (\/[^ >]*)-->/g;
const NEWS_MARK = /<!--upx:news (\/[^ >]*)-->/g;
const cardTokens = Object.entries(T).filter(([k]) => /^[CN]_/.test(k)).map(([, v]) => v);
const bandTokens = BAND_FIELDS.flatMap((f) => Array.from({ length: BAND_ITEMS }, (_, i) => bandToken(f, i)));
const pageTokens = [...Object.entries(T).filter(([k]) => !/^[CN]_/.test(k)).map(([, v]) => v), ...bandTokens];
const allTokens = [...Object.values(T), ...bandTokens, ...ALL_LANGS.map(langToken)];
for (const a of allTokens) {
  expect(/^UPX[A-Z]+$/.test(a), `token ${a}: UPX + capitals only`);
  for (const b of allTokens) expect(a === b || !b.startsWith(a), `token ${a} is a prefix of ${b}`);
}

for (const lang of ALL_LANGS) {
  const rel = at(lang, 'upx-shell/reference/index.html');
  const shell = read(rel);
  if (!shell) {
    contract.push(`${rel} missing`);
    continue;
  }
  for (const tok of pageTokens) expect(shell.includes(tok), `${rel}: token ${tok} missing`);
  for (const l of ALL_LANGS) expect(count(shell, langToken(l)) === 2, `${rel}: ${langToken(l)} should appear twice (header + drawer)`);
  for (const name of BLOCKS) expect(count(shell, M.open(name)) === 1 && count(shell, M.close(name)) === 1, `${rel}: block ${name} markers`);
  for (const name of SLOTS) expect(count(shell, M.slot(name)) === 1, `${rel}: slot ${name}`);
  expect(shell.includes('<meta name="robots" content="noindex, nofollow">'), `${rel}: robots meta`);
  expect(count(shell, '<h1') === 1, `${rel}: one <h1>`);
  expect(soleContent(shell, T.TAGLINE), `${rel}: ${T.TAGLINE} must be the only content of its element`);
  expect(count(shell, `content="${T.DESC}"`) === 3, `${rel}: three description metas`);
  for (const tok of [T.HERO_SRC, T.G_SRC]) expect(shell.includes(`src="${tok}"`), `${rel}: src="${tok}"`);
  const prefix = lang === 'en' ? '' : `${lang}/`;
  const hub = read(`${prefix}references/index.html`);
  const home = read(`${prefix}index.html`);
  for (const [kind, html] of [['hub', hub], ['home', home]]) {
    const where = `${prefix}${kind === 'hub' ? 'references/' : ''}index.html`;
    if (!html) {
      contract.push(`${where} missing`);
      continue;
    }
    expect(count(html, M.open(`${kind}-cards`)) === 1 && count(html, M.close(`${kind}-cards`)) === 1, `${where}: ${kind}-cards markers`);
    const cards = [...html.matchAll(CARD_MARK)].length;
    expect(kind === 'hub' ? cards > 0 : cards === HOME_REFS, `${where}: ${cards} card markers`);
    const tpl = templates(html);
    for (const name of TEMPLATES[kind]) {
      expect(!!tpl[name], `${where}: template ${name} missing`);
      if (!tpl[name] || name === 'news-item') continue;
      for (const tok of [T.C_HREF, T.C_TITLE, T.C_ALT, T.C_KEY]) expect(tpl[name].includes(tok), `${where}: ${name} lacks ${tok}`);
      expect(tpl[name].includes(`src="${T.C_SRC}"`), `${where}: ${name} lacks src="${T.C_SRC}"`);
      expect(soleContent(tpl[name], T.C_COUNTRY), `${where}: ${name}: ${T.C_COUNTRY} must be the only content of its element`);
      for (const tok of kind === 'hub' ? [T.C_TYPES, T.C_EXCERPT] : [T.C_DATE]) expect(soleContent(tpl[name], tok), `${where}: ${name}: ${tok} must be the only content of its element`);
    }
    const featured = tpl[`${kind}-card-featured`];
    const firstStatic = html.split(CARD_MARK)[2];
    expect(!featured || !firstStatic || articleClass(featured) === articleClass(firstStatic), `${where}: first built card is not the featured one the plugin demotes`);
    if (kind === 'hub') {
      for (const attr of ['data-show-all', 'data-results-one', 'data-results-other', 'data-showall-other']) expect(html.includes(attr), `${where}: ${attr}`);
      for (const name of ['news-section', 'news-items']) expect(count(html, M.open(name)) === 1 && count(html, M.close(name)) === 1, `${where}: ${name} markers`);
      expect([...html.matchAll(NEWS_MARK)].length > 0, `${where}: news markers`);
      for (const tok of [T.N_HREF, T.N_TITLE, T.N_DATE_ISO, T.N_DATE]) expect((tpl['news-item'] ?? '').includes(tok), `${where}: news-item lacks ${tok}`);
    }
  }
}
// Product pages: the "installed projects" section in product-refs markers, with its two templates.
let productPages = 0;
for (const abs of walk(OUT_DIR)) {
  if (!abs.endsWith('index.html')) continue;
  const html = fs.readFileSync(abs, 'utf8');
  if (!html.includes(M.open('product-refs'))) continue;
  productPages++;
  const where = path.relative(OUT_DIR, abs).split(path.sep).join('/');
  expect(count(html, M.close('product-refs')) === 1 && count(html, M.open('product-cards')) === 1 && count(html, M.close('product-cards')) === 1, `${where}: product markers`);
  const tpl = templates(html);
  for (const name of TEMPLATES.product) expect(!!tpl[name], `${where}: template ${name} missing`);
  expect((tpl['product-card'] ?? '').includes(`src="${T.C_SRC}"`) && soleContent(tpl['product-card'] ?? '', T.C_COUNTRY), `${where}: product-card tokens`);
}
expect(productPages > 50, `only ${productPages} product pages carry product-refs markers`);
// Import file of the built references.
let migrate = null;
try {
  migrate = JSON.parse(read('upx-shell/migrate.json') ?? 'null');
} catch {
  contract.push('upx-shell/migrate.json is not valid JSON');
}
if (migrate) {
  expect(migrate.contract === CONTRACT, `migrate.json contract ${migrate.contract} ≠ ${CONTRACT}`);
  const slugs = migrate.items.map((it) => it.slug);
  expect(new Set(slugs).size === slugs.length, 'migrate.json: duplicate slugs');
  for (const it of migrate.items) {
    for (const lang of Object.keys(it.langs)) {
      const page = read(`${lang === 'en' ? '' : `${lang}/`}${it.source.slice(1)}index.html`);
      expect(!!page && /<article class="prose">/.test(page), `migrate.json: ${lang} ${it.source} has no built page with an <article class="prose"> body`);
    }
  }
} else contract.push('upx-shell/migrate.json missing');
// "Show all" hides cards by position (HubPage.astro): nth-child(VISIBLE + 1) must match upx-contract VISIBLE.
const css = walk(path.join(OUT_DIR, '_astro')).filter((f) => f.endsWith('.css')).map((f) => fs.readFileSync(f, 'utf8')).join('') + (read('references/index.html') ?? '');
expect(new RegExp(`\\.ref-grid[^{}]*>\\s*\\.ref:nth-child\\(n\\s*\\+\\s*${VISIBLE + 1}\\)`).test(css), `hub CSS: .ref-grid > .ref:nth-child(n+${VISIBLE + 1}) not found`);
let data = null;
try {
  data = JSON.parse(read('upx-shell/data.json') ?? 'null');
} catch {
  contract.push('upx-shell/data.json is not valid JSON');
}
if (data) {
  expect(data.contract === CONTRACT, `data.json contract ${data.contract} ≠ ${CONTRACT}`);
  for (const lang of ALL_LANGS) {
    const l = data.l10n?.[lang];
    expect(!!l, `data.json: l10n.${lang} missing`);
    if (!l) continue;
    expect(l.months.long.length === 12 && l.months.short.length === 12 && l.digits.length === 10, `data.json: ${lang} months/digits`);
    expect(Object.keys(l.countries).length === data.countries.length && Object.keys(l.products).length === data.products.length, `data.json: ${lang} names`);
  }
  expect(data.countries.length > 100 && data.products.length > 5, 'data.json: country / court type lists');
} else contract.push('upx-shell/data.json missing');
expect((read('llms.txt') ?? '').includes('](https://www.unixpadel.com/references/)'), 'llms.txt: references hub line');
expect((read('sitemap-index.xml') ?? '').includes('</sitemapindex>'), 'sitemap-index.xml');
// Tokens only live in shells and templates.
for (const abs of walk(OUT_DIR)) {
  const rel = path.relative(OUT_DIR, abs).split(path.sep).join('/');
  if (SHELL.test(rel) || !/\.(html|xml|txt)$/.test(rel)) continue;
  const stray = fs.readFileSync(abs, 'utf8').replace(/<template\s+data-upx[\s\S]*?<\/template>/g, '').match(/\bUPX[A-Z]{3,}\b/);
  if (stray) contract.push(`${rel}: stray token ${stray[0]}`);
}
// The plugin must know every token, block and slot the build emits.
const php = [fs.readFileSync(path.join(ROOT, 'wp-plugin', `${SLUG}.php`), 'utf8'), ...Object.entries(pluginFiles).filter(([k]) => k.endsWith('.php')).map(([, v]) => v.toString('utf8'))].join('\n');
for (const name of [...pageTokens.filter((t) => !bandTokens.includes(t)), ...cardTokens, 'UPXLANG', "'UPXB'", ...BLOCKS.map((b) => `'${b}'`), ...SLOTS.map(M.slot), '<!--upx:card ', '<!--upx:news ', ...Object.values(TEMPLATES).flat().map((t) => `'${t}'`)]) {
  expect(php.includes(name), `PHP sources never mention ${name}`);
}
if (contract.length) {
  console.error(`\nReference shell contract (src/lib/upx-contract.mjs) broken in ${contract.length} place(s):\n  ${contract.slice(0, 40).join('\n  ')}`);
  process.exit(1);
}

const stamp = new Date().toISOString().replace(/\D/g, '').slice(0, 14); // yyyymmddhhmmss (UTC)
const version = `${pkg.version}.${stamp}`;
const phpString = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
// Language twins of the redirects: the old Weglot URL /<lang>/<old>/ goes to /<lang>/<new>/ when that page was
// built, else to the English target.
const langTarget = (lang, to) => (to.startsWith('/') && fs.existsSync(path.join(OUT_DIR, lang, to, 'index.html')) ? `/${lang}${to}` : to);
const allRedirects = {
  ...redirects,
  ...Object.fromEntries(LANGS.flatMap((lang) => Object.entries(redirects).map(([from, to]) => [`/${lang}${from}`, langTarget(lang, to)]))),
};
const redirectsPhp = `<?php\n// Generated by scripts/build-wp-plugin.mjs from src/data/redirects.json. Do not edit.\nreturn array(\n${
  Object.entries(allRedirects).map(([from, to]) => `\t${phpString(from)} => ${phpString(to)},`).join('\n')
}\n);\n`;
const pluginPhp = fs.readFileSync(path.join(ROOT, 'wp-plugin', `${SLUG}.php`), 'utf8')
  .replaceAll('{{VERSION}}', version)
  .replaceAll('{{BUILT}}', new Date().toISOString())
  .replaceAll('{{PAGE_COUNT}}', String(pages))
  .replaceAll('{{CONTRACT}}', String(CONTRACT));

files[`${SLUG}/${SLUG}.php`] = Buffer.from(pluginPhp);
files[`${SLUG}/redirects.php`] = Buffer.from(redirectsPhp);
files[`${SLUG}/index.php`] = Buffer.from('<?php\n// Silence is golden.\n');
for (const [rel, data] of Object.entries(pluginFiles)) files[`${SLUG}/${rel}`] = data;
// The HTML copies inside the plugin folder are reachable over HTTP (the plugin reads them from disk): keep that
// duplicate out of the index. CSS, fonts and JS in /_astro stay crawlable; Google needs them to render.
files[`${SLUG}/dist/.htaccess`] = Buffer.from(
  '<IfModule mod_headers.c>\n\t<FilesMatch "\\.(html|xml|txt|json)$">\n\t\tHeader set X-Robots-Tag "noindex, nofollow"\n\t</FilesMatch>\n</IfModule>\n',
);

const summary = `  version ${version}, ${pages} pages, ${Object.keys(allRedirects).length} redirects, ${Object.keys(files).length} files`;
if (UNPACKED) {
  const dir = path.join(BUILD_DIR, SLUG);
  fs.rmSync(dir, { recursive: true, force: true });
  for (const [rel, data] of Object.entries(files)) {
    const out = path.join(BUILD_DIR, rel);
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, data);
  }
  console.log(`\n${path.relative(ROOT, dir)}${path.sep} (unpacked, assets from ${ASSETS_ORIGIN})`);
  console.log(summary);
  if (ASSETS_ORIGIN !== 'https://www.unixpadel.com') console.log('  Test build: run `npm run build:wp` again before making the ZIP for the live site.');
} else {
  const zip = zipSync(Object.fromEntries(Object.entries(files).map(([k, v]) => [k, new Uint8Array(v)])), { level: 9 });
  fs.mkdirSync(BUILD_DIR, { recursive: true });
  const zipPath = path.join(BUILD_DIR, `${SLUG}-${version}.zip`);
  fs.writeFileSync(zipPath, zip);
  console.log(`\n${path.relative(ROOT, zipPath)}`);
  console.log(summary);
  console.log(`  ${(zip.length / 1024 / 1024).toFixed(2)} MB (check the host's upload limit under Media → Add New)`);
  // GitHub Actions (.github/workflows/deploy-wp.yml) publishes this ZIP as a release.
  if (process.env.GITHUB_OUTPUT) fs.appendFileSync(process.env.GITHUB_OUTPUT, `version=${version}\nzip=${zipPath}\n`);
}
