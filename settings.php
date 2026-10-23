<?php
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
$tab = ($_GET['tab'] ?? '') === 'compress' ? 'compress' : 'storage';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Pengaturan · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .eyebrow { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px; }
  .head { padding: 18px 20px; background: linear-gradient(120deg, rgba(0, 88, 211, .14), transparent 55%), var(--surface); }
  .head h1 { font-size: 20px; margin: 0 0 4px; }
  .head p, .sec p { margin: 0; font-size: 13px; color: var(--muted); line-height: 1.5; }
  .sec { margin-top: 18px; padding: 20px; }
  .sec h2 { font-size: 16px; margin: 0 0 4px; }
  .opts { display: grid; gap: 10px; margin-top: 16px; }
  .opt { display: flex; gap: 14px; align-items: flex-start; padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px; cursor: pointer; transition: border-color .15s, background .15s; }
  .opt:hover { border-color: var(--surface-3); }
  .opt.on { border-color: var(--accent); background: var(--accent-soft); }
  .opt input { margin: 3px 0 0; accent-color: var(--accent); width: 16px; height: 16px; flex: none; }
  .opt .t { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-weight: 600; font-size: 14.5px; }
  .opt .d { font-size: 12.5px; color: var(--muted); line-height: 1.5; margin-top: 4px; }
  .opt .sz { font-size: 12px; color: var(--muted); margin-top: 6px; font-variant-numeric: tabular-nums; }
  .note { margin-top: 14px; font-size: 12px; color: var(--muted); }
  /* Tab: Manajemen storage / Kompresi */
  .tabs { display: flex; gap: 4px; margin-top: 16px; padding: 4px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2); width: fit-content; max-width: 100%; }
  .tabs a { display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 9px; color: var(--muted); font-size: 13.5px; font-weight: 600; text-decoration: none; }
  .tabs a .i { width: 15px; height: 15px; }
  .tabs a:hover { color: var(--text); }
  .tabs a.on { background: var(--accent-soft); color: var(--accent-fg); }
  /* Pemakaian disk */
  .use-total { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
  .use-total b { font-size: 26px; font-variant-numeric: tabular-nums; }
  .use-total span { font-size: 12.5px; color: var(--muted); }
  .use-bar { display: flex; height: 12px; margin-top: 10px; border-radius: 999px; overflow: hidden; background: var(--surface-3); }
  .use-bar i { display: block; height: 100%; min-width: 2px; }
  .use-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px 18px; margin-top: 14px; }
  .use-row { display: flex; align-items: center; gap: 9px; font-size: 13px; padding: 4px 0; }
  .use-row .dot { width: 10px; height: 10px; border-radius: 3px; flex: none; }
  .use-row .n { flex: 1; min-width: 0; color: var(--text-2); }
  .use-row .v { font-weight: 600; font-variant-numeric: tabular-nums; }
  /* Kartu aksi pembersihan */
  .act { display: grid; grid-template-columns: 44px minmax(0, 1fr) auto; gap: 14px; align-items: center; margin-top: 14px; padding: 16px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); }
  .act .ic { width: 44px; height: 44px; display: grid; place-items: center; border-radius: 12px; background: rgba(229, 72, 77, .14); color: #ff8a80; }
  .act .ic .i { width: 20px; height: 20px; }
  .act h3 { margin: 0; font-size: 14.5px; }
  .act p { margin: 3px 0 0; font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .act .gain { color: #4fd28a; font-weight: 700; }
  .act .keep { margin-top: 6px; font-size: 12px; color: var(--text-2); display: flex; gap: 6px; align-items: center; }
  .act .keep .i { width: 13px; height: 13px; color: #4fd28a; flex: none; }
  .act .btn.go { background: rgba(229, 72, 77, .14); color: #ff8a80; box-shadow: inset 0 0 0 1px rgba(229, 72, 77, .45); font-weight: 600; white-space: nowrap; }
  .act .btn.go:hover { background: rgba(229, 72, 77, .24); }
  .act .btn.go:disabled { opacity: .45; cursor: default; background: transparent; }
  /* Pilih room yang video sumbernya dihapus */
  .rp { grid-column: 1 / -1; margin-top: 2px; border-top: 1px solid var(--border); padding-top: 12px; }
  .rp-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; font-size: 12.5px; color: var(--muted); }
  .rp-head button { border: 0; background: none; color: var(--accent-fg); font: inherit; font-weight: 600; cursor: pointer; padding: 2px 4px; }
  .rp-list { display: grid; gap: 6px; max-height: 360px; overflow-y: auto; }
  .rp-row { display: grid; grid-template-columns: 20px 64px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 8px 10px; border: 1px solid var(--border);
    border-radius: 11px; background: var(--surface); cursor: pointer; transition: border-color .15s, background .15s; }
  .rp-row:hover { border-color: var(--border-strong); }
  .rp-row:has(input:checked) { border-color: rgba(229, 72, 77, .5); background: rgba(229, 72, 77, .06); }
  .rp-row input { width: 16px; height: 16px; margin: 0; accent-color: #e5484d; }
  .rp-row img, .rp-row .ph { width: 64px; height: 36px; border-radius: 6px; object-fit: cover; background: var(--surface-3); }
  .rp-row .tt { min-width: 0; }
  .rp-row .tt b { display: block; font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .rp-row .tt small { display: block; font-size: 11.5px; color: var(--muted); margin-top: 1px; }
  .rp-row .v { font-size: 13px; font-weight: 700; color: #4fd28a; font-variant-numeric: tabular-nums; }
  @media (max-width: 640px) { .rp-row { grid-template-columns: 20px minmax(0, 1fr) auto; } .rp-row img, .rp-row .ph { display: none; } }
  @media (max-width: 640px) { .act { grid-template-columns: 44px minmax(0, 1fr); } .act .btn.go { grid-column: 1 / -1; } .act details { grid-column: 1 / -1; } }
</style>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <a class="back" href="index.php" title="Kembali ke Beranda" aria-label="Kembali ke Beranda"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
  </header>

  <div class="card head">
    <div class="eyebrow"><span class="grad-text">Pengaturan</span></div>
    <h1>Pengaturan BisaClip</h1>
    <p>Atur cara BisaClip menyimpan hasil clip di komputer kamu.</p>
    <nav class="tabs" aria-label="Bagian pengaturan">
      <a href="?tab=storage" class="<?= $tab === 'storage' ? 'on' : '' ?>"><?= icon('layers') ?> Manajemen storage</a>
      <a href="?tab=compress" class="<?= $tab === 'compress' ? 'on' : '' ?>"><?= icon('film') ?> Kompresi hasil clip</a>
    </nav>
  </div>

  <?php if ($tab === 'storage'): ?>
  <section class="card sec">
    <h2>Pemakaian disk BisaClip</h2>
    <p>File yang disimpan BisaClip di komputer ini.</p>
    <div id="use"><div class="empty"><span class="spin"></span></div></div>
  </section>
  <section class="card sec">
    <h2>Bersihkan storage</h2>
    <p>Data room, judul, caption, kalender, dan editan clip tidak ikut terhapus.</p>
    <div id="acts"></div>
  </section>
  <?php else: ?>
  <section class="card sec">
    <h2>Kompresi hasil clip</h2>
    <p>Semua pilihan menjaga ketajaman clip. Bedanya ada di ukuran file dan perangkat yang bisa memutarnya.</p>
    <div class="opts" id="opts"><div class="empty"><span class="spin"></span></div></div>
    <div class="note">Berlaku untuk clip yang di-render setelah ini. Clip yang sudah ada tidak berubah.</div>
  </section>
  <?php endif; ?>
</div>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script>
const TAB = <?= json_encode($tab) ?>;
let S = null;
function paint() {
  const cur = S.settings.clip_codec;
  $('opts').innerHTML = Object.entries(S.codecs).map(([id, c]) => `<label class="opt${id === cur ? ' on' : ''}">
      <input type="radio" name="codec" value="${id}"${id === cur ? ' checked' : ''}>
      <div><div class="t">${esc(c.name)} <span class="pill accent">${esc(c.tag)}</span></div>
        <div class="d">${esc(c.desc)}</div><div class="sz">${esc(c.size)}</div></div>
    </label>`).join('');
}
$('opts')?.addEventListener('change', async e => {
  const prev = S.settings.clip_codec;
  S.settings.clip_codec = e.target.value;
  paint();
  try { S.settings = (await api('settings_save', { clip_codec: e.target.value })).settings; toast('Pengaturan disimpan'); }
  catch (err) { S.settings.clip_codec = prev; paint(); toast(err.message); }
});
if (TAB === 'compress') api('settings').then(d => { S = d; paint(); })
  .catch(err => { $('opts').innerHTML = `<div class="empty">${esc(err.message)}</div>`; });

/* ---------- Manajemen storage ---------- */
const GROUPS = [
  ['source', 'Video sumber (original)', '#e5484d'],
  ['parts', 'Potongan sumber', '#f5a524'],
  ['old', 'Render lama (riwayat)', '#d6409f'],
  ['current', 'Render terbaru', '#1e70f0'],
  ['downloads', 'Unduhan lain', '#6e56cf'],
  ['proxy', 'Preview editor', '#12a594'],
  ['campaign', 'Bahan campaign', '#e54666'],
  ['manual', 'Footage project', '#3e63dd'],
  ['music', 'Library musik', '#8e4ec6'],
  ['models', 'Model AI', '#697177'],
];
let busy = '';
let picked = null; // Set id room yang dipilih (null = belum pernah diatur: semua terpilih)
function paintStorage(I) {
  const g = I.groups, total = GROUPS.reduce((a, [k]) => a + g[k].bytes, 0);
  $('use').innerHTML = `<div class="use-total"><b>${fmtSize(total) || '0 MB'}</b><span>Sisa ruang disk: ${fmtSize(I.disk_free)}</span></div>
    <div class="use-bar">${GROUPS.filter(([k]) => g[k].bytes).map(([k, n, c]) => `<i style="width:${g[k].bytes / total * 100}%;background:${c}" title="${n}: ${fmtSize(g[k].bytes)}"></i>`).join('')}</div>
    <div class="use-list">${GROUPS.map(([k, n, c]) => `<div class="use-row"><i class="dot" style="background:${c}"></i><span class="n">${n}</span><span class="v">${fmtSize(g[k].bytes) || '—'}</span></div>`).join('')}</div>`;
  const rooms = I.rooms || [], ids = rooms.map(r => r.id);
  if (!picked) picked = new Set(ids);
  [...picked].forEach(id => { if (!ids.includes(id)) picked.delete(id); });
  const sel = rooms.filter(r => picked.has(r.id)), save = sel.reduce((a, r) => a + r.save, 0), all = sel.length === rooms.length;
  $('acts').innerHTML = `
    <div class="act">
      <span class="ic">${ic('trash')}</span>
      <div><h3>Hapus video sumber original</h3>
        <p>Video lengkap hasil unduhan YouTube dihapus${rooms.some(r => r.repack) ? ', dan potongan sumber yang masih terlalu besar dikecilkan' : ''}. ${!rooms.length ? 'Tidak ada video sumber yang bisa dihapus.'
          : sel.length ? `<span class="gain">±${fmtSize(save)} kembali</span> dari ${sel.length} room terpilih.` : 'Pilih room di bawah.'}</p>
        <div class="keep">${ic('check')} Bagian di sekitar tiap clip disimpan (kualitas sama), jadi clip tetap bisa diedit &amp; dirender ulang.</div></div>
      <button class="btn go" data-act="slim" ${sel.length && !busy ? '' : 'disabled'}>${busy === 'slim' ? '<span class="spin"></span> Menghapus…' : `Hapus ${sel.length && !all ? sel.length + ' room' : 'video sumber'}`}</button>
      ${rooms.length ? `<div class="rp"><div class="rp-head"><span>${sel.length} dari ${rooms.length} room dipilih</span>
          <button type="button" data-rp-all ${busy ? 'disabled' : ''}>${all ? 'Kosongkan' : 'Pilih semua'}</button></div>
        <div class="rp-list">${rooms.map(r => `<label class="rp-row"><input type="checkbox" data-rp="${r.id}" ${picked.has(r.id) ? 'checked' : ''} ${busy ? 'disabled' : ''}>
          ${r.thumb ? `<img src="${esc(r.thumb)}" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'ph'}))">` : '<span class="ph"></span>'}
          <span class="tt"><b title="${esc(r.title)}">${esc(r.title)}</b><small>${r.repack ? `Potongan sumber ${fmtSize(r.bytes)} dikecilkan` : `Video sumber ${fmtSize(r.bytes)}`}${r.clips ? '' : ' · belum ada clip'}</small></span>
          <span class="v">±${fmtSize(r.save)}</span></label>`).join('')}</div></div>` : ''}
    </div>
    <div class="act">
      <span class="ic">${ic('film')}</span>
      <div><h3>Hapus render lama</h3>
        <p>${g.old.count ? `${g.old.count} file versi lama dari Riwayat render · <span class="gain">${fmtSize(g.old.bytes)} kembali</span>` : 'Tidak ada render lama.'}</p>
        <div class="keep">${ic('check')} Render terbaru tiap clip tetap disimpan.</div></div>
      <button class="btn go" data-act="old" ${g.old.count && !busy ? '' : 'disabled'}>${busy === 'old' ? '<span class="spin"></span> Menghapus…' : 'Hapus render lama'}</button>
    </div>`;
}
async function loadStorage() {
  try { paintStorage(S = await api('storage_info')); }
  catch (err) { $('use').innerHTML = `<div class="empty">${esc(err.message)}</div>`; }
}
if (TAB === 'storage') {
  loadStorage();
  $('acts').addEventListener('change', e => {
    const id = e.target.dataset.rp;
    if (!id) return;
    e.target.checked ? picked.add(id) : picked.delete(id);
    paintStorage(S);
  });
  $('acts').addEventListener('click', async e => {
    if (e.target.closest('[data-rp-all]')) {
      const ids = (S.rooms || []).map(r => r.id);
      picked = new Set(picked.size === ids.length ? [] : ids);
      return paintStorage(S);
    }
    const b = e.target.closest('[data-act]');
    if (!b || busy) return;
    const slim = b.dataset.act === 'slim';
    const sel = (S.rooms || []).filter(r => picked.has(r.id));
    if (!confirm(slim
      ? `Hapus video sumber original di ${sel.length} room (±${fmtSize(sel.reduce((a, r) => a + r.save, 0))})?\n\nBagian di sekitar tiap clip tetap disimpan, jadi clip masih bisa diedit & dirender. Room yang clipnya sedang diproses dilewati.`
      : `Hapus ${S.groups.old.count} render lama (${fmtSize(S.groups.old.bytes)})?\n\nRender terbaru tiap clip tetap aman.`)) return;
    busy = b.dataset.act;
    paintStorage(S);
    try {
      const d = await api(slim ? 'storage_slim_all' : 'storage_purge_renders', slim ? { ids: sel.map(r => r.id) } : {});
      toast(`${fmtSize(d.freed) || '0 MB'} ruang disk kembali` + (d.skipped?.length ? ` · ${d.skipped.length} room dilewati (${d.skipped[0].reason})` : ''));
      busy = '';
      paintStorage(S = d.info);
    } catch (err) { busy = ''; toast(err.message); loadStorage(); }
  });
}
</script>
</body>
</html>
