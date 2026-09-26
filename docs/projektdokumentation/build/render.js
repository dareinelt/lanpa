// Rendert projektdokumentation.html in zwei Durchläufen zu einer PDF-Datei:
// 1. Inhalt mit unsichtbaren Seitenmarken rendern und Seitenzahlen per pdftotext ermitteln,
// 2. Seitenzahlen ins Verzeichnis eintragen, Inhalt und Deckblatt rendern und zusammenfügen.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');

const dir = '/work/docs/projektdokumentation';
const src = 'file://' + dir + '/projektdokumentation.html';
const out = dir + '/projektdokumentation.pdf';
const tmp = '/tmp/render';
fs.mkdirSync(tmp, { recursive: true });

const footer = `<div style="width:100%;font-family:Helvetica,Arial,sans-serif;font-size:7.5pt;color:#5c6670;padding:0 16mm;display:flex;justify-content:space-between;">
  <span>IT-Projektdokumentation · Modernisierung des Klinikum-Intranets</span>
  <span>Seite <span class="pageNumber"></span> von <span class="totalPages"></span></span></div>`;

async function load(browser, mode) {
  const page = await browser.newPage();
  await page.goto(src, { waitUntil: 'networkidle' });
  await page.evaluate((m) => document.body.classList.add(m), mode);
  await page.evaluate(() => Promise.all(Array.from(document.images).map((i) => i.complete ? null : new Promise((r) => { i.onload = i.onerror = r; }))));
  const broken = await page.evaluate(() => Array.from(document.images).filter((i) => !i.naturalWidth).map((i) => i.getAttribute('src')));
  if (broken.length) throw new Error('Bilder nicht geladen: ' + broken.join(', '));
  return page;
}

function bodyPdf(page, file) {
  return page.pdf({ path: file, format: 'A4', printBackground: true, displayHeaderFooter: true,
    headerTemplate: '<span></span>', footerTemplate: footer,
    margin: { top: '18mm', bottom: '18mm', left: '16mm', right: '16mm' } });
}

(async () => {
  const browser = await chromium.launch();

  // Durchlauf 1: Seitenmarken
  let page = await load(browser, 'mode-body');
  const targets = await page.evaluate(() => window.__setMarkers());
  await bodyPdf(page, tmp + '/pass1.pdf');
  const text = execFileSync('pdftotext', ['-layout', tmp + '/pass1.pdf', '-']).toString();
  const pages = {};
  text.split('\f').forEach((p, i) => {
    for (const m of p.matchAll(/QQM([A-Za-z0-9]+)QQE/g)) {
      const id = m[1].replace(/X/g, '-');
      if (!pages[id]) pages[id] = i + 1;
    }
  });
  const missing = targets.filter((t) => !pages[t]);
  if (missing.length) throw new Error('Seitenzahl nicht ermittelt für: ' + missing.join(', '));

  // Durchlauf 2: finale Fassung
  await page.evaluate((p) => window.__setPages(p), pages);
  await bodyPdf(page, tmp + '/body.pdf');
  const check = execFileSync('pdftotext', ['-layout', tmp + '/body.pdf', '-']).toString();
  if (check.split('\f').length !== text.split('\f').length) throw new Error('Seitenumbruch hat sich zwischen den Durchläufen verändert');

  const cover = await load(browser, 'mode-cover');
  await cover.addStyleTag({ content: '@page { margin: 0 !important; }' });
  await cover.pdf({ path: tmp + '/cover.pdf', format: 'A4', printBackground: true, pageRanges: '1',
    margin: { top: '0', bottom: '0', left: '0', right: '0' } });
  await browser.close();

  execFileSync('pdfunite', [tmp + '/cover.pdf', tmp + '/body.pdf', out]);
  console.log('PDF erstellt: docs/projektdokumentation/projektdokumentation.pdf');
  console.log(execFileSync('pdfinfo', [out]).toString().split('\n').filter((l) => /^Pages/.test(l)).join(''));
})().catch((e) => { console.error(e); process.exit(1); });
