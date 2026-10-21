<?php
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
$id = preg_match('/^[a-f0-9]{12}$/', (string) ($_GET['id'] ?? '')) ? $_GET['id'] : '';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Akun · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .profile-wrap { max-width: 935px; margin: 0 auto; }
  .av { flex: none; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 700; }
  /* Header profil ala sosmed */
  .profile { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 28px; align-items: center; padding: 26px 8px 22px; }
  /* Foto profil: klik untuk ganti */
  .pp { position: relative; justify-self: center; width: 132px; height: 132px; border-radius: 50%; padding: 0; border: 0; background: none; cursor: pointer;
    box-shadow: 0 0 0 4px var(--bg), 0 0 0 6px var(--border-strong); }
  .pp .av { width: 100%; height: 100%; font-size: 54px; }
  .pp .cam { position: absolute; inset: 0; border-radius: 50%; display: grid; place-items: center; background: rgba(0, 0, 0, .55); color: #fff;
    font-size: 12px; font-weight: 600; opacity: 0; transition: opacity .15s; }
  .pp:hover .cam, .pp:focus-visible .cam { opacity: 1; }
  .pp.busy .cam { opacity: 1; }
  .photo-row { display: flex; align-items: center; gap: 12px; }
  .photo-row .av { width: 56px; height: 56px; font-size: 22px; }
  .p-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .p-top h1 { font-size: 22px; margin: 0; }
  .p-handle { font-size: 14px; color: var(--muted); margin-top: 2px; }
  .p-nums { display: flex; gap: 26px; margin: 16px 0 12px; flex-wrap: wrap; }
  .p-nums div { font-size: 14px; color: var(--text-2); }
  .p-nums b { font-size: 17px; color: var(--text); font-variant-numeric: tabular-nums; margin-right: 4px; }
  .p-bio { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; font-size: 13px; color: var(--muted); }

  .ptabs { display: flex; justify-content: center; gap: 36px; border-top: 1px solid var(--border); }
  .ptab { font: inherit; font-size: 12.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; background: none; border: 0; color: var(--muted);
    padding: 14px 2px 12px; border-top: 2px solid transparent; margin-top: -1px; cursor: pointer; display: inline-flex; gap: 6px; align-items: center; }
  .ptab.on { color: var(--text); border-top-color: var(--text); }
  .ptab .n { font-weight: 500; color: var(--muted); letter-spacing: 0; }

  /* Grid post 3 kolom 9:16 */
  .pgrid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; margin-top: 4px; }
  .tile { position: relative; aspect-ratio: 4/5; background: var(--surface-2); overflow: hidden; border: 0; padding: 0; cursor: pointer; display: block; }
  .tile img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .25s; }
  .tile:hover img { transform: scale(1.03); }
  .tile .ov { position: absolute; inset: auto 0 0 0; padding: 26px 10px 9px; background: linear-gradient(transparent, rgba(0, 0, 0, .8)); color: #fff;
    text-align: left; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
  .tile .ov .i { width: 13px; height: 13px; }
  .tile .hov { position: absolute; inset: 0; background: rgba(0, 0, 0, .55); color: #fff; opacity: 0; transition: opacity .15s; padding: 12px;
    display: flex; align-items: center; justify-content: center; text-align: center; font-size: 13px; font-weight: 600; line-height: 1.4; }
  .tile:hover .hov, .tile:focus-visible .hov { opacity: 1; }
  .tile .act { position: absolute; left: 8px; right: 8px; bottom: 8px; z-index: 2; }
  .tile .act .btn { width: 100%; }
  .btn.done-up { background: var(--ok); color: #fff; font-weight: 600; }
  .empty-tab { padding: 56px 16px; text-align: center; color: var(--muted); font-size: 13.5px; line-height: 1.6; }
  .empty-tab > b:first-child { display: block; color: var(--text); font-size: 16px; margin-bottom: 4px; }

  /* Daftar room */
  .rlist { display: grid; gap: 8px; margin-top: 14px; }
  .room { display: grid; grid-template-columns: 128px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 10px; color: inherit; text-decoration: none; }
  .room:hover { border-color: var(--surface-3); }
  .room img { width: 128px; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px; background: var(--surface-3); }
  .room .t { font-weight: 600; font-size: 14px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .room .m { font-size: 12px; color: var(--muted); margin-top: 4px; }

  /* Modal: putar post & edit profil */
  .modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 16px; background: rgba(5, 6, 8, .82); backdrop-filter: blur(6px); }
  .modal[hidden] { display: none; }
  .player { display: grid; grid-template-columns: auto 320px; max-width: 860px; width: 100%; max-height: calc(100vh - 32px); overflow: hidden; padding: 0; }
  .player video { height: min(78vh, 720px); aspect-ratio: 9/16; background: #000; display: block; }
  .player .side { padding: 18px; display: flex; flex-direction: column; gap: 10px; overflow-y: auto; min-width: 0; }
  .player .side h3 { margin: 0; font-size: 15px; line-height: 1.4; }
  .player .meta { font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .player .cap { font-size: 13px; line-height: 1.55; white-space: pre-wrap; color: var(--text-2); padding: 10px 12px; border-radius: 10px; background: var(--surface-2); }
  .player .acts { display: flex; flex-wrap: wrap; gap: 6px; margin-top: auto; }
  .m-card { width: 100%; max-width: 420px; padding: 22px; }
  .m-card h3 { margin: 0 0 16px; font-size: 17px; }
  .m-card label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin: 12px 0 6px; }
  .m-card .input { padding: 9px 12px; font-size: 13.5px; }
  .colors { display: flex; gap: 8px; flex-wrap: wrap; }
  .colors button { width: 28px; height: 28px; border-radius: 50%; border: 2px solid transparent; cursor: pointer; padding: 0; }
  .colors button.on { border-color: #fff; box-shadow: 0 0 0 2px rgba(255, 255, 255, .25); }
  .m-foot { display: flex; gap: 8px; margin-top: 20px; }
  .m-foot .grow { flex: 1; }

  @media (max-width: 720px) {
    .profile { grid-template-columns: 96px minmax(0, 1fr); gap: 16px; }
    .pp { width: 84px; height: 84px; }
    .pp .av { font-size: 34px; }
    .p-nums { gap: 16px; }
    .ptabs { gap: 18px; }
    .player { grid-template-columns: minmax(0, 1fr); overflow-y: auto; }
    .player video { height: auto; width: 100%; max-height: 70vh; }
    .room { grid-template-columns: 88px minmax(0, 1fr); }
    .room img { width: 88px; }
    .room .btn { display: none; }
  }
</style>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <a class="back" href="accounts.php" title="Semua akun" aria-label="Semua akun"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
  </header>
  <div class="profile-wrap" id="app"><div class="empty"><span class="spin"></span></div></div>
</div>

<div class="modal" id="play" hidden>
  <div class="card player" role="dialog" aria-modal="true" id="player"></div>
</div>

<div class="modal" id="edit" hidden>
  <form class="card m-card" id="form" role="dialog" aria-modal="true" aria-labelledby="e-title">
    <h3 id="e-title">Edit profil</h3>
    <label>Foto profil</label>
    <div class="photo-row"><span id="f-photo"></span>
      <button class="btn sm" type="button" id="f-photo-pick"><?= icon('image') ?> Ganti foto</button>
      <button class="btn sm ghost danger" type="button" id="f-photo-del">Hapus foto</button></div>
    <label for="f-name">Nama akun</label>
    <input class="input" id="f-name" maxlength="40" required autocomplete="off">
    <label for="f-handle">Username (opsional)</label>
    <input class="input" id="f-handle" maxlength="41" placeholder="@username" autocomplete="off">
    <label for="f-niche">Niche (opsional)</label>
    <input class="input" id="f-niche" maxlength="60" placeholder="mis. Self development" autocomplete="off">
    <label>Warna</label>
    <div class="colors" id="colors"></div>
    <div class="m-foot">
      <button class="btn ghost danger" type="button" id="f-del"><?= icon('trash') ?> Hapus akun</button>
      <span class="grow"></span>
      <button class="btn ghost" type="button" id="f-cancel">Batal</button>
      <button class="btn primary" type="submit" id="f-save">Simpan</button>
    </div>
  </form>
</div>

<input type="file" id="photo-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script>
const ID = <?= json_encode($id) ?>;
let D = null, tab = store.get('acc-tab') || 'posts', color = '';
// Thumbnail feed dari file clip hasil render (sama seperti cover di sosmed); clip tanpa file memakai frame video sumber
const thumb = c => c.file ? `api.php?action=clip_thumb&id=${c.pid}&iid=${c.iid}`
  : `api.php?action=frame&id=${c.analysis_id}&t=${Math.round((c.start + c.end) / 2 * 10) / 10}`;
const dayLabel = (s, long) => { const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('id-ID', long ? { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } : { day: 'numeric', month: 'short' }); };
const todayStr = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

async function load() {
  if (!ID) return notFound('Akun tidak ditemukan.');
  try { D = await api('account_view', null, '&id=' + ID); }
  catch (err) { return notFound(err.message); }
  document.title = `${D.account.name} · BisaClip`;
  render();
}
const notFound = msg => { $('app').innerHTML = `<div class="card empty"><b>${esc(msg)}</b><a href="accounts.php" style="color:var(--accent-fg)">Lihat semua akun</a></div>`; };

function render() {
  const A = D.account;
  const tabs = [['posts', 'Post', D.posts.length], ['queue', 'Siap diupload', D.queue.length], ['rooms', 'Room', D.rooms.length]];
  $('app').innerHTML = `
    <section class="profile">
      <button class="pp" id="pp" title="${A.avatar ? 'Ganti foto profil' : 'Tambah foto profil'}">${accAvatar(A)}<span class="cam">${A.avatar ? 'Ganti foto' : 'Tambah foto'}</span></button>
      <div style="min-width:0">
        <div class="p-top"><h1>${esc(A.name)}</h1>
          <button class="btn sm" id="edit-btn">${ic('pencil')} Edit profil</button>
          <a class="btn sm ghost" href="index.php">${ic('plus')} Clip baru</a></div>
        ${A.handle ? `<div class="p-handle">@${esc(A.handle)}</div>` : ''}
        <div class="p-nums"><div><b>${D.posts.length}</b>post</div><div><b>${D.queue.length}</b>siap diupload</div><div><b>${D.clips}</b>clip</div><div><b>${D.rooms.length}</b>room</div></div>
        <div class="p-bio">${A.niche ? `<span class="pill accent">${esc(A.niche)}</span>` : ''}${A.style ? `<span class="pill">${A.style === 'gaming' ? 'Streaming game' : 'Podcast'}</span>` : ''}
          ${D.posts.length ? `<span>Upload terakhir ${dayLabel(D.posts[0].date)}</span>` : ''}</div>
      </div>
    </section>
    <nav class="ptabs">${tabs.map(([k, n, c]) => `<button class="ptab ${tab === k ? 'on' : ''}" data-tab="${k}">${n} <span class="n">${c}</span></button>`).join('')}</nav>
    <div id="tab-body">${tabBody()}</div>`;
}

function tabBody() {
  if (tab === 'rooms') {
    return D.rooms.length ? `<div class="rlist">${D.rooms.map(r => `<a class="card room" href="studio.php?id=${r.id}">
        <img src="${esc(r.thumbnail)}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
        <div style="min-width:0"><div class="t">${esc(r.title || 'Video')}</div>
          <div class="m">${r.clips} clip · ${r.uploaded} diupload · ${r.style === 'gaming' ? 'Streaming game' : 'Podcast'} · ${fmtDate(r.created_at)}</div></div>
        <span class="btn sm ghost">Buka studio</span></a>`).join('')}</div>`
      : '<div class="empty-tab"><b>Belum ada room</b>Saat membuat clip dari video baru, pilih akun ini di "Atur clip".</div>';
  }
  const list = tab === 'queue' ? D.queue : D.posts;
  if (!list.length) {
    return tab === 'queue'
      ? '<div class="empty-tab"><b>Tidak ada antrean</b>Clip yang sudah selesai dirender tapi belum diupload muncul di sini.</div>'
      : '<div class="empty-tab"><b>Belum ada post</b>Klik <b>Sudah upload</b> pada clip di Clip Studio (atau di tab Siap diupload), nanti tampil di sini seperti feed akunmu.</div>';
  }
  return `<div class="pgrid">${list.map((c, i) => `<div class="tile" role="button" tabindex="0" data-i="${i}" aria-label="${esc(c.title)}">
      <img src="${thumb(c)}" alt="" loading="lazy">
      <span class="hov">${esc(c.title)}</span>
      ${tab === 'posts' ? `<span class="ov">${ic('check')} ${dayLabel(c.date)}</span>`
        : `<span class="act"><button class="btn sm done-up" data-up="${i}">${ic('check')} Sudah upload</button></span>`}
    </div>`).join('')}</div>`;
}

// Putar post / antrean di modal
function openPlayer(c) {
  const A = D.account;
  $('player').innerHTML = `${c.file ? `<video src="downloads/${encodeURIComponent(c.file)}" controls autoplay playsinline></video>` : '<video></video>'}
    <div class="side">
      <div style="display:flex;gap:8px;align-items:center">${accAvatar(A, 'width:28px;height:28px;font-size:12px')}
        <b style="font-size:13.5px">${esc(A.handle ? '@' + A.handle : A.name)}</b></div>
      <h3>${esc(c.title)}</h3>
      <div class="meta">${c.date ? `Diupload ${dayLabel(c.date, true)}<br>` : 'Belum diupload<br>'}Dari: ${esc(c.room)}</div>
      ${c.caption ? `<div class="cap">${esc(c.caption)}${c.hashtags?.length ? '\n\n' + esc(c.hashtags.join(' ')) : ''}</div>` : ''}
      <div class="acts">
        ${c.caption ? '<button class="btn sm" id="p-copy">' + ic('caption') + ' Salin caption</button>' : ''}
        ${c.en ? '<button class="btn sm" id="p-copy-en" title="Judul + caption + hashtag versi English">' + ic('caption') + ' Salin English</button>' : ''}
        ${c.file ? `<a class="btn sm ghost" href="downloads/${encodeURIComponent(c.file)}" download>${ic('download')} Download</a>` : ''}
        <a class="btn sm ghost" href="studio.php?id=${c.pid}">Buka studio</a>
        <button class="btn sm ghost" id="p-close">Tutup</button>
      </div>
    </div>`;
  $('play').hidden = false;
  if ($('p-copy')) $('p-copy').onclick = () => copyText((c.caption + (c.hashtags?.length ? '\n\n' + c.hashtags.join(' ') : '')).trim(), 'Caption disalin');
  if ($('p-copy-en')) $('p-copy-en').onclick = () => copyText((c.en.caption + (c.en.hashtags?.length ? '\n\n' + c.en.hashtags.join(' ') : '')).trim(), 'English caption copied');
  $('p-close').onclick = closePlayer;
}
function closePlayer() { $('play').hidden = true; $('player').innerHTML = ''; }
$('play').addEventListener('click', e => { if (e.target === $('play')) closePlayer(); });

$('app').addEventListener('click', async e => {
  const t = e.target.closest('[data-tab]');
  if (t) { tab = t.dataset.tab; store.set('acc-tab', tab); return render(); }
  if (e.target.closest('#edit-btn')) return openEdit();
  if (e.target.closest('#pp')) return $('photo-file').click();
  const up = e.target.closest('[data-up]');
  if (up) {
    // Done upload langsung dari antrean akun
    up.disabled = true;
    const c = D.queue[+up.dataset.up];
    try {
      await api('project_upload', { id: c.pid, iid: c.iid, uploaded: true, date: todayStr() });
      toast('Ditandai sudah diupload hari ini');
      await load();
    } catch (err) { toast(err.message); up.disabled = false; }
    return;
  }
  const tile = e.target.closest('.tile');
  if (tile) openPlayer((tab === 'queue' ? D.queue : D.posts)[+tile.dataset.i]);
});
$('app').addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.classList.contains('tile')) e.target.click();
});

// Foto profil: unggah (dipotong persegi di server) atau hapus
async function savePhoto(file) {
  const fd = new FormData();
  fd.append('id', ID);
  if (file) fd.append('file', file);
  $('pp')?.classList.add('busy');
  try {
    const r = await fetch('api.php?action=account_avatar', { method: 'POST', body: fd });
    const d = await r.json().catch(() => ({ error: 'Respons server tidak valid.' }));
    if (!r.ok || d.error) throw new Error(d.error || 'Foto gagal disimpan.');
    D.account.avatar = d.avatar;
    toast(file ? 'Foto profil diperbarui' : 'Foto profil dihapus');
    render();
    paintPhoto();
  } catch (err) { toast(err.message); $('pp')?.classList.remove('busy'); }
}
$('photo-file').onchange = e => { const f = e.target.files[0]; e.target.value = ''; if (f) savePhoto(f); };
const paintPhoto = () => {
  $('f-photo').innerHTML = accAvatar({ ...D.account, color });
  $('f-photo-del').hidden = !D.account.avatar;
};
$('f-photo-pick').onclick = () => $('photo-file').click();
$('f-photo-del').onclick = () => savePhoto(null);

// Edit profil & hapus akun
function openEdit() {
  const A = D.account;
  $('f-name').value = A.name; $('f-handle').value = A.handle ? '@' + A.handle : ''; $('f-niche').value = A.niche || '';
  color = A.color;
  paintColors();
  paintPhoto();
  $('edit').hidden = false;
  $('f-name').focus();
}
function paintColors() {
  $('colors').innerHTML = D.colors.map(c => `<button type="button" class="${c === color ? 'on' : ''}" data-c="${c}" style="background:${c}" aria-label="Warna ${c}"></button>`).join('');
}
$('colors').onclick = e => { const b = e.target.closest('[data-c]'); if (b) { color = b.dataset.c; paintColors(); paintPhoto(); } };
$('f-cancel').onclick = () => { $('edit').hidden = true; };
$('edit').addEventListener('click', e => { if (e.target === $('edit')) $('edit').hidden = true; });
$('form').onsubmit = async e => {
  e.preventDefault();
  $('f-save').disabled = true;
  try {
    await api('account_save', { id: ID, name: $('f-name').value, handle: $('f-handle').value, niche: $('f-niche').value, color });
    $('edit').hidden = true;
    toast('Profil disimpan');
    load();
  } catch (err) { toast(err.message); }
  finally { $('f-save').disabled = false; }
};
$('f-del').onclick = async () => {
  if (!confirm(`Hapus akun "${D.account.name}"? Room dan clip-nya tidak ikut terhapus, hanya jadi "tanpa akun".`)) return;
  try { await api('account_delete', { id: ID }); location.href = 'accounts.php'; } catch (err) { toast(err.message); }
};
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!$('play').hidden) closePlayer();
  $('edit').hidden = true;
});
load();
</script>
</body>
</html>
