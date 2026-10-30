// Template grafik bersama (editor AI clip & editor manual): definisi template + penggambar canvas → PNG overlay.
// Butuh @font-face "Clip …" / "ClipW …" di halaman (lihat sub_font_faces() di subtitles.php) dan ic() dari common.js tidak diperlukan.
/* ---------- Template grafik (judul berita ala clipper): digambar di canvas jadi PNG, dipakai sebagai media overlay ---------- */
const GFX = {
  hook: { name: 'Default', cat: 'Teks hook', label: '', text: 'Kalimat pembuka yang bikin penasaran',
    c1: '#FFFFFF', c2: '#FFFFFF', c3: '#111111', cn: [null, 'Kotak', 'Teks'], noLabel: true },
  hook_dark: { name: 'Gelap', cat: 'Teks hook', label: '', text: 'Kalimat pembuka yang bikin penasaran',
    c1: '#FFFFFF', c2: '#111318', c3: '#FFFFFF', cn: [null, 'Kotak', 'Teks'], noLabel: true },
  hook_yellow: { name: 'Kuning', cat: 'Teks hook', label: '', text: 'Kalimat pembuka yang bikin penasaran',
    c1: '#FFFFFF', c2: '#FFD43B', c3: '#111111', cn: [null, 'Kotak', 'Teks'], noLabel: true },
  hook_red: { name: 'Merah', cat: 'Teks hook', label: '', text: 'Kalimat pembuka yang bikin penasaran',
    c1: '#FFFFFF', c2: '#E3262E', c3: '#FFFFFF', cn: [null, 'Kotak', 'Teks'], noLabel: true },
  hook_grad: { name: 'Gradasi', cat: 'Teks hook', label: '', text: 'Kalimat pembuka yang bikin penasaran',
    c1: '#003A8C', c2: '#2F7BF0', c3: '#FFFFFF', cn: ['Gradasi', 'Kotak', 'Teks'], noLabel: true },
  hook_outline: { name: 'Outline', cat: 'Teks hook', label: '', text: 'Kalimat pembuka yang bikin penasaran',
    c1: '#FFFFFF', c2: '#000000', c3: '#FFFFFF', cn: [null, 'Garis', 'Teks'], noLabel: true },
  clipnews: { cat: 'Grafik news', name: 'Clip News', label: 'Clip News', text: 'INDONESIA GAWAT DARURAT! KEUANGAN NEGARA HAMPIR HABIS, TERNYATA INI PENYEBABNYA!',
    c1: '#E5432E', c2: '#FFFFFF', c3: '#111111', cn: ['Label', 'Kotak', 'Teks'] },
  news: { cat: 'Grafik news', name: 'News', label: 'NEWS', text: 'DETIK-DETIK BOCAH BAKAR RUMPUT KERING DI KALIMANTAN, HITUNGAN MENIT API LANGSUNG MEMBESAR',
    c1: '#E0312B', c2: '#0B0B0B', c3: '#FFFFFF', cn: ['Label', 'Kotak', 'Teks'] },
  viral: { cat: 'Grafik news', name: 'Viral', label: '#VIRALIN', text: 'Detik-detik Dua Mobil Tabrakan Adu Banteng, Sopir Sempat Terjepit',
    c1: '#1F4FB5', c2: '#F4D53A', c3: '#161616', cn: ['Label', 'Kotak', 'Teks'] },
  breaking: { cat: 'Grafik news', name: 'Breaking', label: 'BREAKING NEWS', text: 'Harga Emas Hari Ini Tembus Rekor Tertinggi Sepanjang Sejarah',
    c1: '#FFFFFF', c2: '#D72C2C', c3: '#FFFFFF', cn: ['Label', 'Kotak', 'Teks'] },
  // Banner judul selebar layar: pita + kartu bergaris + label. c1 kartu & pita, c2 garis, c3 judul, c4 latar label, c5 teks label
  ...Object.fromEntries([
    ['banner', 'Merah', '#A8383A', '#FFFFFF', '#FFFFFF', '#A8383A', '#FFFFFF'],
    ['banner_blue', 'Biru', '#1E4FB8', '#FFFFFF', '#FFFFFF', '#FFD43B', '#111111'],
    ['banner_dark', 'Gelap', '#15171C', '#FFD43B', '#FFFFFF', '#FFD43B', '#111111'],
    ['banner_yellow', 'Kuning', '#FFD43B', '#111111', '#111111', '#111111', '#FFD43B'],
    ['banner_white', 'Putih', '#FFFFFF', '#E0312B', '#111111', '#E0312B', '#FFFFFF'],
    ['banner_square', 'Tegas', '#D72C2C', '#FFFFFF', '#FFFFFF', '#111111', '#FFFFFF'],
    ['banner_pill', 'Kapsul', '#0F766E', '#FFFFFF', '#FFFFFF', '#FFFFFF', '#0F766E'],
  ].map(([k, name, c1, c2, c3, c4, c5]) => [k, { cat: 'Banner judul', name, label: 'clipper4fame', text: 'Ngobrol Bareng Radit, Mas Tepe Malah Liat Jam?',
    c1, c2, c3, c4, c5, cn: ['Kartu & pita', 'Garis', 'Judul', 'Latar label', 'Teks label'], full: true }])),
  // Kartu kutipan: kartu bergaris tebal + bayangan blok berwarna (kiri-bawah), lencana tanda kutip (kanan atas),
  // teks tebal rapat dengan kata sorotan (*kata*), garis titik-titik & baris sumber. c1 aksen/sorotan, c2 kartu, c3 teks
  ...Object.fromEntries([
    ['quote', 'Merah', '#E8402E', '#FFFFFF', '#111111'],
    ['quote_dark', 'Gelap', '#FFC400', '#15171C', '#FFFFFF'],
    ['quote_blue', 'Biru', '#2F7BF0', '#FFFFFF', '#111111'],
    ['quote_yellow', 'Kuning', '#D72C2C', '#FFD43B', '#111111'],
  ].map(([k, name, c1, c2, c3]) => [k, { cat: 'Kartu kutipan', name, label: '🎬 Judul Film: Subjudul',
    text: 'Siapa sangka, *sahabat sendiri* yang jadi *dalang semuanya*… persahabatan mereka sampai hancur 😱',
    c1, c2, c3, cn: ['Aksen & sorotan', 'Kartu', 'Teks'], rich: true }])),
  minimal: { cat: 'Grafik news', name: 'Minimal', label: 'TERKINI', text: 'Pemerintah Umumkan Kebijakan Baru untuk Pekerja Muda',
    c1: '#2F7BF0', c2: '#0E131B', c3: '#FFFFFF', cn: ['Aksen', 'Kotak', 'Teks'] },
};
const GFX_W = 900;  // lebar desain grafik (px pada layar 1080)
const gfxFont = (st, sz) => `${st} ${sz}px "Clip Montserrat", "Arial Black", sans-serif`;
function wrapText(g, text, maxW) {
  const words = String(text).split(/\s+/).filter(Boolean), lines = [];
  let cur = '';
  for (const w of words) {
    const t = cur ? cur + ' ' + w : w;
    if (cur && g.measureText(t).width > maxW) { lines.push(cur); cur = w; } else cur = t;
  }
  if (cur) lines.push(cur);
  return lines.length ? lines : [' '];
}
// Teks dengan sorotan: bagian yang diapit *bintang* berwarna sorotan. Satu kata bisa berisi beberapa bagian
// ("nafsu*," = "nafsu" sorotan + "," biasa) supaya tanda baca tetap menempel. Dibungkus per kata seperti wrapText.
function richLines(g, text, maxW) {
  const words = [];
  let hi = false;
  for (const raw of String(text).split(/\s+/).filter(Boolean)) {
    const runs = [];
    raw.split('*').forEach((t, i) => {
      if (i > 0) hi = !hi;
      if (t) runs.push({ t, hi });
    });
    if (runs.length) words.push({ w: runs.map(r => r.t).join(''), runs });
  }
  const lines = [];
  let cur = [], curT = '';
  for (const w of words) {
    const t = curT ? curT + ' ' + w.w : w.w;
    if (cur.length && g.measureText(t).width > maxW) { lines.push(cur); cur = [w]; curT = w.w; } else { cur.push(w); curT = t; }
  }
  if (cur.length) lines.push(cur);
  return lines.length ? lines : [[{ w: ' ', runs: [{ t: ' ', hi: false }] }]];
}
// Sedikit lebih gelap / terang untuk gradasi kotak
function shade(hex, f) {
  const n = parseInt(hex.slice(1), 16), c = v => Math.max(0, Math.min(255, Math.round(v + (f < 0 ? v : 255 - v) * f)));
  return `rgb(${c(n >> 16)},${c(n >> 8 & 255)},${c(n & 255)})`;
}
// Gambar grafik ke canvas baru (skala = kerapatan piksel). Mengembalikan canvas.
// Font teks utama pilihan (p.tf = kunci font subtitle): [keluarga file bawaan, keluarga per ketebalan, daftar ketebalan asli].
// Butuh FONTS di halaman (editor); tanpa itu / kosong = font bawaan template (Montserrat)
function gfxTitleFont(p) {
  const f = p.tf && typeof FONTS !== 'undefined' ? FONTS.find(x => x.k === p.tf) : null;
  return f ? { fam: f.family, wf: f.wf, ws: f.w || [] } : null;
}
async function gfxCanvas(p, scale = 2) {
  await Promise.all(['800', 'italic 800', '900', '700'].map(w => document.fonts.load(`${w} 40px "Clip Montserrat"`).catch(() => {})));
  const TF = gfxTitleFont(p), tw = p.tw && (!TF || TF.ws.includes(+p.tw)) ? p.tw : '';
  if (tw) await document.fonts.load(`${tw} 40px "${TF ? TF.wf : 'ClipW Montserrat'}"`).catch(() => {});
  if (TF) await document.fonts.load(`40px "${TF.fam}"`).catch(() => {});
  if (String(p.type).startsWith('quote')) await Promise.all(['Clip Anton', 'Clip Roboto Slab', 'Clip Playfair Display']
    .map(f => document.fonts.load(`700 40px "${f}"`).catch(() => {})));
  const meas = document.createElement('canvas').getContext('2d');
  const M = 18, W = GFX_W, ops = [];
  let H = 0;
  const text = (font, upper) => { meas.font = font; return upper ? String(p.text || '').toUpperCase() : String(p.text || ''); };
  const label = String(p.label || '');
  const T = p.type;
  // Ukuran label (kotak + teks label), 60–200% dari ukuran bawaan template
  const k = Math.max(0.6, Math.min(2, (p.ls ?? 100) / 100));
  // Teks utama: ukuran (ts %), jarak baris (lh %), ketebalan (tw) & perataan (ta); kosong = bawaan template
  const ts = Math.max(0.6, Math.min(1.6, (p.ts ?? 100) / 100)), lhf = Math.max(0.7, Math.min(1.6, (p.lh ?? 100) / 100));
  // Ketebalan pilihan memakai file ketebalan asli (keluarga "ClipW …"); font pilihan tanpa ketebalan = file bawaan font itu
  // (tanpa tebal sintetis); keduanya kosong = font template
  const titleFont = (st, sz) => tw
    ? `${/italic/.test(st) ? 'italic ' : ''}${tw} ${sz * ts}px "${TF ? TF.wf : 'ClipW Montserrat'}", "${TF ? TF.fam : 'Clip Montserrat'}", sans-serif`
    : TF ? `${sz * ts}px "${TF.fam}", "Clip Montserrat", sans-serif` : gfxFont(st, sz * ts);
  const LH = base => base * ts * lhf;
  const place = (def, left, right) => {
    const align = p.ta || def;
    return { align, x: align === 'left' ? left : align === 'right' ? right : (left + right) / 2 };
  };
  if (T === 'clipnews') {
    const bf = gfxFont('italic 800', 42 * k); meas.font = bf;
    const bw = Math.min(W - 22, meas.measureText(label).width + 56 * k), bh = 74 * k;
    const hf = titleFont('italic 800', 46), lines = wrapText(meas, text(hf, true), W - 88), lh = LH(60), pl = place('left', 44, W - 44);
    const by = bh * 0.62, bhh = 40 + bh * 0.38 + lines.length * lh + 34;
    ops.push({ k: 'box', x: 0, y: by, w: W, h: bhh, r: 16, fill: p.c2, grad: true, shadow: true });
    lines.forEach((l, i) => ops.push({ k: 'text', x: pl.x, align: pl.align, y: by + 40 + bh * 0.38 + i * lh + lh * 0.78, t: l, font: hf, fill: p.c3 }));
    if (label) {
      ops.push({ k: 'box', x: 22, y: 0, w: bw, h: bh, r: 12, fill: p.c1, shadow: true });
      ops.push({ k: 'text', x: 22 + bw / 2, y: bh * 0.7, t: label, font: bf, fill: '#FFFFFF', align: 'center' });
    }
    H = by + bhh;
  } else if (T === 'news') {
    const tf = gfxFont('900', 32 * k); meas.font = tf;
    const tw = Math.min(W, meas.measureText(label.toUpperCase()).width + 36 * k), th = label ? 52 * k : 0;
    const hf = titleFont('800', 48), lines = wrapText(meas, text(hf, true), W - 64), lh = LH(60), pl = place('left', 32, W - 32);
    const bh = lines.length * lh + 52;
    ops.push({ k: 'box', x: 0, y: th, w: W, h: bh, r: 0, fill: p.c2, shadow: true });
    lines.forEach((l, i) => ops.push({ k: 'text', x: pl.x, align: pl.align, y: th + 26 + i * lh + lh * 0.8, t: l, font: hf, fill: p.c3 }));
    if (label) {
      ops.push({ k: 'box', x: 0, y: 0, w: tw, h: th, r: 0, fill: p.c1 });
      ops.push({ k: 'text', x: 18 * k, y: th * 0.72, t: label.toUpperCase(), font: tf, fill: '#FFFFFF' });
    }
    H = th + bh;
  } else if (T === 'viral') {
    const bf = gfxFont('900', 52 * k); meas.font = bf;
    const bw = Math.min(W, meas.measureText(label).width + 64 * k), bh = label ? 92 * k : 0;
    const hf = titleFont('800', 54), lines = wrapText(meas, text(hf, false), W - 72), lh = LH(66), pl = place('center', 36, W - 36);
    const by = Math.max(0, bh - 16 * k), bhh = lines.length * lh + 56 + (label ? 16 : 0);
    ops.push({ k: 'box', x: 0, y: by, w: W, h: bhh, r: 8, fill: p.c2, shadow: true });
    lines.forEach((l, i) => ops.push({ k: 'text', x: pl.x, y: by + 28 + (label ? 16 : 0) + i * lh + lh * 0.78, t: l, font: hf, fill: p.c3, align: pl.align }));
    if (label) {
      ops.push({ k: 'box', x: (W - bw) / 2, y: 0, w: bw, h: bh, r: 8, fill: p.c1, shadow: true });
      ops.push({ k: 'text', x: W / 2, y: bh * 0.68, t: label, font: bf, fill: '#FFFFFF', align: 'center' });
    }
    H = by + bhh;
  } else if (T.startsWith('banner')) {
    // Banner judul selebar layar: pita dari tepi kiri ke kanan (bergaris di atas), kartu bergaris berisi judul rata tengah,
    // label kecil menimpa garis atas kartu. Variasi bentuk: sudut kartu, posisi & bentuk label
    const SH = { banner_square: { r: 4, tab: 'left', tr: 2 }, banner_pill: { r: 52, tab: 'center', tr: 'pill' } }[T] || { r: 26, tab: 'center', tr: 8 };
    const lf = gfxFont('700', 30 * k), hf = titleFont('700', 62); meas.font = lf;
    const tabW = Math.min(W - 120, meas.measureText(label).width + (SH.tr === 'pill' ? 56 : 40) * k), tabH = label ? 54 * k : 0;
    meas.font = hf;
    const cx = 40, cw = W - 80, lines = wrapText(meas, String(p.text || ''), cw - 90), lh = LH(76), pl = place('center', cx + 45, cx + cw - 45);
    const cy = tabH / 2, ch = (label ? 34 + tabH / 2 : 40) + lines.length * lh + 34;
    // Pita sampai bawah layar: diperpanjang sebesar ext (dihitung fitBand dari posisi grafik)
    const bandY = cy + ch * 0.58, bottom = cy + ch + 46 + (p.fill !== false ? Math.max(0, p.ext || 0) : 0);
    ops.push({ k: 'box', x: -M, y: bandY, w: W + M * 2, h: bottom - bandY + M, r: 0, fill: p.c1 });
    ops.push({ k: 'box', x: -M, y: bandY, w: W + M * 2, h: 7, r: 0, fill: p.c2 });
    ops.push({ k: 'box', x: cx, y: cy, w: cw, h: ch, r: SH.r, fill: p.c1, line: p.c2, lw: 9, shadow: true });
    lines.forEach((l, i) => ops.push({ k: 'text', x: pl.x, y: cy + (label ? 34 + tabH / 2 : 40) + i * lh + lh * 0.8, t: l, font: hf, fill: p.c3, align: pl.align }));
    if (label) {
      const tx = SH.tab === 'left' ? cx + 34 : (W - tabW) / 2;
      ops.push({ k: 'box', x: tx, y: 0, w: tabW, h: tabH, r: SH.tr === 'pill' ? tabH / 2 : SH.tr * k, fill: p.c4 || p.c1, line: p.c2, lw: 4 * k });
      ops.push({ k: 'text', x: tx + tabW / 2, y: tabH * 0.7, t: label, font: lf, fill: p.c5 || p.c3, align: 'center' });
    }
    H = bottom;
  } else if (T.startsWith('hook')) {
    // Teks hook: kotak membulat selebar teks (polos / gradasi), atau tanpa kotak dengan garis tepi tebal; teks tebal rata tengah
    const outline = T === 'hook_outline', hf = titleFont(outline ? '900' : '800', outline ? 56 : 50); meas.font = hf;
    const lines = wrapText(meas, String(p.text || ''), W - 120), lh = LH(outline ? 68 : 62);
    const bw = Math.min(W, Math.max(...lines.map(l => meas.measureText(l).width)) + 96), bh = lines.length * lh + 52;
    if (!outline) ops.push({ k: 'box', x: (W - bw) / 2, y: 0, w: bw, h: bh, r: 14, fill: p.c2, shadow: true, grad2: T === 'hook_grad' ? p.c1 : null });
    const pl = place('center', (W - bw) / 2 + 48, (W + bw) / 2 - 48);
    lines.forEach((l, i) => ops.push({ k: 'text', x: pl.x, y: 26 + i * lh + lh * 0.78, t: l, font: hf, fill: p.c3, align: pl.align, stroke: outline ? p.c2 : null }));
    H = bh;
  } else if (T.startsWith('quote')) {
    // Kartu kutipan. Teks utama huruf kapital (font Anton), *kata* = warna sorotan; label = baris sumber di bawah garis
    const qf = tw || TF ? titleFont('', 56) : `${56 * ts}px "Clip Anton", "Arial Narrow", sans-serif`;
    const ff = `600 ${28 * k}px "Clip Roboto Slab", Georgia, serif`;
    meas.font = qf;
    const SX = 24, SY = 22;                       // geser bayangan blok: ke kiri-bawah
    const br = 50 * Math.min(1.3, k);             // jari-jari lencana tanda kutip
    const cx = SX + 6, cw = W - cx - 8, padX = 54, cy = br - 4;
    const lines = richLines(meas, String(p.text || '').toUpperCase(), cw - padX * 2), lh = LH(68);
    const pl = place('left', cx + padX, cx + cw - padX);
    const top = cy + 58, textH = lines.length * lh;
    const foot = label ? 30 + 44 * k : 0;
    const ch = top - cy + textH + (label ? 26 : 0) + foot + 40;
    ops.push({ k: 'box', x: cx - SX, y: cy + SY, w: cw, h: ch, r: 30, fill: p.c1 });
    ops.push({ k: 'box', x: cx, y: cy, w: cw, h: ch, r: 30, fill: p.c2, line: '#111111', lw: 9 });
    lines.forEach((ws, i) => ops.push({ k: 'rich', x: pl.x, align: pl.align, y: top + i * lh + lh * 0.82, words: ws, font: qf, fill: p.c3, hi: p.c1 }));
    if (label) {
      const ly = top + textH + 22;
      ops.push({ k: 'line', x1: cx + padX, y1: ly, x2: cx + cw - padX, y2: ly, stroke: p.c1, lw: 5, dash: [2, 12] });
      ops.push({ k: 'text', x: cx + padX, y: ly + 22 + 32 * k, t: label, font: ff, fill: p.c3, alpha: 0.85 });
    }
    // Lencana tanda kutip di pojok kanan atas kartu
    const bx = cx + cw - 34, by = cy + 4;
    ops.push({ k: 'circle', x: bx, y: by, r: br + 9, fill: '#FFFFFF', line: '#111111', lw: 5 });
    ops.push({ k: 'circle', x: bx, y: by, r: br, fill: p.c1 });
    ops.push({ k: 'text', x: bx, y: by + br * 0.95, t: '“', font: `900 ${br * 2.3}px "Clip Playfair Display", Georgia, serif`, fill: '#FFFFFF', align: 'center' });
    H = cy + ch + SY;
  } else if (T === 'breaking') {
    const tf = gfxFont('900', 30 * k); meas.font = tf;
    const tw = Math.min(W, meas.measureText(label.toUpperCase()).width + 40 * k), th = label ? 50 * k : 0;
    const hf = titleFont('800', 50), lines = wrapText(meas, text(hf, false), W - 64), lh = LH(62), pl = place('left', 32, W - 32);
    const bh = lines.length * lh + 56;
    ops.push({ k: 'box', x: 0, y: th, w: W, h: bh, r: 10, fill: p.c2, grad: true, shadow: true });
    lines.forEach((l, i) => ops.push({ k: 'text', x: pl.x, align: pl.align, y: th + 28 + i * lh + lh * 0.8, t: l, font: hf, fill: p.c3 }));
    if (label) {
      ops.push({ k: 'box', x: 0, y: 0, w: tw, h: th + 10, r: 10, fill: p.c1 });
      ops.push({ k: 'text', x: 20 * k, y: th * 0.72, t: label.toUpperCase(), font: tf, fill: p.c2 });
    }
    H = th + bh;
  } else {
    const lf = gfxFont('800', 28 * k), hf = titleFont('700', 46); meas.font = hf;
    const lines = wrapText(meas, String(p.text || ''), W - 100), lh = LH(58), top = label ? 36 + 38 * k : 36, pl = place('left', 50, W - 40);
    const bh = top + lines.length * lh + 34;
    ops.push({ k: 'box', x: 0, y: 0, w: W, h: bh, r: 18, fill: p.c2, alpha: .9, shadow: true });
    ops.push({ k: 'box', x: 0, y: 0, w: 10, h: bh, r: [18, 0, 0, 18], fill: p.c1 });
    if (label) ops.push({ k: 'text', x: 50, y: 30 + 28 * k, t: label.toUpperCase(), font: lf, fill: p.c1, spacing: 3 * k });
    lines.forEach((l, i) => ops.push({ k: 'text', x: pl.x, align: pl.align, y: top + i * lh + lh * 0.78, t: l, font: hf, fill: p.c3 }));
    H = bh;
  }
  const cv2 = document.createElement('canvas');
  cv2.width = Math.ceil((W + M * 2) * scale); cv2.height = Math.ceil((H + M * 2) * scale);
  const g = cv2.getContext('2d');
  g.scale(scale, scale);
  g.translate(M, M);
  for (const o of ops) {
    g.save();
    if (o.k === 'box') {
      if (o.shadow) { g.shadowColor = 'rgba(0,0,0,.35)'; g.shadowBlur = 16; g.shadowOffsetY = 5; }
      g.globalAlpha = o.alpha ?? 1;
      if (o.grad2) { const gr = g.createLinearGradient(o.x, o.y, o.x + o.w, o.y + o.h); gr.addColorStop(0, o.fill); gr.addColorStop(1, o.grad2); g.fillStyle = gr; }
      else if (o.grad) { const gr = g.createLinearGradient(0, o.y, 0, o.y + o.h); gr.addColorStop(0, shade(o.fill, .06)); gr.addColorStop(1, shade(o.fill, -.12)); g.fillStyle = gr; }
      else g.fillStyle = o.fill;
      g.beginPath(); g.roundRect(o.x, o.y, o.w, o.h, o.r); g.fill();
      if (o.line) { g.shadowColor = 'transparent'; g.lineWidth = o.lw || 4; g.strokeStyle = o.line; g.stroke(); }
    } else if (o.k === 'circle') {
      g.beginPath(); g.arc(o.x, o.y, o.r, 0, Math.PI * 2);
      g.fillStyle = o.fill; g.fill();
      if (o.line) { g.lineWidth = o.lw || 4; g.strokeStyle = o.line; g.stroke(); }
    } else if (o.k === 'line') {
      g.beginPath(); g.moveTo(o.x1, o.y1); g.lineTo(o.x2, o.y2);
      g.lineWidth = o.lw || 3; g.strokeStyle = o.stroke; g.lineCap = 'round';
      if (o.dash) g.setLineDash(o.dash);
      g.stroke();
    } else if (o.k === 'rich') {
      // Satu baris berisi kata biasa & kata sorotan, digambar berurutan sesuai perataan
      g.font = o.font; g.textAlign = 'left';
      const sp = g.measureText(' ').width, widths = o.words.map(w => g.measureText(w.w).width);
      const total = widths.reduce((a, b) => a + b, 0) + sp * Math.max(0, o.words.length - 1);
      let x = o.align === 'center' ? o.x - total / 2 : o.align === 'right' ? o.x - total : o.x;
      o.words.forEach((w, i) => {
        let rx = x;
        for (const r of w.runs) { g.fillStyle = r.hi ? o.hi : o.fill; g.fillText(r.t, rx, o.y); rx += g.measureText(r.t).width; }
        x += widths[i] + sp;
      });
    } else {
      g.font = o.font; g.fillStyle = o.fill; g.textAlign = o.align || 'left';
      if (o.alpha != null) g.globalAlpha = o.alpha;
      if (o.spacing) g.letterSpacing = o.spacing + 'px';
      if (o.stroke) { g.lineJoin = 'round'; g.lineWidth = 12; g.strokeStyle = o.stroke; g.strokeText(o.t, o.x, o.y); }
      g.fillText(o.t, o.x, o.y);
    }
    g.restore();
  }
  return cv2;
}
