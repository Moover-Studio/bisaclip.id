<?php
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Kalender Upload · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .eyebrow { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px; }
  .head { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; padding: 18px 20px;
    background: linear-gradient(120deg, rgba(0, 88, 211, .14), transparent 55%), var(--surface); }
  .head h1 { font-size: 20px; margin: 0 0 8px; text-transform: capitalize; }
  .head .grow { flex: 1; min-width: 220px; }
  .stats { display: flex; gap: 6px; flex-wrap: wrap; }
  .nav { display: flex; gap: 6px; align-items: center; }
  .nav .btn.icon { width: 34px; padding: 0; height: 34px; }

  /* Filter per akun */
  .acc-filter { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 14px; }
  .acc-filter button { font: inherit; font-size: 12.5px; display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); cursor: pointer; }
  .acc-filter button.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); color: var(--text); }
  .av { flex: none; width: 18px; height: 18px; border-radius: 50%; display: inline-grid; place-items: center; color: #fff; font-weight: 700; font-size: 9.5px; }
  .acc-filter button:has(.av) { padding-left: 5px; }
  .layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 18px; align-items: stretch; margin-top: 18px; }
  /* Grid bulan: Senin sampai Minggu */
  .cal { padding: 10px; }
  .dow, .days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
  .dow div { font-size: 11.5px; font-weight: 600; color: var(--muted); text-align: center; padding: 4px 0 8px; }
  .day { position: relative; min-height: 104px; padding: 7px 7px 8px; border-radius: 10px; border: 1px solid var(--border);
    background: var(--surface-2); font: inherit; color: var(--text); text-align: left; cursor: pointer; display: flex; flex-direction: column; gap: 6px;
    transition: border-color .15s, background .15s; }
  .day:hover { border-color: var(--border-strong); }
  .day.out { opacity: .35; }
  .day.today .num { background: var(--accent); color: #fff; }
  .day.sel { border-color: rgba(30, 112, 240, .8); background: rgba(30, 112, 240, .12); box-shadow: 0 0 0 1px rgba(30, 112, 240, .5); }
  .day.has { background: rgba(31, 157, 92, .08); border-color: rgba(31, 157, 92, .3); }
  .day.has.sel { background: rgba(30, 112, 240, .14); border-color: rgba(30, 112, 240, .8); }
  .day .top { display: flex; align-items: center; justify-content: space-between; gap: 4px; }
  .day .num { font-size: 12.5px; font-weight: 600; min-width: 22px; height: 22px; display: grid; place-items: center; border-radius: 11px; padding: 0 5px;
    font-variant-numeric: tabular-nums; }
  .day .cnt { font-size: 10.5px; font-weight: 700; color: var(--ok); background: var(--ok-soft); padding: 1px 6px; border-radius: 6px; font-variant-numeric: tabular-nums; }
  .thumbs { display: flex; gap: 3px; align-items: flex-end; flex-wrap: wrap; }
  .thumbs img { width: 22px; aspect-ratio: 9/16; object-fit: cover; border-radius: 3px; background: var(--surface-3); }
  .thumbs .more { font-size: 10.5px; color: var(--muted); font-weight: 600; padding-left: 2px; }

  /* Detail tanggal terpilih */
  /* Tinggi panel rekomendasi = tinggi kalender (height 0 + min-height 100%); isi yang lebih panjang di-scroll di dalam panel */
  .detail { height: 0; min-height: 100%; overflow-y: auto; padding: 16px; scrollbar-width: thin; }
  .detail h2 { font-size: 15px; margin: 0 0 2px; }
  .detail .sub { font-size: 12.5px; color: var(--muted); margin-bottom: 12px; }

  /* Hari libur & jenis hari di grid */
  .day.wknd .num { color: #ff8b8b; }
  .day.hol { background: rgba(229, 72, 77, .09); border-color: rgba(229, 72, 77, .32); }
  .day.hol .num, .day.cuti .num { color: #ff8b8b; }
  .day.cuti { background: rgba(245, 166, 35, .07); border-color: rgba(245, 166, 35, .28); }
  .day.cuti .num { color: #f5c06b; }
  .day.custom-libur { background: rgba(229, 72, 77, .06); border-style: dashed; border-color: rgba(229, 72, 77, .4); }
  .day.custom-kerja { border-style: dashed; }
  .day.today .num { color: #fff; }
  .day .hname { margin-top: auto; font-size: 10.5px; font-weight: 600; line-height: 1.25; color: #ff9d9d; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .day.cuti .hname { color: #f5c06b; }
  .day.custom-libur .hname, .day.custom-kerja .hname { color: var(--muted); }
  .legend { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; padding: 12px 4px 2px; font-size: 11.5px; color: var(--muted); }
  .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
  .legend .src { margin-left: auto; }

  /* Panel "Hari ini": jenis hari, garis waktu jam rekomendasi, slot berikutnya */
  .todayc { margin-top: 18px; padding: 18px 20px; display: grid; grid-template-columns: 220px minmax(0, 1fr) 250px; gap: 22px; align-items: center; }
  .todayc .lbl { font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .todayc h2 { margin: 4px 0 8px; font-size: 18px; }
  .chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
  .chip.kerja { background: var(--surface-3); color: var(--text-2); }
  .chip.weekend, .chip.holiday { background: rgba(229, 72, 77, .16); color: #ff9d9d; }
  .chip.cuti { background: rgba(245, 166, 35, .15); color: #f5c06b; }
  .chip.custom { background: rgba(30, 112, 240, .16); color: var(--accent-fg); }
  .todayc .hn { margin-top: 6px; font-size: 12.5px; color: var(--text-2); }
  .tl { position: relative; height: 92px; margin-top: 18px; }
  .tl .slot.hi b { margin-top: -26px; }
  .tl .slot.hi .stem { height: 38px; }
  .tl .slot.hi .dots { order: 3; }
  .tl .bar { position: absolute; left: 0; right: 0; top: 46px; height: 8px; border-radius: 4px; background: var(--surface-3); }
  .tl .past { position: absolute; left: 0; top: 46px; height: 8px; border-radius: 4px; background: rgba(255, 255, 255, .12); }
  .tl .now { position: absolute; top: 36px; width: 2px; height: 28px; margin-left: -1px; background: #fff; border-radius: 1px; box-shadow: 0 0 0 3px rgba(255, 255, 255, .12); }
  .tl .now span { position: absolute; top: 30px; left: 50%; transform: translateX(-50%); font-size: 10.5px; font-weight: 700; white-space: nowrap; }
  .tl .tick { position: absolute; top: 72px; transform: translateX(-50%); font-size: 10.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .tl .slot { position: absolute; top: 0; transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; gap: 3px; }
  .tl .slot b { font-size: 12px; font-variant-numeric: tabular-nums; padding: 2px 7px; border-radius: 7px; background: var(--surface-3); white-space: nowrap; }
  .tl .slot.s3 b { background: var(--accent); color: #fff; }
  .tl .slot.done b { opacity: .45; }
  .tl .slot.live b { background: #1f9d5c; color: #fff; box-shadow: 0 0 0 4px rgba(31, 157, 92, .25); }
  .tl .slot .dots { display: flex; gap: 2px; }
  .tl .slot .dots i { width: 8px; height: 8px; border-radius: 50%; }
  .tl .slot .stem { width: 2px; height: 12px; background: var(--border-strong); }
  .nextc { padding: 12px 14px; border-radius: 14px; background: var(--surface-2); border: 1px solid var(--border); }
  .nextc .k { font-size: 11.5px; color: var(--muted); font-weight: 600; }
  .nextc .v { margin-top: 2px; font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
  .nextc .w { font-size: 12px; color: var(--text-2); margin-top: 2px; }
  .nextc .rd { margin-top: 8px; font-size: 12px; color: var(--ok); font-weight: 600; }
  .nextc .btn { margin-top: 10px; width: 100%; }

  /* Detail: jam rekomendasi untuk tanggal terpilih */
  .dtype { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
  .ovr { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
  .sec-t { display: flex; align-items: center; justify-content: space-between; margin: 4px 0 8px; font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .rec { margin-bottom: 10px; }
  .rec-acc { display: flex; align-items: center; gap: 8px; margin: 8px 0 6px; font-size: 12.5px; font-weight: 700; }
  .rec-acc small { margin-left: auto; font-weight: 600; color: var(--muted); font-size: 11.5px; }
  .rec-acc .av { width: 20px; height: 20px; font-size: 10px; }
  .slotr { display: grid; grid-template-columns: 58px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 7px 10px; border-radius: 12px; background: var(--surface-2); margin-bottom: 5px; }
  .slotr .tm { font-size: 16px; font-weight: 800; font-variant-numeric: tabular-nums; }
  .slotr .nm { font-size: 12.5px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
  .slotr .why { font-size: 11.5px; color: var(--muted); line-height: 1.35; margin-top: 1px; }
  .slotr .st { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
  .slotr .st.live { background: rgba(31, 157, 92, .18); color: #4fd28a; }
  .slotr .st.next { background: rgba(30, 112, 240, .18); color: var(--accent-fg); }
  .slotr .st.done { color: var(--muted); }
  .slotr.past { opacity: .55; }
  .stars { color: #f5c06b; font-size: 10px; letter-spacing: 1px; }

  /* Video yang sudah diupload: bagian penuh di bawah kalender & rekomendasi */
  .ups { margin-top: 18px; padding: 18px 20px 20px; }
  .ups-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
  .ups-head h2 { margin: 0; font-size: 16px; }
  .ups-head .sub { font-size: 12.5px; color: var(--muted); }
  .ups-head .grow { flex: 1; min-width: 200px; }
  .seg { display: flex; gap: 3px; padding: 3px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2); }
  .seg button { height: 32px; padding: 0 14px; border: 0; border-radius: 9px; background: none; color: var(--muted); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; }
  .seg button:hover { color: var(--text); }
  .seg button.on { background: var(--surface-3); color: #fff; box-shadow: inset 0 0 0 1px var(--border-strong); }
  .ups-day { display: flex; align-items: center; gap: 8px; margin: 18px 0 10px; font-size: 13px; font-weight: 700; }
  .ups-day:first-of-type { margin-top: 0; }
  .ups-day small { color: var(--muted); font-weight: 500; }
  .ups-day .chip { font-size: 11px; padding: 2px 8px; }
  .vgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(178px, 1fr)); gap: 14px; }
  .vcard { display: flex; flex-direction: column; border: 1px solid var(--border); border-radius: 16px; background: var(--surface-2); overflow: hidden;
    transition: border-color .15s, transform .15s; }
  .vcard:hover { border-color: var(--border-strong); box-shadow: 0 8px 24px rgba(0, 0, 0, .35); }
  .vcard .scr { position: relative; aspect-ratio: 9 / 16; background: #000; cursor: pointer; }
  .vcard .scr img, .vcard .scr video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .vcard .scr::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0, 0, 0, .45), transparent 28%, transparent 62%, rgba(0, 0, 0, .7));
    pointer-events: none; }
  .vcard .scr.playing::after { display: none; }
  .vcard .play { position: absolute; left: 50%; top: 50%; z-index: 1; width: 46px; height: 46px; margin: -23px 0 0 -23px; display: grid; place-items: center;
    border-radius: 50%; background: rgba(255, 255, 255, .18); backdrop-filter: blur(6px); color: #fff; opacity: 0; transition: opacity .15s; }
  .vcard .play .i { width: 20px; height: 20px; margin-left: 2px; }
  .vcard .scr:hover .play { opacity: 1; }
  .vcard .acc { position: absolute; left: 8px; top: 8px; z-index: 1; display: inline-flex; align-items: center; gap: 5px; max-width: calc(100% - 16px);
    padding: 3px 8px 3px 3px; border-radius: 999px; background: rgba(0, 0, 0, .55); backdrop-filter: blur(6px); color: #fff; font-size: 11px; font-weight: 600; }
  .vcard .acc span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .vcard .acc .av { width: 18px; height: 18px; font-size: 9px; }
  .vcard .meta { position: absolute; left: 8px; right: 8px; bottom: 8px; z-index: 1; display: flex; justify-content: space-between; gap: 6px;
    font-size: 11px; font-weight: 600; color: #fff; font-variant-numeric: tabular-nums; }
  .vcard .meta span { padding: 2px 7px; border-radius: 7px; background: rgba(0, 0, 0, .55); }
  .vcard .scr.playing .acc, .vcard .scr.playing .meta, .vcard .scr.playing .play { display: none; }
  .vcard .bd { flex: 1; display: flex; flex-direction: column; gap: 4px; padding: 10px 11px 11px; }
  .vcard .t { font-size: 13px; font-weight: 700; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .vcard .r { font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .vcard .acts { display: flex; gap: 6px; margin-top: auto; padding-top: 8px; }
  .vcard .acts .btn { flex: 1; padding-left: 6px; padding-right: 6px; }
  .ups .none { padding: 34px 10px; text-align: center; font-size: 13px; color: var(--muted); border: 1px dashed var(--border-strong); border-radius: 14px; line-height: 1.55; }
  .up-sum { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); font-size: 12.5px; }
  .up-sum b { color: var(--ok); }
  .up-sum a { color: var(--accent-fg); font-weight: 600; text-decoration: none; }

  @media (max-width: 1100px) {
    .todayc { grid-template-columns: 1fr; gap: 14px; }
  }
  @media (max-width: 960px) {
    .layout { grid-template-columns: minmax(0, 1fr); }
    .detail { height: auto; min-height: 0; overflow: visible; }
  }
  @media (max-width: 640px) {
    .vgrid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .ups { padding: 14px; }
    .cal { padding: 6px; }
    .dow, .days { gap: 3px; }
    .day { min-height: 58px; padding: 5px 4px; border-radius: 8px; }
    .thumbs img { display: none; }
    .day .cnt { font-size: 10px; padding: 0 4px; }
    .day .top { flex-direction: column; align-items: flex-start; }
    .day .hname { display: none; }
    .tl .tick:nth-of-type(even) { display: none; }
  }
</style>
</head>
<body>
<div class="wrap" style="max-width:1280px">
  <header class="topbar">
    <a class="back" href="index.php" title="Kembali ke Beranda" aria-label="Kembali ke Beranda"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
  </header>

  <div class="card head">
    <div class="grow">
      <div class="eyebrow"><span class="grad-text">Kalender upload</span></div>
      <h1 id="title">…</h1>
      <div class="stats" id="stats"></div>
      <div class="acc-filter" id="acc-filter" hidden></div>
    </div>
    <div class="nav">
      <button class="btn icon" id="prev" aria-label="Bulan sebelumnya"><?= icon('chevron-left') ?></button>
      <button class="btn" id="this">Bulan ini</button>
      <button class="btn icon" id="next" aria-label="Bulan berikutnya"><?= icon('chevron-right') ?></button>
    </div>
  </div>

  <div class="card todayc" id="todayc"></div>

  <div class="layout">
    <div class="card cal">
      <div class="dow"><div>Sen</div><div>Sel</div><div>Rab</div><div>Kam</div><div>Jum</div><div>Sab</div><div>Min</div></div>
      <div class="days" id="days"></div>
      <div class="legend" id="legend"></div>
    </div>
    <aside class="card detail" id="detail"></aside>
  </div>

  <section class="card ups" id="ups"></section>
</div>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script>
// Semua tanggal memakai tanggal lokal (YYYY-MM-DD), sama seperti saat clip ditandai "Done upload" di Clip Studio
const pad = n => String(n).padStart(2, '0');
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d || 1); };
const today = ymd(new Date());
const thumb = c => `api.php?action=frame&id=${c.analysis_id}&t=${Math.round((c.start + c.end) / 2 * 10) / 10}`;

let month = /^\d{4}-\d{2}$/.test(new URLSearchParams(location.search).get('month') || '') ? new URLSearchParams(location.search).get('month') : today.slice(0, 7);
let data = null, sel = null, accFilter = store.get('cal-account') ?? 'all';
const accOf = c => (data.accounts || []).find(a => a.id === c.account_id);
const avatar = a => accAvatar(a);

async function load() {
  history.replaceState(null, '', month === today.slice(0, 7) ? 'calendar.php' : `calendar.php?month=${month}`);
  $('title').textContent = parse(month).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
  $('days').innerHTML = '<div class="empty" style="grid-column:1/-1"><span class="spin"></span></div>';
  try { data = await api('upload_calendar', null, `&month=${month}`); }
  catch (err) { $('days').innerHTML = `<div class="empty" style="grid-column:1/-1">${esc(err.message)}</div>`; return; }
  // Tanggal terpilih: hari ini (kalau di bulan ini), atau tanggal terakhir yang ada upload-nya
  const dates = [...new Set(data.clips.map(c => c.date))];
  if (!sel || !sel.startsWith(month)) sel = today.startsWith(month) ? today : dates[dates.length - 1] || `${month}-01`;
  render();
}

function render() {
  // Filter per akun (Semua / akun tertentu / tanpa akun)
  const accs = data.accounts || [];
  if (accFilter !== 'all' && accFilter !== '' && !accs.some(a => a.id === accFilter)) accFilter = 'all';
  const known = c => accs.some(a => a.id === c.account_id) ? c.account_id : '';
  const clips = accFilter === 'all' ? data.clips : data.clips.filter(c => known(c) === accFilter);
  $('acc-filter').hidden = !accs.length;
  $('acc-filter').innerHTML = `<button class="${accFilter === 'all' ? 'on' : ''}" data-f="all">Semua akun</button>
    ${accs.map(a => `<button class="${accFilter === a.id ? 'on' : ''}" data-f="${a.id}">${avatar(a)}${esc(a.name)}</button>`).join('')}
    ${data.clips.some(c => !known(c)) ? `<button class="${accFilter === '' ? 'on' : ''}" data-f="">Tanpa akun</button>` : ''}`;
  const by = {};
  clips.forEach(c => (by[c.date] = by[c.date] || []).push(c));
  monthClips = clips;
  const days = Object.keys(by).length;
  $('stats').innerHTML = `<span class="pill ok">${clips.length} clip diupload</span><span class="pill">${days} hari aktif</span>
    ${clips.length ? `<span class="pill">rata-rata ${(clips.length / days).toFixed(1).replace('.', ',')} clip/hari aktif</span>` : ''}
    <span class="pill">${data.total} clip sepanjang waktu</span>`;

  // 6 baris x 7 hari, dimulai Senin sebelum/tanggal 1
  const first = parse(month), start = new Date(first);
  start.setDate(1 - ((first.getDay() + 6) % 7));
  const cells = [];
  for (let k = 0; k < 42; k++) {
    const d = new Date(start); d.setDate(start.getDate() + k);
    const key = ymd(d), list = by[key] || [], out = key.slice(0, 7) !== month;
    if (k >= 35 && out && d.getDate() <= 7) break; // baris terakhir kosong tidak perlu
    const di = dayInfo(key, data.holidays, data.overrides);
    const dcls = di.kind === 'custom' ? (di.libur ? 'custom-libur' : 'custom-kerja') : { holiday: 'hol', cuti: 'cuti', weekend: 'wknd' }[di.kind] || '';
    const hname = di.kind === 'custom' ? di.label : di.holiday ? di.holiday.name : '';
    cells.push(`<button class="day ${dcls} ${out ? 'out' : ''} ${key === today ? 'today' : ''} ${key === sel ? 'sel' : ''} ${list.length ? 'has' : ''}" data-d="${key}"
        ${out ? 'tabindex="-1"' : ''} aria-label="${d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' })}, ${list.length} clip">
      <span class="top"><span class="num">${d.getDate()}</span>${list.length ? `<span class="cnt">${list.length} clip</span>` : ''}</span>
      ${list.length ? `<span class="thumbs">${list.slice(0, 4).map(c => `<img src="${thumb(c)}" alt="" loading="lazy"${accOf(c) ? ` style="box-shadow:0 0 0 1.5px ${esc(accOf(c).color)}"` : ''}>`).join('')}
        ${list.length > 4 ? `<span class="more">+${list.length - 4}</span>` : ''}</span>` : ''}
      ${hname ? `<span class="hname" title="${esc(hname)}">${esc(hname)}</span>` : ''}
    </button>`);
  }
  $('days').innerHTML = cells.join('');
  const src = data.holiday_source || {};
  $('legend').innerHTML = `<span><i style="background:rgba(229,72,77,.55)"></i>Libur nasional</span><span><i style="background:rgba(245,166,35,.55)"></i>Cuti bersama</span>
    <span><i style="background:#ff8b8b"></i>Akhir pekan</span><span><i style="border:1px dashed var(--muted)"></i>Ditandai manual</span>
    <span class="src" title="Libur nasional & cuti bersama mengikuti SKB pemerintah">${src.online ? `Data libur diperbarui ${new Date(src.fetched_at * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })}` : 'Data libur bawaan (offline)'}</span>`;
  renderDetail(by[sel] || []);
  renderToday();
}

/* ---------- Jam upload rekomendasi ---------- */
const nowMin = () => { const n = new Date(); return n.getHours() * 60 + n.getMinutes(); };
const inMin = m => m >= 60 ? `${Math.floor(m / 60)} jam ${m % 60 ? (m % 60) + ' mnt' : ''}`.trim() : `${m} mnt`;
// Akun yang ditampilkan mengikuti filter akun di atas
const recAccounts = () => {
  const accs = data.accounts || [];
  if (accFilter === 'all') return accs.length ? accs : [null];
  return accs.filter(a => a.id === accFilter).concat(accFilter === '' ? [null] : []);
};
const stars = n => `<span class="stars" title="Kekuatan slot">${'★'.repeat(n)}${'☆'.repeat(3 - n)}</span>`;
function slotState(date, s) {
  if (date !== today) return date < today ? 'done' : '';
  const m = nowMin();
  if (m >= s.min - 30 && m <= s.min + 20) return 'live';
  return m > s.min + 20 ? 'done' : 'next';
}

function renderToday() {
  const info = dayInfo(today, data.holidays, data.overrides), m = nowMin(), accs = recAccounts();
  const label = parse(today).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
  // Semua slot hari ini (gabungan akun), satu penanda per jam
  const byTime = {};
  accs.forEach(a => uploadSlots(info, a).forEach(s => { (byTime[s.time] = byTime[s.time] || { ...s, accs: [] }).accs.push(a); byTime[s.time].strength = Math.max(byTime[s.time].strength, s.strength); }));
  const slots = Object.values(byTime).sort((a, b) => a.min - b.min);
  const T0 = 6 * 60, T1 = 24 * 60, pos = v => Math.max(0, Math.min(100, (v - T0) / (T1 - T0) * 100));
  // Label jam yang terlalu berdekatan dinaikkan selang-seling supaya tidak bertumpuk
  slots.forEach((s, k) => { s.hi = k > 0 && !slots[k - 1].hi && s.min - slots[k - 1].min < 100; });
  const next = slots.find(s => m <= s.min + 20);
  const live = next && m >= next.min - 30;
  const ready = accs.reduce((a, x) => a + ((data.ready || {})[x?.id ?? ''] || 0), 0);
  setHTML($('todayc'), `<div>
      <div class="lbl">Hari ini</div><h2>${label}</h2>
      <span class="chip ${info.kind}">${esc(info.label)}</span>
      ${info.holiday ? `<div class="hn">${esc(info.holiday.name)}</div>` : ''}
    </div>
    <div class="tl" aria-label="Jam upload rekomendasi hari ini">
      <div class="bar"></div><div class="past" style="width:${pos(m)}%"></div>
      ${[6, 9, 12, 15, 18, 21, 24].map(h => `<span class="tick" style="left:${pos(h * 60)}%">${String(h % 24).padStart(2, '0')}.00</span>`).join('')}
      ${slots.map(s => `<div class="slot s${s.strength} ${slotState(today, s)} ${s.hi ? 'hi' : ''}" style="left:${pos(s.min)}%" title="${esc(s.label)} · ${esc(s.why)}">
        <b>${fmtSlot(s.time)}</b><span class="dots">${s.accs.filter(Boolean).map(a => `<i style="background:${esc(a.color)}" title="${esc(a.name)}"></i>`).join('')}</span><span class="stem"></span></div>`).join('')}
      ${m >= T0 ? `<div class="now" style="left:${pos(m)}%"><span>${String(Math.floor(m / 60)).padStart(2, '0')}.${String(m % 60).padStart(2, '0')}</span></div>` : ''}
    </div>
    <div class="nextc">${next
      ? `<div class="k">${live ? 'Sekarang waktunya upload' : 'Jam upload berikutnya'}</div><div class="v">${fmtSlot(next.time)}</div>
         <div class="w">${esc(next.label)}${live ? '' : ` · dalam ${inMin(next.min - m)}`}</div>`
      : `<div class="k">Slot hari ini sudah lewat</div><div class="w">Lanjut besok. Cek jadwalnya di tanggal besok.</div>`}
      <div class="rd">${ready ? `${ready} clip siap upload` : 'Belum ada clip yang siap upload'}</div>
      ${'Notification' in window && Notification.permission !== 'granted'
        ? `<button class="btn sm" id="notif-on" ${Notification.permission === 'denied' ? 'disabled title="Notifikasi diblokir di pengaturan browser"' : ''}>${ic('calendar')} Aktifkan notifikasi</button>` : ''}
    </div>`);
}
setInterval(() => { if (data && !document.hidden) renderToday(); }, 30e3);
document.addEventListener('click', async e => {
  if (!e.target.closest('#notif-on')) return;
  const p = await Notification.requestPermission();
  toast(p === 'granted' ? 'Notifikasi aktif · kamu diingatkan saat jam upload' : 'Notifikasi tidak diizinkan');
  renderToday();
});

function renderDetail(list) {
  const label = parse(sel).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const info = dayInfo(sel, data.holidays, data.overrides), ov = data.overrides[sel];
  const rec = recAccounts().map(a => {
    const sl = uploadSlots(info, a);
    return `<div class="rec-acc">${a ? accAvatar(a) + esc(a.name) : 'Rekomendasi umum'}<small>${SLOT_KIND_NAME[nicheKind(a)]}</small></div>`
      + sl.map(s => { const st = slotState(sel, s);
        return `<div class="slotr ${st === 'done' ? 'past' : ''}"><span class="tm">${fmtSlot(s.time)}</span>
          <div style="min-width:0"><div class="nm">${esc(s.label)} ${stars(s.strength)}</div><div class="why">${esc(s.why)}</div></div>
          ${st === 'live' ? '<span class="st live">Sekarang</span>' : st === 'next' ? '<span class="st next">Nanti</span>' : st === 'done' && sel === today ? '<span class="st done">Lewat</span>' : '<span></span>'}</div>`; }).join('');
  }).join('');
  $('detail').innerHTML = `<h2>${label}</h2>
    <div class="dtype"><span class="chip ${info.kind}">${esc(info.label)}</span>${info.holiday ? `<span class="muted small">${esc(info.holiday.name)}</span>` : ''}</div>
    <div class="ovr">${ov ? `<button class="btn sm ghost" data-ov="">${ic('reset')} Ikuti kalender</button>` : ''}
      ${!info.libur ? `<button class="btn sm ghost" data-ov="libur" title="Mis. cuti pribadi atau libur daerah">Tandai libur</button>` : ''}
      ${info.libur ? `<button class="btn sm ghost" data-ov="kerja" title="Mis. tetap masuk kerja di hari ini">Tandai hari kerja</button>` : ''}</div>
    <div class="rec"><div class="sec-t">Jam upload rekomendasi</div>${rec}</div>
    <div class="sec-t">Diupload</div>
    <div class="up-sum">${list.length ? `<span><b>${list.length} clip</b> diupload di tanggal ini</span>` : '<span>Belum ada upload di tanggal ini</span>'}<a href="#ups">Lihat ↓</a></div>`;
  renderUploads();
}

/* ---------- Video yang sudah diupload (bagian di bawah kalender) ---------- */
let upView = store.get('cal-up-view') || 'day';
let monthClips = [];
function renderUploads() {
  const dayLabel = d => parse(d).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
  const list = upView === 'day' ? monthClips.filter(c => c.date === sel) : monthClips;
  const card = c => {
    const a = accOf(c);
    return `<div class="vcard">
      <div class="scr" ${c.file ? `data-play="${esc(c.file)}" data-job="${esc(c.job)}"` : ''}><img src="${thumb(c)}" alt="" loading="lazy">
        ${a ? `<span class="acc">${avatar(a)}<span>${esc(a.name)}</span></span>` : ''}
        ${c.file ? `<span class="play">${ic('play')}</span>` : ''}
        <span class="meta"><span>${fmtDur(c.duration)}</span>${c.uploaded_at ? `<span>${new Date(c.uploaded_at * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span>` : ''}</span></div>
      <div class="bd"><div class="t" title="${esc(c.title)}">${esc(c.title)}</div><div class="r" title="${esc(c.room)}">${esc(c.room)}</div>
        <div class="acts"><a class="btn sm ghost" href="studio.php?id=${c.pid}">Studio</a>
          ${c.file ? `<a class="btn sm" href="downloads/${encodeURIComponent(c.file)}?v=${c.job}" download="${esc(c.file)}">${ic('download')} Unduh</a>` : ''}</div></div>
    </div>`;
  };
  let body;
  if (!list.length) {
    body = `<div class="none">${upView === 'day' ? `Belum ada clip yang diupload pada ${dayLabel(sel)}.` : 'Belum ada clip yang diupload bulan ini.'}<br>
      Tandai clip dengan tombol <b>Sudah upload</b> di Clip Studio, nanti muncul di sini.</div>`;
  } else if (upView === 'day') {
    body = `<div class="vgrid">${list.map(card).join('')}</div>`;
  } else {
    // Seluruh bulan: dikelompokkan per tanggal, terbaru dulu
    const dates = [...new Set(list.map(c => c.date))].sort().reverse();
    body = dates.map(d => {
      const info = dayInfo(d, data.holidays, data.overrides), items = list.filter(c => c.date === d);
      return `<div class="ups-day">${dayLabel(d)} <span class="chip ${info.kind}">${esc(info.label)}</span><small>${items.length} clip</small></div>
        <div class="vgrid">${items.map(card).join('')}</div>`;
    }).join('');
  }
  $('ups').innerHTML = `<div class="ups-head"><div class="grow"><h2>Video yang sudah diupload</h2>
      <div class="sub">${upView === 'day' ? dayLabel(sel) : parse(month).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })} · ${list.length} clip</div></div>
    <div class="seg"><button type="button" class="${upView === 'day' ? 'on' : ''}" data-uv="day">Tanggal terpilih</button>
      <button type="button" class="${upView === 'month' ? 'on' : ''}" data-uv="month">Sebulan</button></div></div>${body}`;
}
$('ups').addEventListener('click', e => {
  const v = e.target.closest('[data-uv]');
  if (v) { upView = v.dataset.uv; store.set('cal-up-view', upView); return renderUploads(); }
  // Klik thumbnail: putar videonya langsung di kartu
  const scr = e.target.closest('[data-play]');
  if (scr && !scr.classList.contains('playing')) {
    $('ups').querySelectorAll('.scr.playing').forEach(x => { x.classList.remove('playing'); x.querySelector('video')?.remove(); x.querySelector('img').hidden = false; });
    scr.classList.add('playing');
    scr.querySelector('img').hidden = true;
    scr.insertAdjacentHTML('beforeend', `<video src="downloads/${encodeURIComponent(scr.dataset.play)}?v=${scr.dataset.job}" controls autoplay playsinline></video>`);
  }
});

$('days').onclick = e => {
  const b = e.target.closest('[data-d]');
  if (!b) return;
  sel = b.dataset.d;
  if (sel.slice(0, 7) !== month) { month = sel.slice(0, 7); return load(); } // klik tanggal bulan sebelah: pindah bulan
  render();
};
$('detail').addEventListener('click', async e => {
  const b = e.target.closest('[data-ov]');
  if (!b) return;
  b.disabled = true;
  try { data.overrides = (await api('day_override', { date: sel, mode: b.dataset.ov })).overrides; render(); toast('Jenis hari diperbarui'); }
  catch (err) { toast(err.message); b.disabled = false; }
});
$('acc-filter').onclick = e => {
  const b = e.target.closest('[data-f]');
  if (!b) return;
  accFilter = b.dataset.f;
  store.set('cal-account', accFilter);
  render();
};
const shift = n => { const d = parse(month); d.setMonth(d.getMonth() + n); month = ymd(d).slice(0, 7); load(); };
$('prev').onclick = () => shift(-1);
$('next').onclick = () => shift(1);
$('this').onclick = () => { month = today.slice(0, 7); sel = today; load(); };
document.addEventListener('keydown', e => {
  if (e.target.closest('input, textarea')) return;
  if (e.key === 'ArrowLeft' && e.altKey) shift(-1);
  if (e.key === 'ArrowRight' && e.altKey) shift(1);
});
document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
load();
</script>
</body>
</html>
