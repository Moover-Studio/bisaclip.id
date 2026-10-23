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
<title>bisaclip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .hero { text-align: center; padding: 28px 0 22px; }
  .hero h1 { font-size: 26px; line-height: 1.25; margin: 0 0 6px; letter-spacing: -.01em; }
  .hero p { margin: 0; color: var(--muted); }
  .search { display: flex; align-items: center; gap: 6px; padding: 6px 6px 6px 4px; }
  .search .input { border: 0; background: transparent; font-size: 15px; padding: 9px 12px; }
  .search .input:focus { background: transparent; box-shadow: none; }
  .search .btn { flex: none; }
  .search-msg { font-size: 13.5px; padding: 0 4px; margin-top: 10px; min-height: 20px; text-align: center; }

  /* Kartu video setelah dicek */
  .video { margin-top: 16px; overflow: hidden; }
  /* Thumbnail kiri; kanan: judul (maks. 2 baris), info, lalu tombol sejajar dasar thumbnail */
  .video-head { display: grid; grid-template-columns: 196px minmax(0, 1fr); gap: 18px; align-items: stretch; padding: 16px; }
  .video-head img { width: 196px; aspect-ratio: 16/9; object-fit: cover; border-radius: 10px; background: var(--surface-2); align-self: start; }
  .video-info { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
  .video-head h2 { font-size: 16px; line-height: 1.4; margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .choices { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; padding-top: 10px; }
  .choices .btn.on { background: rgba(30, 112, 240, .3); }
  .dl-panel { border-top: 1px solid var(--border); padding: 16px; background: var(--surface-2); }
  .dl-panel .label { font-size: 12px; font-weight: 600; color: var(--muted); margin: 0 0 8px; text-transform: uppercase; letter-spacing: .04em; }
  .dl-row + .dl-row { margin-top: 16px; }
  .dl-foot { display: flex; justify-content: flex-end; margin-top: 16px; }

  /* Section bawah */
  .section { margin-top: 36px; }
  .rooms { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px; }
  .room { overflow: hidden; transition: transform .15s, border-color .15s; display: block; }
  .room:hover { border-color: var(--surface-3); box-shadow: 0 8px 24px rgba(0, 0, 0, .35); }
  .room-thumb { position: relative; aspect-ratio: 16/9; background: var(--surface-2); }
  .room-thumb img { width: 100%; height: 100%; object-fit: cover; }
  .room-thumb .pill { position: absolute; left: 8px; bottom: 8px; background: rgba(0, 0, 0, .7); color: #fff; backdrop-filter: blur(4px); }
  .room-thumb .pill.cleaned { left: auto; right: 8px; }
  .room-body { padding: 12px 14px 14px; }
  .room-title { font-weight: 600; font-size: 14px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden; min-height: 39px; }
  .room-meta { font-size: 12.5px; color: var(--muted); margin-top: 6px; display: flex; gap: 6px; align-items: center; }
  .room .progress { margin-top: 8px; }
  /* Room selesai: bagian terpisah di bawah room aktif, bisa dilipat */
  .rooms-done { margin-top: 28px; }
  .rooms-done-h { display: flex; align-items: center; gap: 10px; width: 100%; margin: 0 0 12px; padding: 10px 0 0; border: 0; border-top: 1px solid var(--border);
    background: none; color: var(--text); font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; text-align: left; }
  .rooms-done-h .n { font-size: 12.5px; font-weight: 500; color: var(--muted); }
  .rooms-done-h .i { width: 15px; height: 15px; color: var(--muted); transition: transform .2s; }
  .rooms-done-h .hint { margin-left: auto; font-size: 12px; font-weight: 500; color: var(--muted); }
  .rooms-done.shut .rooms-done-h .i { transform: rotate(-90deg); }
  .rooms-done.shut .rooms { display: none; }
  .rooms-done .room:not(:hover) .room-thumb img { filter: saturate(.55) brightness(.85); }
  .room-meta .fin { color: var(--ok); font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
  .room-meta .fin .i { width: 13px; height: 13px; }
  /* Mode pilih untuk hapus: kartu jadi bisa dicentang, tombol hapus melayang di bawah */
  .tabs .tab-act { margin-left: auto; align-self: center; flex: none; width: 32px; height: 32px; display: grid; place-items: center; padding: 0;
    border: 1px solid var(--border); border-radius: 9px; background: var(--surface-2); color: var(--text-2); cursor: pointer; transition: background .15s, color .15s; }
  .tabs .tab-act .i { width: 15px; height: 15px; }
  .tabs .tab-act:hover { background: var(--surface-3); color: var(--text); }
  .tabs .tab-act.on { background: var(--accent-soft); color: var(--accent-fg); border-color: transparent; }
  .room .pick { position: absolute; top: 8px; right: 8px; width: 24px; height: 24px; display: none; place-items: center; border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, .9); background: rgba(0, 0, 0, .35); color: #fff; box-shadow: 0 2px 6px rgba(0, 0, 0, .3); }
  .room .pick .i { width: 13px; height: 13px; stroke-width: 3; opacity: 0; }
  .selecting .room .pick { display: grid; }
  .selecting .room:hover { transform: none; }
  .room.picked { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
  .room.picked .pick { background: var(--accent); border-color: var(--accent); }
  .room.picked .pick .i { opacity: 1; }
  .selbar { position: fixed; left: 50%; bottom: 24px; z-index: 40; display: flex; align-items: center; gap: 8px; padding: 8px 8px 8px 16px;
    border: 1px solid var(--border-strong); border-radius: 14px; background: var(--surface); box-shadow: 0 16px 40px -12px rgba(0, 0, 0, .55);
    transform: translateX(-50%); animation: selin .2s ease-out; }
  .selbar[hidden] { display: none; }
  .selbar .cnt { font-size: 13.5px; font-weight: 600; margin-right: 8px; white-space: nowrap; }
  .btn.del { background: var(--err); color: #fff; font-weight: 600; }
  .btn.del:hover { filter: brightness(1.08); }
  .btn.del:disabled:hover { background: var(--err); filter: none; }
  @keyframes selin { from { opacity: 0; transform: translate(-50%, 16px); } to { opacity: 1; transform: translate(-50%, 0); } }
  body.selecting .wrap { padding-bottom: 96px; }

  /* Modal pengaturan "Buat Clip" */
  .modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 16px;
    background: rgba(5, 6, 8, .72); backdrop-filter: blur(6px); animation: mfade .15s; }
  .modal[hidden] { display: none; }
  @keyframes mfade { from { opacity: 0; } }
  .m-card { width: 100%; max-width: 480px; max-height: calc(100vh - 32px); overflow-y: auto; padding: 22px 22px 18px; animation: mpop .18s ease-out; scrollbar-width: thin; }
  /* Tombol aksi modal selalu terlihat walau isi modal di-scroll (layar laptop pendek) */
  .m-card .m-foot { position: sticky; bottom: -18px; z-index: 3; margin: 18px -22px -18px; padding: 14px 22px 18px;
    background: var(--surface); border-top: 1px solid var(--border); }
  @keyframes mpop { from { opacity: 0; transform: translateY(8px) scale(.98); } }
  .m-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
  .m-head h3 { margin: 0 0 3px; font-size: 17px; }
  .m-head p { margin: 0; font-size: 12.5px; color: var(--muted); line-height: 1.45; }
  .m-head .grow { flex: 1; min-width: 0; }
  .m-label { font-size: 12px; font-weight: 500; color: var(--muted); margin: 0 0 8px; display: flex; justify-content: space-between; }
  .presets { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
  .langs { margin-bottom: 18px; }
  /* Konsep clip: chip pilihan ganda */
  .concepts { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
  .concepts button { font: inherit; display: inline-flex; flex-direction: column; align-items: flex-start; gap: 1px; padding: 7px 11px; border-radius: 12px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text); cursor: pointer; text-align: left; transition: border-color .15s, background .15s; }
  .concepts button b { font-size: 12.5px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
  .concepts button small { font-size: 10.5px; color: var(--muted); }
  .concepts button:hover { border-color: var(--border-strong); }
  .concepts button.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); }
  .concepts button.on b { color: #86B6FF; }
  .concepts button .ck { width: 14px; height: 14px; border-radius: 4px; border: 1.5px solid var(--border-strong); display: inline-grid; place-items: center; font-size: 9px; color: transparent; }
  .concepts button.on .ck { background: var(--accent); border-color: var(--accent); color: #fff; }
  .concepts .all { justify-content: center; }
  .concept-note { width: 100%; margin-bottom: 18px; padding: 9px 12px; font-size: 13px; }
  /* Gaya caption: pilih satu */
  .capstyles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-bottom: 18px; }
  .capstyles button { font: inherit; text-align: left; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2);
    color: var(--text); cursor: pointer; transition: border-color .15s, background .15s; min-width: 0; }
  .capstyles button b { display: block; font-size: 12.5px; font-weight: 600; }
  .capstyles button small { display: block; margin-top: 1px; font-size: 10.5px; color: var(--muted); line-height: 1.3; }
  .capstyles button:hover { border-color: var(--border-strong); }
  .capstyles button.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); }
  .capstyles button.on b { color: #86B6FF; }
  @media (max-width: 520px) { .capstyles { grid-template-columns: 1fr 1fr; } }
  .preset { font: inherit; text-align: left; padding: 9px 11px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2);
    color: var(--text); cursor: pointer; transition: border-color .15s, background .15s; }
  .preset b { display: block; font-size: 13.5px; font-variant-numeric: tabular-nums; }
  .preset small { display: block; margin-top: 2px; font-size: 11px; color: var(--muted); }
  .preset:hover { border-color: var(--border-strong); }
  .preset.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); }
  .preset.on b { color: #86B6FF; }
  .preset:disabled { opacity: .35; cursor: default; }
  .custom { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 13px; color: var(--muted); }
  .custom .input { width: 80px; padding: 7px 10px; text-align: center; font-variant-numeric: tabular-nums; }
  .m-sec { margin-top: 18px; }
  /* Pilihan style clip: podcast / streaming game, dengan sketsa layout 9:16 */
  .styles { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 18px; }
  .style-opt { font: inherit; text-align: left; display: flex; align-items: center; gap: 11px; padding: 10px 11px; border-radius: 10px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text); cursor: pointer; transition: border-color .15s, background .15s; }
  .style-opt b { display: block; font-size: 13.5px; }
  .style-opt small { display: block; margin-top: 2px; font-size: 11px; color: var(--muted); line-height: 1.35; }
  .style-opt:hover { border-color: var(--border-strong); }
  .style-opt.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); }
  .style-opt.on b { color: #86B6FF; }
  .style-opt .lay { flex: none; width: 22px; height: 38px; display: flex; flex-direction: column; gap: 2px; padding: 2px;
    border-radius: 5px; border: 1px solid var(--border-strong); }
  .style-opt .lay i { flex: 1; border-radius: 2px; background: var(--text-2); opacity: .45; }
  .style-opt .lay.game i:first-child { flex: 7; }
  .style-opt .lay.game i:last-child { flex: 3; }
  .style-opt.on .lay i { background: #86B6FF; opacity: .8; }
  /* Akun tujuan (modal) & avatar inisial akun */
  .av { flex: none; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 13px; }
  .m-label a { color: var(--accent-fg); font-weight: 500; }
  .accs { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 18px; }
  .acc-opt { font: inherit; text-align: left; display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 10px; min-width: 0;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text); cursor: pointer; transition: border-color .15s, background .15s; }
  .acc-opt > span:last-child { min-width: 0; }
  .acc-opt b { display: block; font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .acc-opt small { display: block; font-size: 11px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .acc-opt:hover { border-color: var(--border-strong); }
  .acc-opt.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); }
  .acc-opt.add { border-style: dashed; color: var(--text-2); justify-content: center; }
  .acc-opt.add .av { background: var(--surface-3); color: var(--text-2); }
  .acc-new { display: grid; gap: 8px; padding: 12px; margin: -10px 0 18px; border-radius: 12px; border: 1px solid var(--border); background: var(--surface-2); }
  .acc-new .input { padding: 9px 12px; font-size: 13.5px; }
  .acc-new .row { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  .acc-new .hint { font-size: 12px; color: var(--muted); }
  /* Label akun di kartu room & filter per akun */
  .room-thumb .acc-tag { position: absolute; left: 8px; top: 8px; display: inline-flex; align-items: center; gap: 5px; max-width: calc(100% - 16px);
    padding: 2px 8px 2px 3px; border-radius: 999px; background: rgba(0, 0, 0, .72); color: #fff; font-size: 11.5px; font-weight: 600; backdrop-filter: blur(4px); }
  .acc-tag .av { width: 16px; height: 16px; font-size: 9px; }
  .acc-tag span:last-child { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .acc-filter { display: flex; gap: 6px; flex-wrap: wrap; margin: -4px 0 14px; }
  .acc-filter button { font: inherit; font-size: 12.5px; display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px 4px 5px; border-radius: 999px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); cursor: pointer; }
  .acc-filter button:not(:has(.av)) { padding-left: 11px; }
  .acc-filter button.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); color: var(--text); }
  .acc-filter .av { width: 18px; height: 18px; font-size: 9.5px; }
  .acc-filter .n { color: var(--muted); font-variant-numeric: tabular-nums; }
  .top-links { margin-left: auto; display: flex; gap: 4px; }
  /* Tiga aksi utama beranda: tenang dengan aksen biru brand.
     Hover premium: semua perubahan memudar perlahan (kurva ease-out panjang), sorotan biru mengikuti kursor,
     kotak ikon terisi gradasi biru secara halus, kartu terangkat sedikit. */
  .acts3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; --ease: cubic-bezier(.22, 1, .36, 1); }
  .act3 { position: relative; isolation: isolate; display: flex; flex-direction: column; justify-content: space-between; align-items: flex-start; gap: 28px;
    padding: 18px 18px 16px; border: 1px solid rgba(255, 255, 255, .08); border-radius: 16px; cursor: pointer; overflow: hidden;
    font: inherit; color: var(--text); text-decoration: none; text-align: left;
    background: radial-gradient(120% 90% at 0% 0%, rgba(30, 112, 240, .10), transparent 55%),
      linear-gradient(180deg, rgba(255, 255, 255, .035), rgba(255, 255, 255, .01)), var(--surface);
    transform: translateZ(0);
    transition: transform .55s var(--ease), border-color .55s var(--ease), box-shadow .55s var(--ease); }
  /* Garis cahaya biru tipis di tepi atas */
  .act3::before { content: ""; position: absolute; left: 18px; right: 18px; top: 0; height: 1px; opacity: .5; z-index: 1;
    background: linear-gradient(90deg, transparent, rgba(61, 139, 255, .95), transparent); transition: opacity .55s var(--ease), left .55s var(--ease), right .55s var(--ease); }
  /* Sorotan biru lembut yang mengikuti posisi kursor */
  .act3::after { content: ""; position: absolute; inset: 0; z-index: -1; opacity: 0; pointer-events: none;
    background: radial-gradient(260px circle at var(--mx, 30%) var(--my, 0%), rgba(61, 139, 255, .16), transparent 65%);
    transition: opacity .6s var(--ease); }
  .act3:hover { transform: translateY(-2px); border-color: rgba(61, 139, 255, .42);
    box-shadow: 0 0 0 3px rgba(30, 112, 240, .07), 0 18px 40px -12px rgba(0, 0, 0, .55), 0 8px 24px -10px rgba(30, 112, 240, .35); }
  .act3:hover::before { opacity: 1; left: 0; right: 0; }
  .act3:hover::after { opacity: 1; }
  .act3:active { transform: translateY(0) scale(.992); transition-duration: .15s; }
  .act3:focus-visible { outline: none; border-color: rgba(61, 139, 255, .7); }
  .act3 .ic { position: relative; isolation: isolate; width: 36px; height: 36px; display: grid; place-items: center; border-radius: 10px; color: #6FA8FF; overflow: hidden;
    border: 1px solid rgba(61, 139, 255, .28); background: rgba(30, 112, 240, .12);
    transition: color .5s var(--ease), border-color .5s var(--ease), box-shadow .5s var(--ease); }
  /* Isi gradasi biru memudar masuk (gradasi tidak bisa ditransisikan langsung) */
  .act3 .ic::before { content: ""; position: absolute; inset: 0; z-index: -1; opacity: 0; background: linear-gradient(145deg, #2F80FF, #0058D3); transition: opacity .5s var(--ease); }
  .act3 .ic .i { width: 17px; height: 17px; transition: transform .55s var(--ease); }
  .act3:hover .ic { color: #fff; border-color: rgba(120, 170, 255, .7); box-shadow: 0 6px 18px -6px rgba(30, 112, 240, .7); }
  .act3:hover .ic::before { opacity: 1; }
  .act3:hover .ic .i { transform: scale(1.08); }
  .act3 b { font-size: 15px; font-weight: 600; letter-spacing: -.01em; transition: letter-spacing .55s var(--ease); }
  .act3 .go { position: absolute; right: 16px; bottom: 16px; color: #6FA8FF; opacity: 0; transform: translateX(-8px);
    transition: opacity .45s var(--ease), transform .55s var(--ease); }
  .act3 .go .i { width: 15px; height: 15px; }
  .act3:hover .go { opacity: 1; transform: none; }
  @media (prefers-reduced-motion: reduce) { .act3, .act3 *, .act3::before, .act3::after { transition: none !important; } .act3:hover { transform: none; } }
  @media (max-width: 760px) {
    .acts3 { gap: 8px; }
    .act3 { gap: 18px; padding: 14px 12px; border-radius: 14px; }
    .act3 b { font-size: 13.5px; } .act3 .go { display: none; }
  }
  .url-card { max-width: 640px; }
  .url-card .search { padding: 4px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); }
  .url-card .video { margin-top: 14px; box-shadow: none; }
  /* Timeline proses buat clip */
  .flow-card { max-width: 520px; }
  .fl-thumb { width: 88px; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px; flex: none; background: var(--surface-2); }
  .fl-steps { display: grid; gap: 0; }
  .fl-step { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 12px; position: relative; padding-bottom: 16px; }
  .fl-step:not(:last-child)::before { content: ""; position: absolute; left: 13px; top: 28px; bottom: 0; width: 2px; background: var(--border); }
  .fl-step.done:not(:last-child)::before { background: var(--accent); }
  .fl-dot { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-3); color: var(--muted); font-size: 12px; font-weight: 700; }
  .fl-dot .i { width: 14px; height: 14px; }
  .fl-step.done .fl-dot { background: var(--accent); color: #fff; }
  .fl-step.active .fl-dot { background: var(--accent-soft); color: var(--accent-fg); box-shadow: 0 0 0 3px rgba(30, 112, 240, .25); }
  .fl-step.error .fl-dot { background: rgba(229, 72, 77, .2); color: #ff8a80; }
  .fl-step b { display: block; font-size: 13.5px; margin-top: 4px; }
  .fl-step small { display: block; font-size: 12px; color: var(--muted); margin-top: 2px; line-height: 1.45; }
  .fl-step.error small { color: #ff8a80; }
  .fl-step .bar { height: 4px; margin-top: 7px; border-radius: 3px; background: var(--surface-3); overflow: hidden; }
  .fl-step .bar i { display: block; height: 100%; background: var(--accent); transition: width .4s; }
  .fl-note { margin: 4px 0 0; font-size: 12px; color: var(--muted); }
  /* Pintu masuk clip campaign dari footage lokal */
  .camp-cta { position: relative; isolation: isolate; overflow: hidden; display: flex; align-items: center; gap: 12px; margin: 12px 0 0; padding: 12px 16px;
    border: 1px solid rgba(255, 255, 255, .08); border-radius: 16px; color: var(--text); text-decoration: none;
    background: linear-gradient(180deg, rgba(255, 255, 255, .035), rgba(255, 255, 255, .01)), var(--surface);
    transition: border-color .55s cubic-bezier(.22, 1, .36, 1), box-shadow .55s cubic-bezier(.22, 1, .36, 1); }
  .camp-cta::after { content: ""; position: absolute; inset: 0; z-index: -1; opacity: 0; pointer-events: none;
    background: radial-gradient(320px circle at var(--mx, 10%) var(--my, 50%), rgba(61, 139, 255, .13), transparent 65%); transition: opacity .6s cubic-bezier(.22, 1, .36, 1); }
  .camp-cta:hover { border-color: rgba(61, 139, 255, .42); box-shadow: 0 0 0 3px rgba(30, 112, 240, .06); }
  .camp-cta:hover::after { opacity: 1; }
  .camp-cta .ic { flex: none; width: 36px; height: 36px; display: grid; place-items: center; border-radius: 10px; color: #6FA8FF;
    border: 1px solid rgba(61, 139, 255, .28); background: rgba(30, 112, 240, .12); transition: color .5s, border-color .5s; }
  .camp-cta:hover .ic { color: #fff; border-color: rgba(120, 170, 255, .7); }
  .camp-cta .ic .i { width: 17px; height: 17px; }
  .camp-cta > span:nth-child(2) { flex: 1; min-width: 0; }
  .camp-cta b { display: block; font-size: 13.5px; font-weight: 600; }
  .camp-cta small { display: block; font-size: 12px; color: var(--muted); margin-top: 1px; }
  .camp-cta > .i { width: 15px; height: 15px; color: var(--muted); flex: none; transition: transform .55s cubic-bezier(.22, 1, .36, 1), color .45s; }
  .camp-cta:hover > .i { transform: translateX(3px); color: #6FA8FF; }
  /* Dropdown Pengaturan */
  .set-dd { position: relative; }
  .set-menu { position: absolute; right: 0; top: calc(100% + 6px); z-index: 50; width: 260px; padding: 6px; border-radius: 14px; box-shadow: 0 16px 40px rgba(0, 0, 0, .45); }
  .set-menu a { display: flex; gap: 10px; align-items: center; padding: 9px 10px; border-radius: 10px; color: var(--text); text-decoration: none; }
  .set-menu a:hover, .set-menu a:focus-visible { background: var(--surface-3); outline: none; }
  .set-menu .ic { flex: none; width: 32px; height: 32px; display: grid; place-items: center; border-radius: 9px; background: var(--surface-3); color: var(--text-2); }
  .set-menu .ic .i { width: 16px; height: 16px; }
  .set-menu b { display: block; font-size: 13.5px; }
  .set-menu small { display: block; font-size: 11.5px; color: var(--muted); margin-top: 1px; }
  /* Layar sempit: menu atas cukup ikon supaya tidak meluap */
  @media (max-width: 640px) {
    .top-links span { display: none; }
    .top-links .btn { width: 36px; height: 36px; padding: 0; }
    .top-links .btn .i { width: 17px; height: 17px; margin: 0; }
  }
  .counter { display: flex; align-items: center; gap: 12px; }
  .counter .btn { width: 32px; height: 32px; padding: 0; font-size: 16px; }
  .counter output { min-width: 34px; text-align: center; font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .counter .muted { font-size: 12.5px; }
  .estimate { margin-top: 18px; padding: 14px 16px; border-radius: 12px; background: rgba(30, 112, 240, .1); border: 1px solid rgba(30, 112, 240, .28); }
  .estimate .big { font-size: 15px; font-weight: 600; }
  .estimate .big em { font-style: normal; color: #86B6FF; }
  .estimate p { margin: 4px 0 0; font-size: 12px; color: var(--muted); line-height: 1.5; }
  .m-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }

  @media (max-width: 640px) {
    .hero h1 { font-size: 22px; }
    .search { flex-wrap: wrap; }
    .search .input { flex-basis: 100%; }
    .search .btn { flex: 1; }
    .video-head { grid-template-columns: 1fr; }
    .video-head img { width: 100%; }
    .choices .btn { flex: 1; }
    .presets { grid-template-columns: 1fr 1fr; }
    .styles, .accs { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
<div class="wrap narrow">
  <header class="topbar">
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
    <div class="top-links">
      <a class="btn sm ghost" href="accounts.php" title="Akun"><?= icon('user') ?> <span>Akun</span></a>
      <a class="btn sm ghost" href="calendar.php" title="Kalender upload"><?= icon('calendar') ?> <span>Kalender upload</span></a>
      <div class="set-dd" id="set-dd">
        <button class="btn sm ghost" type="button" id="set-btn" title="Pengaturan" aria-haspopup="menu" aria-expanded="false"><?= icon('settings') ?> <span>Pengaturan</span></button>
        <div class="set-menu card" id="set-menu" role="menu" hidden>
          <a role="menuitem" href="settings.php?tab=storage"><span class="ic"><?= icon('layers') ?></span><span><b>Manajemen storage</b><small>Hapus video sumber &amp; render lama</small></span></a>
          <a role="menuitem" href="settings.php?tab=compress"><span class="ic"><?= icon('film') ?></span><span><b>Kompresi hasil clip</b><small>Ukuran file &amp; codec render</small></span></a>
        </div>
      </div>
    </div>
  </header>

  <section class="hero">
    <h1>Mau bikin apa hari ini?</h1>
    <p>Jadikan video YouTube clip oleh AI, edit footage sendiri, atau unduh video &amp; audio dengan kualitas maksimal.</p>
  </section>

  <!-- Tiga aksi utama -->
  <div class="acts3">
    <button class="act3" type="button" data-open="clip"><span class="ic"><?= icon('scissors') ?></span><b>Buat clip</b><span class="go"><?= icon('arrow-right') ?></span></button>
    <a class="act3" href="project.php"><span class="ic"><?= icon('layers') ?></span><b>Edit manual</b><span class="go"><?= icon('arrow-right') ?></span></a>
    <button class="act3" type="button" data-open="download"><span class="ic"><?= icon('download') ?></span><b>YT Downloader</b><span class="go"><?= icon('arrow-right') ?></span></button>
  </div>
  <a class="camp-cta" href="campaign.php"><span class="ic"><?= icon('film') ?></span>
    <span><b>Punya footage sendiri untuk campaign?</b><small>Unggah bahan dari komputer, tulis angle-nya, AI menyusun clip + hook</small></span>
    <?= icon('arrow-right') ?></a>

  <!-- Modal link YouTube: dipakai "Buat clip" & "Download" -->
  <div class="modal" id="url-modal" hidden>
  <div class="card m-card url-card" role="dialog" aria-modal="true" aria-labelledby="um-title">
    <div class="m-head"><div class="grow"><h3 id="um-title"></h3><p id="um-sub"></p></div>
      <button class="btn sm ghost" id="um-close" title="Tutup (Esc)"><?= icon('x') ?></button></div>
  <form class="search" id="form">
    <input class="input" type="text" id="url" placeholder="https://youtube.com/watch?v=…" autocomplete="off" autofocus>
    <button type="button" class="btn ghost" id="paste"><?= icon('paste') ?> Tempel</button>
    <button type="submit" class="btn primary" id="check" disabled><?= icon('search') ?> Cek Video</button>
  </form>
  <div class="search-msg" id="msg"></div>

  <div class="card video" id="video" hidden>
    <div class="video-head">
      <img id="v-thumb" alt="">
      <div class="video-info">
        <h2 id="v-title"></h2>
        <div class="muted small" id="v-meta"></div>
        <div class="choices">
          <button class="btn primary" id="go-analyze"><?= icon('scissors') ?> Buat Clip</button>
          <button class="btn" id="go-download"><?= icon('download') ?> Download</button>
        </div>
      </div>
    </div>
    <div class="dl-panel" id="dl-panel" hidden>
      <div class="dl-row" id="quality-row">
        <div class="label">Kualitas</div>
        <div class="chips" id="qualities"></div>
      </div>
      <div class="dl-row">
        <div class="label">Format</div>
        <div class="formats" id="formats"></div>
      </div>
      <div class="dl-foot"><button class="btn primary" id="start"><?= icon('download') ?> Mulai Download</button></div>
    </div>
  </div>
  </div>
  </div>

  <!-- Modal timeline proses setelah "Mulai buat clip" -->
  <div class="modal" id="flow-modal" hidden>
    <div class="card m-card flow-card" role="dialog" aria-modal="true" aria-labelledby="fl-title">
      <div class="m-head"><img class="fl-thumb" id="fl-thumb" alt=""><div class="grow"><h3 id="fl-title">Memproses clip</h3><p id="fl-video"></p></div></div>
      <div class="fl-steps" id="fl-steps"></div>
      <p class="fl-note">Proses tetap berjalan walau jendela ini ditutup. Room-nya ada di tab Clip Studio.</p>
      <div class="m-foot"><button class="btn ghost" id="fl-close">Tutup</button>
        <a class="btn primary" id="fl-go" href="#"><?= icon('scissors') ?> Masuk Clip Studio</a></div>
    </div>
  </div>

  <section class="section">
    <div class="tabs" id="tabs">
      <button class="tab on" data-tab="studio">Clip Studio<span class="count" id="c-studio"></span></button>
      <button class="tab" data-tab="projects">Project<span class="count" id="c-projects"></span></button>
      <button class="tab" data-tab="downloads">Unduhan<span class="count" id="c-downloads"></span></button>
      <button class="tab-act" id="sel-toggle" type="button" title="Pilih room untuk dihapus" aria-label="Pilih room untuk dihapus"><?= icon('trash') ?></button>
    </div>
    <div id="p-studio"></div>
    <div id="p-projects" hidden></div>
    <div id="p-downloads" hidden></div>
  </section>
</div>

<div class="selbar" id="selbar" hidden>
  <span class="cnt" id="sel-cnt">0 dipilih</span>
  <button class="btn sm ghost" id="sel-all" type="button">Pilih semua</button>
  <button class="btn sm ghost" id="sel-cancel" type="button">Batal</button>
  <button class="btn del" id="sel-del" type="button" disabled><?= icon('trash') ?> Hapus</button>
</div>

<div class="modal" id="del-modal" hidden>
  <div class="card m-card" role="dialog" aria-modal="true" aria-labelledby="del-title" style="max-width:420px">
    <div class="m-head"><div class="grow"><h3 id="del-title">Hapus room?</h3>
      <p id="del-text">Semua clip, hasil render, dan editan di room ini akan dihapus permanen.</p></div></div>
    <div class="m-foot">
      <button class="btn ghost" id="del-no" type="button">Batal</button>
      <button class="btn del" id="del-yes" type="button"><?= icon('trash') ?> Hapus permanen</button>
    </div>
  </div>
</div>

<div class="modal" id="clip-modal" hidden>
  <div class="card m-card" role="dialog" aria-modal="true" aria-labelledby="m-title">
    <div class="m-head">
      <div class="grow"><h3 id="m-title">Atur clip</h3><p id="m-video"></p></div>
      <button class="btn sm ghost" id="m-close" title="Tutup (Esc)"><?= icon('x') ?></button>
    </div>
    <div class="m-label"><span>Akun tujuan</span><a href="accounts.php">Kelola akun</a></div>
    <div class="accs" id="accs"></div>
    <div class="acc-new" id="acc-new" hidden>
      <input class="input" id="an-name" placeholder="Nama akun, mis. Mindset Harian" maxlength="40" autocomplete="off">
      <input class="input" id="an-niche" placeholder="Niche (opsional), mis. Self development" maxlength="60" autocomplete="off">
      <div class="row"><span class="hint" id="an-hint">Clip dari video ini akan masuk ke akun ini.</span>
        <span><button class="btn sm ghost" id="an-cancel" type="button">Batal</button> <button class="btn sm primary" id="an-save" type="button">Simpan akun</button></span></div>
    </div>
    <div class="m-label"><span>Style clip</span></div>
    <div class="styles" id="styles">
      <button class="style-opt" data-style="podcast"><span class="lay"><i></i></span>
        <span><b>Podcast</b><small>Kamera mengikuti orang yang bicara</small></span></button>
      <button class="style-opt" data-style="gaming"><span class="lay game"><i></i><i></i></span>
        <span><b>Streaming game</b><small>Game 70% di atas, webcam 30% di bawah</small></span></button>
    </div>
    <div class="m-label"><span>Konsep clip</span><span>boleh pilih lebih dari satu</span></div>
    <div class="concepts" id="concepts"></div>
    <input class="input concept-note" id="concept-note" maxlength="200" autocomplete="off"
      placeholder="Konteks tambahan (opsional), mis. fokus saat bahas bisnis &amp; uang">
    <div class="m-label"><span>Gaya caption</span><span id="cap-hint"></span></div>
    <div class="capstyles" id="capstyles"></div>
    <div class="m-label"><span>Bahasa judul &amp; caption</span><span id="lang-hint"></span></div>
    <div class="presets langs" id="langs"></div>
    <div class="m-label"><span>Durasi tiap clip</span></div>
    <div class="presets" id="presets"></div>
    <div class="custom" id="custom" hidden>
      <input class="input" type="number" id="c-min" min="10" max="170" step="5"> –
      <input class="input" type="number" id="c-max" min="20" max="180" step="5"> detik
    </div>
    <div class="m-sec">
      <div class="m-label"><span>Jumlah clip maksimal</span><span id="count-hint"></span></div>
      <div class="counter">
        <button class="btn" id="cnt-dec" aria-label="Kurangi">−</button>
        <output id="cnt">0</output>
        <button class="btn" id="cnt-inc" aria-label="Tambah">+</button>
        <span class="muted" id="cnt-auto"></span>
      </div>
    </div>
    <div class="estimate" id="estimate"></div>
    <div class="m-foot">
      <button class="btn ghost" id="m-cancel">Batal</button>
      <button class="btn primary" id="m-start"><?= icon('scissors') ?> Mulai buat clip</button>
    </div>
  </div>
</div>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script>

let video = null, height = 0, mode = 'edit_h264', polling = null;
let accounts = [], accFilter = store.get('room-filter') ?? 'all';
const avatar = a => accAvatar(a);
async function loadAccounts() { try { accounts = (await api('accounts')).accounts; } catch { accounts = []; } }
let tab = ['downloads', 'projects'].includes(store.get('tab')) ? store.get('tab') : 'studio';

/* ---------- Modal link YouTube: mode "clip" (lanjut atur clip) atau "download" ---------- */
let urlMode = 'clip';
function openUrl(mode) {
  urlMode = mode;
  $('um-title').textContent = mode === 'clip' ? 'Buat clip dari YouTube' : 'Download dari YouTube';
  $('um-sub').textContent = mode === 'clip' ? 'Tempel link video, lalu klik Proses. Berikutnya kamu bisa mengatur jenis & jumlah clip.'
    : 'Tempel link video, lalu pilih kualitas & format.';
  $('check').innerHTML = `${ic(mode === 'clip' ? 'scissors' : 'search')} ${mode === 'clip' ? 'Proses' : 'Cek video'}`;
  $('video').hidden = true;
  $('msg').textContent = '';
  $('url-modal').hidden = false;
  setTimeout(() => $('url').focus(), 30);
}
const closeUrl = () => { $('url-modal').hidden = true; };
document.querySelectorAll('[data-open]').forEach(b => b.onclick = () => openUrl(b.dataset.open));
$('um-close').onclick = closeUrl;
$('url-modal').addEventListener('click', e => { if (e.target === $('url-modal')) closeUrl(); });
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!$('url-modal').hidden) closeUrl();
  else if (!$('flow-modal').hidden) closeFlow();
});

/* ---------- Cek video ---------- */

$('paste').onclick = async () => {
  try { $('url').value = (await navigator.clipboard.readText()).trim(); syncCheck(); if ($('url').value) $('form').requestSubmit(); }
  catch { $('url').focus(); }
};

// Cek Video hanya aktif kalau kolom URL terisi
const syncCheck = () => { $('check').disabled = !$('url').value.trim(); };
$('url').addEventListener('input', syncCheck);

$('form').onsubmit = async e => {
  e.preventDefault();
  const url = $('url').value.trim();
  if (!url) return;
  $('check').disabled = true;
  $('msg').className = 'search-msg muted';
  $('msg').innerHTML = '<span class="spin"></span> Membaca info video…';
  $('video').hidden = true;
  try {
    video = await api('info', { url });
    $('msg').textContent = '';
    $('url').value = ''; // kembali ke kondisi awal, siap untuk link berikutnya
    // Buat clip: langsung ke pengaturan clip; Download: tampilkan pilihan kualitas & format
    if (urlMode === 'clip') { closeUrl(); openModal(); } else showVideo();
  } catch (err) {
    $('msg').className = 'search-msg err-text';
    $('msg').textContent = err.message;
  } finally {
    syncCheck();
  }
};

function showVideo() {
  $('v-thumb').src = video.thumbnail;
  $('v-title').textContent = video.title;
  $('v-title').title = video.title;
  const best = video.qualities[0];
  $('v-meta').textContent = [video.uploader, video.duration ? fmtDur(video.duration) : '',
    best ? 'maks ' + qName(best.height) + (best.fps > 30 ? best.fps : '') : ''].filter(Boolean).join(' · ');
  height = 0;
  $('video').hidden = false;
  // Di modal Download: panel unduh langsung terbuka, tombol pilihan disembunyikan
  $('video').querySelector('.choices').hidden = urlMode === 'download';
  $('dl-panel').hidden = urlMode !== 'download';
  $('go-download').classList.toggle('on', urlMode === 'download');
  if (urlMode === 'download') { renderQualities(); renderFormats(); }
}


$('go-download').onclick = () => {
  const open = $('dl-panel').hidden;
  $('dl-panel').hidden = !open;
  $('go-download').classList.toggle('on', open);
  if (open) { renderQualities(); renderFormats(); }
};

function renderQualities() {
  const chips = [{ h: 0, label: 'Terbaik', sub: '' }].concat(video.qualities.map(q => ({
    h: q.height, label: qName(q.height) + (q.fps > 30 ? q.fps : ''), sub: q.size ? '~' + fmtSize(q.size) : '',
  })));
  $('qualities').innerHTML = chips.map(c =>
    `<button class="chip ${c.h === height ? 'on' : ''}" data-h="${c.h}">${esc(c.label)}${c.sub ? `<small>${esc(c.sub)}</small>` : ''}</button>`).join('');
}
$('qualities').onclick = e => {
  const b = e.target.closest('[data-h]');
  if (b) { height = +b.dataset.h; renderQualities(); }
};

function renderFormats() {
  $('formats').innerHTML = FORMATS.map(f => `<label class="format ${f.id === mode ? 'on' : ''}">
    <input type="radio" name="fmt" value="${f.id}" ${f.id === mode ? 'checked' : ''}>
    <div><b>${esc(f.name)}</b><span>${esc(f.desc)}</span></div></label>`).join('');
  $('quality-row').hidden = mode.startsWith('audio');
}
$('formats').onchange = e => { mode = e.target.value; renderFormats(); };

$('start').onclick = async () => {
  $('start').disabled = true;
  try {
    await api('start', { url: video.url, mode, height: mode.startsWith('audio') ? 0 : height,
      title: video.title, thumbnail: video.thumbnail });
    $('video').hidden = true;
    $('url').value = ''; syncCheck();
    closeUrl();
    setTab('downloads');
    toast('Download dimulai');
    loadJobs();
  } catch (err) {
    toast(err.message);
  } finally {
    $('start').disabled = false;
  }
};

/* ---------- Modal pengaturan Buat Clip ---------- */

const PRESETS = [
  { min: 15, max: 30, label: 'Singkat & padat' },
  { min: 30, max: 60, label: 'Umum Reels/TikTok' },
  { min: <?= CLIP_MIN_SEC ?>, max: <?= CLIP_MAX_SEC ?>, label: 'Rekomendasi' },
  { min: 60, max: 90, label: 'Lebih lengkap' },
  { min: 90, max: 180, label: 'Pembahasan dalam' },
  { custom: true, label: 'Atur sendiri' },
];
const clipCfg = { preset: 2, min: <?= CLIP_MIN_SEC ?>, max: <?= CLIP_MAX_SEC ?>, count: 0, touched: false, account: store.get('clip-account') || '',
  style: store.get('clip-style') === 'gaming' ? 'gaming' : 'podcast', lang: ['id', 'en'].includes(store.get('clip-lang')) ? store.get('clip-lang') : 'auto' };
const CONCEPTS = <?= json_encode(array_map(fn($g) => array_map(fn($c) => ['n' => $c[0], 'd' => $c[1]], $g), CLIP_CONCEPTS), JSON_UNESCAPED_UNICODE) ?>;
// Konsep terpilih diingat per style (podcast / game); kosong = AI bebas memilih momen terbaik
const conceptPick = style => { try { const v = JSON.parse(store.get('clip-concepts-' + style) || '[]'); return Array.isArray(v) ? v.filter(k => CONCEPTS[style][k]) : []; } catch { return []; } };
const CAP_STYLES = <?= json_encode(array_map(fn($c) => ['n' => $c[0], 'd' => $c[1]], CAPTION_STYLES), JSON_UNESCAPED_UNICODE) ?>;
// Gaya caption diingat per akun tujuan (tiap akun biasanya punya "suara" sendiri)
const capStylePick = () => { const v = store.get('cap-style-' + (clipCfg.account || 'all')); return CAP_STYLES[v] ? v : 'auto'; };
const LANG_NAMES = { en: 'English', id: 'Indonesia', ja: 'Jepang', ko: 'Korea', es: 'Spanyol', ar: 'Arab', zh: 'Mandarin', hi: 'Hindi', ms: 'Melayu' };

// Perkiraan jumlah clip dari durasi video: kira-kira 1 clip tiap 3x panjang clip, dibatasi supaya
// total durasi clip tidak lebih dari ±60% video
function clipLimits() {
  const dur = video?.duration || 0, avg = (clipCfg.min + clipCfg.max) / 2;
  if (!dur) return { est: 5, max: 20 };
  const max = Math.max(1, Math.min(20, Math.floor(dur * 0.6 / avg)));
  return { est: Math.max(1, Math.min(max, 15, Math.ceil(dur / (avg * 3)))), max };
}

// Akun tujuan wajib dipilih; kalau belum punya akun, form akun baru langsung dibuka
const accOk = () => accounts.some(a => a.id === clipCfg.account);
function renderAccounts() {
  $('accs').innerHTML = accounts.map(a => `<button class="acc-opt ${a.id === clipCfg.account ? 'on' : ''}" data-acc="${a.id}" type="button">
      ${avatar(a)}<span><b>${esc(a.name)}</b><small>${esc(a.niche || (a.handle ? '@' + a.handle : 'Tanpa niche'))}</small></span></button>`).join('')
    + `<button class="acc-opt add" data-acc-new type="button"><span class="av">+</span><span><b>Akun baru</b></span></button>`;
  if (!accounts.length) {
    $('acc-new').hidden = false;
    $('an-hint').textContent = 'Buat akun dulu, mis. akun self development atau akun clip game.';
  }
}
function renderModal() {
  const dur = video?.duration || 0;
  renderAccounts();
  document.querySelectorAll('#styles [data-style]').forEach(b => {
    b.classList.toggle('on', b.dataset.style === clipCfg.style);
    b.setAttribute('aria-pressed', b.dataset.style === clipCfg.style);
  });
  // Konsep clip sesuai style yang dipilih
  const picked = conceptPick(clipCfg.style), C = CONCEPTS[clipCfg.style];
  $('concepts').innerHTML = `<button type="button" class="all ${picked.length ? '' : 'on'}" data-concept=""><b><span class="ck">✓</span>Semua momen</b><small>AI pilih yang terbaik</small></button>`
    + Object.entries(C).map(([k, c]) => `<button type="button" class="${picked.includes(k) ? 'on' : ''}" data-concept="${k}" title="${esc(c.d)}">
      <b><span class="ck">✓</span>${esc(c.n)}</b><small>${esc(c.d)}</small></button>`).join('');
  // Gaya caption (satu pilihan)
  const cs = capStylePick();
  $('capstyles').innerHTML = Object.entries(CAP_STYLES).map(([k, c]) =>
    `<button type="button" class="${cs === k ? 'on' : ''}" data-capstyle="${k}"><b>${esc(c.n)}</b><small>${esc(c.d)}</small></button>`).join('');
  const acc = accounts.find(a => a.id === clipCfg.account);
  $('cap-hint').textContent = acc ? `diingat untuk ${acc.name.length > 22 ? acc.name.slice(0, 22) + '…' : acc.name}` : '';
  // Bahasa judul, hook layar, caption & hashtag. Otomatis: video English -> English, lainnya -> Indonesia
  const vl = video?.language || '', auto = vl ? (vl === 'en' ? 'en' : 'id') : '';
  $('lang-hint').textContent = vl ? `Bahasa video: ${LANG_NAMES[vl] || vl.toUpperCase()}` : '';
  $('langs').innerHTML = [['auto', 'Otomatis', auto ? `Ikuti video · ${auto === 'en' ? 'English' : 'Indonesia'}` : 'Ikuti bahasa video'],
    ['id', 'Indonesia', 'Selalu Indonesia'], ['en', 'English', 'Always in English']].map(([k, n, d]) =>
    `<button class="preset ${clipCfg.lang === k ? 'on' : ''}" data-lang="${k}" type="button"><b>${n}</b><small>${d}</small></button>`).join('');
  $('presets').innerHTML = PRESETS.map((p, i) => `<button class="preset ${i === clipCfg.preset ? 'on' : ''}" data-p="${i}"
      ${!p.custom && dur && p.min > dur ? 'disabled' : ''}>
      <b>${p.custom ? 'Kustom' : `${p.min}–${p.max} dtk`}</b><small>${p.label}</small></button>`).join('');
  $('custom').hidden = !PRESETS[clipCfg.preset].custom;
  const lim = clipLimits();
  if (!clipCfg.touched) clipCfg.count = lim.est;
  clipCfg.count = Math.max(1, Math.min(lim.max, clipCfg.count));
  $('cnt').textContent = clipCfg.count;
  $('cnt-dec').disabled = clipCfg.count <= 1;
  $('cnt-inc').disabled = clipCfg.count >= lim.max;
  $('count-hint').textContent = `maks. ${lim.max}`;
  $('cnt-auto').innerHTML = clipCfg.count === lim.est ? 'sesuai rekomendasi' : `<a href="#" id="cnt-reset" style="color:var(--accent-fg)">kembali ke rekomendasi (${lim.est})</a>`;
  const avg = (clipCfg.min + clipCfg.max) / 2;
  const total = clipCfg.count * avg;
  $('estimate').innerHTML = `<div class="big">Perkiraan hasil: <em>hingga ${clipCfg.count} clip</em> × ${clipCfg.min}–${clipCfg.max} detik</div>
    <p>Total ±${fmtDur(total)} dari video ${dur ? fmtDur(dur) : '(durasi tidak diketahui)'}${dur ? ` (±${Math.round(total / dur * 100)}% isi video)` : ''}.
      AI hanya memilih momen yang benar-benar layak, jadi jumlah akhirnya bisa lebih sedikit.</p>`;
  $('m-start').disabled = !(clipCfg.min >= 10 && clipCfg.max <= 180 && clipCfg.max - clipCfg.min >= 10) || !accOk();
  $('m-start').title = accOk() ? '' : 'Pilih akun tujuan dulu';
}

async function openModal() {
  await loadAccounts();
  if (!accOk()) clipCfg.account = accounts.length === 1 ? accounts[0].id : '';
  $('acc-new').hidden = true;
  $('m-video').textContent = `${video.title}${video.duration ? ' · ' + fmtDur(video.duration) : ''}`;
  clipCfg.touched = false;
  // Video lebih pendek dari preset terpilih: pindah ke preset terpanjang yang muat
  const dur = video.duration || 0;
  if (dur && !PRESETS[clipCfg.preset].custom && PRESETS[clipCfg.preset].min > dur) {
    clipCfg.preset = Math.max(0, PRESETS.findLastIndex(p => !p.custom && p.min <= dur));
    Object.assign(clipCfg, { min: PRESETS[clipCfg.preset].min, max: PRESETS[clipCfg.preset].max });
  }
  $('c-min').value = clipCfg.min; $('c-max').value = clipCfg.max;
  renderModal();
  $('clip-modal').hidden = false;
  $('m-start').focus();
}
const closeModal = () => { $('clip-modal').hidden = true; };

$('go-analyze').onclick = openModal;
$('m-close').onclick = $('m-cancel').onclick = closeModal;
$('clip-modal').addEventListener('click', e => { if (e.target === $('clip-modal')) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('clip-modal').hidden) closeModal(); });
function pickAccount(id) {
  clipCfg.account = id;
  store.set('clip-account', id);
  const a = accounts.find(x => x.id === id);
  if (a?.style) { clipCfg.style = a.style; store.set('clip-style', a.style); } // akun clip game → otomatis streaming game
  renderModal();
}
$('accs').onclick = e => {
  if (e.target.closest('[data-acc-new]')) {
    $('acc-new').hidden = false;
    $('an-name').focus();
    return;
  }
  const b = e.target.closest('[data-acc]');
  if (b) { $('acc-new').hidden = true; pickAccount(b.dataset.acc); }
};
$('an-cancel').onclick = () => { $('acc-new').hidden = !accounts.length ? false : true; };
$('an-save').onclick = async () => {
  const name = $('an-name').value.trim();
  if (!name) return $('an-name').focus();
  $('an-save').disabled = true;
  try {
    const { account } = await api('account_save', { name, niche: $('an-niche').value.trim() });
    accounts.push(account);
    $('an-name').value = $('an-niche').value = '';
    $('acc-new').hidden = true;
    pickAccount(account.id);
    toast(`Akun "${account.name}" dibuat`);
  } catch (err) { toast(err.message); }
  finally { $('an-save').disabled = false; }
};
$('acc-new').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('an-save').click(); } });
$('styles').onclick = e => {
  const b = e.target.closest('[data-style]');
  if (!b) return;
  clipCfg.style = b.dataset.style;
  store.set('clip-style', clipCfg.style);
  renderModal();
};
$('concepts').onclick = e => {
  const b = e.target.closest('[data-concept]');
  if (!b) return;
  const k = b.dataset.concept, cur = conceptPick(clipCfg.style);
  const next = !k ? [] : cur.includes(k) ? cur.filter(x => x !== k) : [...cur, k];
  store.set('clip-concepts-' + clipCfg.style, JSON.stringify(next));
  renderModal();
};
$('capstyles').onclick = e => {
  const b = e.target.closest('[data-capstyle]');
  if (!b) return;
  store.set('cap-style-' + (clipCfg.account || 'all'), b.dataset.capstyle);
  renderModal();
};
$('langs').onclick = e => {
  const b = e.target.closest('[data-lang]');
  if (!b) return;
  clipCfg.lang = b.dataset.lang;
  store.set('clip-lang', clipCfg.lang);
  renderModal();
};
$('presets').onclick = e => {
  const b = e.target.closest('[data-p]');
  if (!b) return;
  clipCfg.preset = +b.dataset.p;
  const p = PRESETS[clipCfg.preset];
  // Kustom mulai dari rentang yang sedang dipilih
  if (!p.custom) Object.assign(clipCfg, { min: p.min, max: p.max });
  else { $('c-min').value = clipCfg.min; $('c-max').value = clipCfg.max; }
  clipCfg.touched = false;
  renderModal();
  if (p.custom) $('c-min').focus();
};
$('custom').oninput = () => {
  clipCfg.min = Math.round(+$('c-min').value || 0);
  clipCfg.max = Math.round(+$('c-max').value || 0);
  renderModal();
};
$('cnt-dec').onclick = () => { clipCfg.count--; clipCfg.touched = true; renderModal(); };
$('cnt-inc').onclick = () => { clipCfg.count++; clipCfg.touched = true; renderModal(); };
$('cnt-auto').onclick = e => { if (e.target.id === 'cnt-reset') { e.preventDefault(); clipCfg.touched = false; renderModal(); } };

$('m-start').onclick = async () => {
  $('m-start').disabled = true;
  try {
    const { id } = await api('analyze', { url: video.url, title: video.title, thumbnail: video.thumbnail,
      clip_min: clipCfg.min, clip_max: clipCfg.max, max_clips: clipCfg.count, style: clipCfg.style, account_id: clipCfg.account, out_lang: clipCfg.lang,
      concepts: conceptPick(clipCfg.style), concept_note: $('concept-note').value.trim(), caption_style: capStylePick() });
    $('m-start').disabled = false;
    closeModal();
    openFlow(id);
    loadJobs();
  } catch (err) {
    toast(err.message);
    $('m-start').disabled = false;
  }
};

/* ---------- Timeline proses buat clip (setelah "Mulai buat clip") ---------- */
let flowId = null, flowPoll = null;
function openFlow(id) {
  flowId = id;
  $('fl-thumb').src = video?.thumbnail || '';
  $('fl-thumb').hidden = !video?.thumbnail;
  $('fl-video').textContent = video?.title || '';
  $('fl-go').href = 'studio.php?id=' + id;
  $('fl-steps').innerHTML = '<div class="empty" style="padding:18px"><span class="spin"></span></div>';
  $('flow-modal').hidden = false;
  pollFlow();
}
function closeFlow() { $('flow-modal').hidden = true; flowId = null; clearTimeout(flowPoll); }
$('fl-close').onclick = closeFlow;
$('flow-modal').addEventListener('click', e => { if (e.target === $('flow-modal')) closeFlow(); });
const FL_ACTIVE = ['queued', 'downloading', 'converting', 'analyzing'];
async function pollFlow() {
  if (!flowId) return;
  let d;
  try { d = await api('studio', null, '&id=' + flowId); } catch { flowPoll = setTimeout(pollFlow, 3000); return; }
  const A = d.analysis || {}, S = d.source, P = d.project, steps = [];
  const st = (title, state, sub = '', progress = null) => steps.push({ title, state, sub, progress });
  st('Membaca video', 'done', video?.duration ? fmtDur(video.duration) : '');
  if (A.status === 'done') st('Menganalisa dengan AI', A.clip_count ? 'done' : 'error', A.clip_count ? `${A.clip_count} momen terbaik ditemukan` : 'AI tidak menemukan momen yang cocok');
  else if (FL_ACTIVE.includes(A.status)) st('Menganalisa dengan AI', 'active', A.stage || 'Menunggu…', A.progress || 0);
  else st('Menganalisa dengan AI', 'error', A.error || 'Analisa gagal');
  if (S?.status === 'done') st('Mengunduh video sumber', 'done', S.size ? fmtSize(S.size) : '');
  else if (S && FL_ACTIVE.includes(S.status)) st('Mengunduh video sumber', 'active', [S.stage, S.speed].filter(Boolean).join(' · '), S.progress || 0);
  else if (S?.status === 'error') st('Mengunduh video sumber', 'error', S.error || 'Unduhan gagal');
  else st('Mengunduh video sumber', 'pending', 'Menunggu…');
  const items = P?.items || [], js = items.map(it => d.jobs?.[it.job_id] || {});
  const done = js.filter(j => j.status === 'done').length;
  if (!items.length) st('Memotong & merender clip', 'pending', 'Setelah analisa selesai');
  else if (done === items.length) st('Memotong & merender clip', 'done', `${done} clip selesai dirender`);
  else st('Memotong & merender clip', 'active', `${done} dari ${items.length} clip selesai`, done / items.length * 100);
  st('Siap diedit & diunduh', items.length && done === items.length ? 'done' : 'pending', 'Buka Clip Studio untuk melihat, mengedit, & mengunduh clip');
  if (P?.id) $('fl-go').href = 'studio.php?id=' + P.id;
  setHTML($('fl-steps'), steps.map((s, i) => `<div class="fl-step ${s.state}"><span class="fl-dot">${s.state === 'done' ? ic('check') : s.state === 'active' ? '<span class="spin" style="width:13px;height:13px"></span>' : s.state === 'error' ? '!' : i + 1}</span>
    <div><b>${esc(s.title)}</b>${s.sub ? `<small>${esc(s.sub)}</small>` : ''}${s.progress != null ? `<div class="bar"><i style="width:${Math.max(3, Math.min(100, s.progress))}%"></i></div>` : ''}</div></div>`).join(''));
  const finished = steps.some(s => s.state === 'error') || steps[steps.length - 1].state === 'done';
  if (!finished) flowPoll = setTimeout(pollFlow, 2000);
}

/* ---------- Tab Project: project manual (project.php) ---------- */
async function loadProjects() {
  let d;
  try { d = await api('mproj_list'); } catch { return; }
  $('c-projects').textContent = d.projects.length || '';
  setHTML($('p-projects'), `<div class="rooms">
    <button type="button" class="card room new-proj" data-newproj style="text-align:left;font:inherit;color:inherit;cursor:pointer;padding:0">
      <div class="room-thumb" style="display:grid;place-items:center;color:var(--muted)">${ic('plus').replace('class="i"', 'class="i" style="width:30px;height:30px"')}</div>
      <div class="room-body"><div class="room-title">Project baru</div><div class="room-meta"><span>Edit manual dari footage sendiri</span></div></div></button>
    ${d.projects.map(p => `<a class="card room" href="project.php?id=${p.id}">
      <div class="room-thumb">${p.thumb ? `<img src="${esc(p.thumb)}" alt="" loading="lazy">` : ''}<span class="pill">${ic('film')} ${p.clips} potongan</span></div>
      <div class="room-body"><div class="room-title">${esc(p.name)}</div><div class="room-meta"><span>${p.ratio} · ${fmtDur(p.duration)}${p.renders ? ` · ${p.renders} render` : ''}</span></div></div></a>`).join('')}</div>`);
}

/* ---------- Tab Room & Unduhan ---------- */

function setTab(t) {
  tab = t;
  store.set('tab', t);
  document.querySelectorAll('#tabs .tab').forEach(b => b.classList.toggle('on', b.dataset.tab === t));
  $('p-studio').hidden = t !== 'studio';
  $('p-downloads').hidden = t !== 'downloads';
  $('p-projects').hidden = t !== 'projects';
  if (t === 'projects') loadProjects();
  // Tombol hapus room hanya untuk tab Clip Studio
  if (t !== 'studio' && selecting) setSelecting(false);
  $('sel-toggle').hidden = t !== 'studio' || !Object.keys(rooms).length;
}
$('tabs').onclick = e => { const b = e.target.closest('[data-tab]'); if (b) setTab(b.dataset.tab); };

/* ---------- Hapus room: mode pilih, bar melayang, konfirmasi ---------- */
// Satu room = analisa video + semua proyek clip dari analisa itu
let rooms = {}, picked = new Set(), selecting = false;
const roomKey = (j, p) => (j || p).kind === 'analyze' ? (j || p).id : (p.analysis_id || p.id);
function setSelecting(on) {
  selecting = on;
  if (!on) picked.clear();
  document.body.classList.toggle('selecting', on);
  $('p-studio').classList.toggle('selecting', on);
  $('sel-toggle').classList.toggle('on', on);
  $('selbar').hidden = !on;
  paintPicked();
}
function paintPicked() {
  document.querySelectorAll('.room[data-room]').forEach(c => c.classList.toggle('picked', picked.has(c.dataset.room)));
  const n = picked.size, total = Object.keys(rooms).length;
  $('sel-cnt').textContent = n ? `${n} room dipilih` : 'Pilih room';
  $('sel-del').disabled = !n;
  $('sel-del').innerHTML = `${ic('trash')} Hapus${n ? ` (${n})` : ''}`;
  $('sel-all').textContent = n && n === total ? 'Batalkan semua' : 'Pilih semua';
}
$('sel-toggle').onclick = () => { if (tab !== 'studio') setTab('studio'); setSelecting(!selecting); };
$('sel-cancel').onclick = () => setSelecting(false);
$('sel-all').onclick = () => {
  const all = Object.keys(rooms);
  if (picked.size === all.length) picked.clear(); else all.forEach(k => picked.add(k));
  paintPicked();
};
$('p-studio').addEventListener('click', e => {
  const card = e.target.closest('.room[data-room]');
  if (!selecting || !card) return;
  e.preventDefault();
  const k = card.dataset.room;
  if (picked.has(k)) picked.delete(k); else picked.add(k);
  paintPicked();
});
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!$('del-modal').hidden) $('del-modal').hidden = true;
  else if (selecting) setSelecting(false);
});
$('sel-del').onclick = () => {
  const n = picked.size;
  if (!n) return;
  $('del-title').textContent = `Hapus ${n} room?`;
  $('del-text').textContent = `Semua clip, hasil render, dan editan di ${n > 1 ? 'room-room' : 'room'} ini akan dihapus permanen. Tindakan ini tidak bisa dibatalkan.`;
  $('del-modal').hidden = false;
  $('del-no').focus();
};
$('del-no').onclick = () => { $('del-modal').hidden = true; };
$('del-modal').addEventListener('click', e => { if (e.target === $('del-modal')) $('del-modal').hidden = true; });
// Analisa yang masih berjalan dibatalkan dulu, lalu ditunggu sampai berhenti sebelum dihapus
async function waitStopped(id) {
  for (let k = 0; k < 20; k++) {
    const j = (await api('jobs')).jobs.find(x => x.id === id);
    if (!j || !ACTIVE.includes(j.status)) return;
    await new Promise(r => setTimeout(r, 500));
  }
}
$('del-yes').onclick = async () => {
  const keys = [...picked], btn = $('del-yes');
  btn.disabled = true;
  let done = 0, failed = 0;
  for (const k of keys) {
    btn.innerHTML = `<span class="spin"></span> Menghapus ${done + failed + 1}/${keys.length}…`;
    const r = rooms[k];
    try {
      for (const p of r.projects) await api('delete', { id: p.id });
      if (r.analysis) {
        if (ACTIVE.includes(r.analysis.status)) { await api('cancel', { id: r.analysis.id }); await waitStopped(r.analysis.id); }
        await api('delete', { id: r.analysis.id });
      }
      done++;
    } catch { failed++; }
  }
  btn.disabled = false;
  btn.innerHTML = `${ic('trash')} Hapus permanen`;
  $('del-modal').hidden = true;
  setSelecting(false);
  toast(failed ? `${done} room dihapus, ${failed} gagal` : `${done} room dihapus`);
  loadJobs();
};

// Kartu Clip Studio: proyek (sudah ada clip) atau analisa yang masih berjalan / gagal
function studioCard(j, p) {
  let badge, foot;
  if (p) {
    badge = `<span class="pill">🎬 ${p.clip_count} clip</span>${p.cleaned ? '<span class="pill cleaned" title="File berat sudah dibersihkan, riwayat clip tetap tersimpan">🧹 Dibersihkan</span>' : ''}`;
    foot = p.finished ? `<span class="fin">${ic('check')} Selesai</span><span>· ${fmtDate(p.finished)}</span>` : `<span>${fmtDate(p.created_at)}</span>`;
  } else if (ACTIVE.includes(j.status)) {
    badge = '<span class="pill"><span class="spin"></span> Menganalisa</span>';
    foot = `<span>${esc(j.stage)}</span>`;
  } else if (j.status === 'done') {
    badge = `<span class="pill">✨ ${j.clip_count || 0} momen</span>`;
    foot = '<span>Buka untuk mulai membuat clip</span>';
  } else {
    badge = '';
    foot = `<span class="err-text">${j.status === 'cancelled' ? 'Dibatalkan' : 'Analisa gagal'}</span>`;
  }
  const x = p || j, key = roomKey(j, p);
  const acc = accounts.find(a => a.id === j?.account_id);
  return `<a class="card room${picked.has(key) ? ' picked' : ''}" href="studio.php?id=${x.id}" data-room="${key}">
    <div class="room-thumb">${x.thumbnail ? `<img src="${esc(x.thumbnail)}" alt="" loading="lazy" onerror="this.remove()">` : ''}${badge}<span class="pick">${ic('check')}</span>
      ${acc ? `<span class="acc-tag">${avatar(acc)}<span>${esc(acc.name)}</span></span>` : ''}</div>
    <div class="room-body">
      <div class="room-title">${esc(x.title || x.url)}</div>
      <div class="room-meta">${foot}</div>
      ${!p && ACTIVE.includes(j.status) ? `<div class="progress"><i style="width:${j.progress || 0}%"></i></div>` : ''}
    </div></a>`;
}

async function loadJobs() {
  let data;
  try { data = await api('jobs'); } catch { return; }
  const analyses = data.jobs.filter(j => j.kind === 'analyze');
  const projects = data.jobs.filter(j => j.kind === 'project');
  // Satu kartu per video: proyek terbaru per analisa, atau analisanya sendiri kalau proyek belum dibuat
  const byAnalysis = {};
  projects.forEach(p => { if (!byAnalysis[p.analysis_id]) byAnalysis[p.analysis_id] = p; });
  const studio = [...projects.filter(p => !analyses.some(r => r.id === p.analysis_id)).map(p => [null, p]),
    ...analyses.map(r => [r, byAnalysis[r.id] || null])]
    .sort((a, b) => ((b[1] || b[0]).created_at || 0) - ((a[1] || a[0]).created_at || 0));
  // Clip milik proyek dikelola di Clip Studio, tidak ditampilkan di Unduhan
  const downloads = data.jobs.filter(j => !j.kind && !j.project_id);
  $('c-studio').textContent = studio.length || '';
  // Peta room → analisa & semua proyeknya (dipakai saat menghapus)
  rooms = {};
  studio.forEach(([r, p]) => { rooms[roomKey(r, p)] = { analysis: r, projects: [] }; });
  projects.forEach(p => { const k = p.analysis_id && rooms[p.analysis_id] ? p.analysis_id : p.id; if (rooms[k]) rooms[k].projects.push(p); });
  picked.forEach(k => { if (!rooms[k]) picked.delete(k); });
  $('sel-toggle').hidden = tab !== 'studio' || (!studio.length && !selecting);
  $('c-downloads').textContent = downloads.length || '';

  // Filter room per akun (hanya tampil kalau sudah ada akun)
  const accOf = ([r]) => accounts.some(a => a.id === r?.account_id) ? r.account_id : '';
  if (accFilter !== 'all' && accFilter !== '' && !accounts.some(a => a.id === accFilter)) accFilter = 'all';
  const shown = accFilter === 'all' ? studio : studio.filter(x => accOf(x) === accFilter);
  const nOf = id => studio.filter(x => accOf(x) === id).length;
  const filter = accounts.length ? `<div class="acc-filter" id="acc-filter">
      <button class="${accFilter === 'all' ? 'on' : ''}" data-filter="all">Semua <span class="n">${studio.length}</span></button>
      ${accounts.map(a => `<button class="${accFilter === a.id ? 'on' : ''}" data-filter="${a.id}">${avatar(a)}${esc(a.name)} <span class="n">${nOf(a.id)}</span></button>`).join('')}
      ${nOf('') ? `<button class="${accFilter === '' ? 'on' : ''}" data-filter="">Tanpa akun <span class="n">${nOf('')}</span></button>` : ''}
    </div>` : '';
  // Room yang sudah diselesaikan dipisah ke bagian bawah (terbaru diselesaikan di atas)
  const active = shown.filter(([, p]) => !p?.finished);
  const finished = shown.filter(([, p]) => p?.finished).sort((a, b) => b[1].finished - a[1].finished);
  const doneOpen = store.get('rooms-done-open') !== '0';
  setHTML($('p-studio'), studio.length
    ? `${filter}${active.length ? `<div class="rooms">${active.map(([r, p]) => studioCard(r, p)).join('')}</div>` : ''}
       ${!shown.length ? '<div class="empty"><b>Belum ada room di akun ini</b>Buat clip baru lalu pilih akun ini di "Atur clip".</div>'
         : !active.length ? '<div class="empty"><b>Semua room sudah selesai</b>Buat clip baru untuk memulai room berikutnya.</div>' : ''}
       ${finished.length ? `<section class="rooms-done${doneOpen ? '' : ' shut'}" id="rooms-done">
         <button type="button" class="rooms-done-h" data-a="done-toggle" aria-expanded="${doneOpen}">${ic('chevron-down')} Room selesai <span class="n">${finished.length}</span>
           <span class="hint">${doneOpen ? 'Sembunyikan' : 'Tampilkan'}</span></button>
         <div class="rooms">${finished.map(([r, p]) => studioCard(r, p)).join('')}</div></section>` : ''}`
    : '<div class="empty"><b>Belum ada clip</b>Cek sebuah video lalu pilih "Buat Clip AI".</div>');
  setHTML($('p-downloads'), downloads.length
    ? `<div class="jobs">${downloads.map(jobRow).join('')}</div>
       <p class="muted small" style="margin-top:12px">Folder: ${esc(data.download_dir)}</p>`
    : '<div class="empty"><b>Belum ada unduhan</b>Video dan clip yang kamu unduh akan muncul di sini.</div>');

  if (selecting) paintPicked();
  clearTimeout(polling);
  if (analyses.concat(downloads).some(j => ACTIVE.includes(j.status))) polling = setTimeout(loadJobs, 1500);
}

bindJobActions($('p-downloads'), loadJobs);
setTab(tab);
$('p-studio').addEventListener('click', e => {
  if (e.target.closest('[data-a="done-toggle"]')) {
    const open = $('rooms-done').classList.toggle('shut') === false;
    store.set('rooms-done-open', open ? '1' : '0');
    return loadJobs();
  }
  const b = e.target.closest('[data-filter]');
  if (!b) return;
  accFilter = b.dataset.filter;
  store.set('room-filter', accFilter);
  loadJobs();
});
loadAccounts().then(loadJobs);
loadProjects();
// Sorotan hover mengikuti kursor (posisi relatif kartu sebagai variabel CSS --mx / --my)
document.querySelectorAll('.act3, .camp-cta').forEach(el => el.addEventListener('pointermove', e => {
  const r = el.getBoundingClientRect();
  el.style.setProperty('--mx', (e.clientX - r.left) + 'px');
  el.style.setProperty('--my', (e.clientY - r.top) + 'px');
}));
// Kartu "Project baru": langsung buat project lalu buka editornya
$('p-projects').addEventListener('click', async e => {
  if (e.target.closest('[data-newproj]')) openNewProject();
});
// Dropdown Pengaturan: Manajemen storage / Kompresi hasil clip
(() => {
  const btn = $('set-btn'), menu = $('set-menu');
  const show = on => { menu.hidden = !on; btn.setAttribute('aria-expanded', on); };
  btn.onclick = e => { e.stopPropagation(); show(menu.hidden); if (!menu.hidden) menu.querySelector('a').focus(); };
  document.addEventListener('click', e => { if (!menu.hidden && !e.target.closest('#set-dd')) show(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !menu.hidden) { show(false); btn.focus(); } });
})();
</script>
</body>
</html>
