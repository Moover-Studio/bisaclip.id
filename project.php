<?php
// Edit manual: unggah footage → susun di timeline → teks, grafik, musik, transisi, preset → render kualitas pilihan
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
require_once __DIR__ . '/subtitles.php';
require_once __DIR__ . '/editor_lib.php'; // TRANSITION_PRESETS
$pid = (string) ($_GET['id'] ?? '');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Project · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
<?php foreach (SUB_FONT_LIST as $k => $_): if ($k !== 'arial-black'): ?>
  @font-face { font-family: "<?= sub_font_family($k) ?>"; src: url("assets/fonts/<?= $k ?>.ttf") format("truetype"); font-display: swap; }
<?php endif; endforeach ?>
<?php foreach (SUB_FONT_LIST as $k => [$label]): foreach (sub_font_weights($k) as $w): ?>
  @font-face { font-family: "ClipW <?= $label ?>"; src: url("assets/fonts/<?= $k ?>-w<?= $w ?>.ttf") format("truetype"); font-weight: <?= $w ?>; font-display: swap; }
<?php endforeach; endforeach ?>
  /* Ikon di dalam teks mengikuti ukuran huruf (ikon tombol tetap memakai ukuran dari app.css) */
  :where(#app, .modal) .i { width: 1.15em; height: 1.15em; flex: none; vertical-align: -0.2em; }
  /* ---------- Daftar project ---------- */
  .head { padding: 18px 20px; background: linear-gradient(120deg, rgba(0, 88, 211, .14), transparent 55%), var(--surface); }
  .head-row { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
  .head h1 { font-size: 20px; margin: 0; }
  .plist { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin-top: 16px; }
  .pcard { overflow: hidden; display: block; color: inherit; text-decoration: none; transition: border-color .15s; }
  .pcard:hover { border-color: var(--surface-3); }
  .pcard .th { aspect-ratio: 16/9; background: var(--surface-2) center / cover no-repeat; display: grid; place-items: center; color: var(--muted); }
  .pcard .th .i { width: 28px; height: 28px; }
  .pcard .bd { padding: 12px 14px 14px; } .pcard b { display: block; font-size: 14px; }
  .pcard small { display: block; font-size: 12px; color: var(--muted); margin-top: 4px; }

  /* ---------- Editor ---------- */
  body.ed-mode { overflow: hidden; }
  body.ed-mode .wrap { max-width: none; height: 100vh; display: flex; flex-direction: column; padding: 0 14px 12px; }
  body.ed-mode #app { flex: 1; min-height: 0; display: flex; flex-direction: column; }
  .ed-top { display: flex; align-items: center; gap: 8px; padding: 10px 0; flex: none; }
  .ed-top .name { font: inherit; font-size: 15px; font-weight: 700; color: var(--text); background: none; border: 1px solid transparent; border-radius: 8px; padding: 5px 8px; min-width: 0; width: min(380px, 36vw); }
  .ed-top .name:hover { border-color: var(--border); } .ed-top .name:focus { outline: none; border-color: var(--accent); background: var(--surface-2); }
  .ed-top .st { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 12px; white-space: nowrap; }
  .ed-top .st .i { width: 15px; height: 15px; } .ed-top .st.busy { color: var(--accent); } .ed-top .st.err { color: #ff8a80; }
  .ed-top .st.dirty { color: #ffc65c; }
  /* Tooltip mengambang (di luar panel supaya tidak terpotong); posisinya diatur JS agar selalu muat di layar — sama dengan editor clip */
  .tipbox { position: fixed; z-index: 60; width: max-content; max-width: 240px; padding: 8px 10px; background: var(--surface-2); color: var(--text-2);
    border: 1px solid var(--border-strong); font-size: 12px; font-weight: 500; line-height: 1.45; border-radius: 10px; pointer-events: none;
    opacity: 0; transform: translateY(4px) scale(.97); transition: opacity .16s ease, transform .2s cubic-bezier(.2, .8, .2, 1); }
  .tipbox.up { transform: translateY(-4px) scale(.97); }
  .tipbox.show { opacity: 1; transform: none; transition-delay: 90ms; }
  .tipbox kbd { font: inherit; font-size: 11px; padding: 0 5px; margin-left: 4px; border-radius: 5px; background: var(--surface-3); color: var(--text); }
  .ed-top .sp { flex: 1; }
  .ib { width: 34px; height: 34px; display: inline-grid; place-items: center; padding: 0; border: 1px solid transparent; border-radius: 9px; background: none; color: var(--text-2); cursor: pointer; transition: background .15s, color .15s, border-color .15s; }
  .ib:hover:not(:disabled) { background: var(--surface-2); color: var(--text); }
  .ib:disabled { opacity: .35; cursor: default; }
  .ib .i { width: 18px; height: 18px; pointer-events: none; }
  .ib.sm { width: 28px; height: 28px; border-radius: 8px; } .ib.sm .i { width: 15px; height: 15px; }
  .ib.on { background: var(--accent-soft); color: #8fb8ff; border-color: rgba(47, 123, 240, .35); }
  .ib.danger:hover:not(:disabled) { background: rgba(217, 58, 63, .15); color: #ff8a80; }
  .ed { flex: 1; min-height: 0; display: grid; grid-template-columns: 58px 268px minmax(0, 1fr) 276px; grid-template-rows: minmax(0, 1fr) auto; gap: 10px; }
  .pane { min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
  .pane-h { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 44px; padding: 8px 10px 6px 14px; font-size: 13px; font-weight: 700; flex: none; }
  .pane-h .t { display: flex; align-items: center; gap: 8px; min-width: 0; } .pane-h .t .i { width: 16px; height: 16px; color: var(--muted); }
  .pane-h .t span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .pane-h .acts { display: flex; gap: 2px; }
  .pane-b { flex: 1; min-height: 0; overflow-y: auto; padding: 2px 12px 12px; }
  /* Rail ikon menu kiri */
  .rail { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 8px 0; }
  .rail button { width: 48px; padding: 7px 0 5px; display: grid; justify-items: center; gap: 3px; border: 0; border-radius: 11px; background: none; color: var(--muted); font: inherit; font-size: 10px; font-weight: 600; cursor: pointer; transition: background .15s, color .15s; }
  .rail button .i { width: 20px; height: 20px; }
  .rail button:hover { color: var(--text); background: var(--surface-2); }
  .rail button.on { color: #8fb8ff; background: var(--accent-soft); }
  .rail button { position: relative; }
  .rail .cnt { position: absolute; top: 3px; right: 4px; min-width: 15px; height: 15px; padding: 0 4px; border-radius: 8px; background: var(--surface-3); color: var(--text-2); font-size: 9.5px; font-weight: 700; line-height: 15px; }
  .rail .cnt:empty { display: none; } .rail button.on .cnt { background: var(--accent); color: #fff; }
  /* Panel kiri: isi tiap menu bisa di-scroll sendiri */
  .lp-pane { flex: 1; min-height: 0; display: flex; flex-direction: column; }
  .lp-pane[hidden] { display: none; }
  /* Media */
  .drop { display: flex; align-items: center; justify-content: center; gap: 8px; border: 1.5px dashed var(--border-strong); border-radius: 11px; padding: 12px; color: var(--muted); font-size: 12px; font-weight: 600; cursor: pointer; transition: border-color .15s, background .15s, color .15s; }
  .drop:hover, .drop.over { border-color: var(--accent); background: var(--accent-soft); color: var(--text); }
  .drop .i { width: 18px; height: 18px; }
  .media { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
  .mc { position: relative; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: var(--surface-2); cursor: grab; }
  .mc.busy { cursor: default; }
  .mc .th { aspect-ratio: 16/10; background: #000 center / cover no-repeat; position: relative; }
  .mc .kind { position: absolute; left: 5px; top: 5px; display: grid; place-items: center; width: 20px; height: 20px; border-radius: 6px; background: rgba(0, 0, 0, .65); color: #fff; }
  .mc .kind .i { width: 12px; height: 12px; }
  .mc .dur { position: absolute; right: 5px; bottom: 5px; padding: 0 5px; border-radius: 5px; background: rgba(0, 0, 0, .7); color: #fff; font-size: 10.5px; font-variant-numeric: tabular-nums; }
  .mc .acts { position: absolute; right: 4px; top: 4px; display: flex; gap: 3px; opacity: 0; transition: opacity .15s; }
  .mc:hover .acts { opacity: 1; }
  .mc .acts button { width: 24px; height: 24px; display: grid; place-items: center; border: 0; border-radius: 7px; background: rgba(0, 0, 0, .7); color: #fff; cursor: pointer; }
  .mc .acts button:hover { background: var(--accent); } .mc .acts .rm:hover { background: #d93a3f; }
  .mc .acts .i { width: 13px; height: 13px; }
  .mc .nm { padding: 5px 7px 6px; font-size: 11px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .mc .bar { height: 3px; background: var(--surface-3); } .mc .bar i { display: block; height: 100%; background: var(--accent); transition: width .3s; }
  .mc .err { padding: 0 7px 6px; font-size: 10.5px; color: #ff8a80; }
  /* Template teks & grafik */
  .sec { font-size: 11px; font-weight: 700; color: var(--muted); letter-spacing: .04em; text-transform: uppercase; margin: 14px 2px 7px; }
  .sec:first-child { margin-top: 4px; }
  .gl { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
  .gl.one { grid-template-columns: 1fr; }
  .gt { display: grid; place-items: center; min-height: 64px; padding: 8px; border: 1px solid var(--border); border-radius: 10px; background: repeating-conic-gradient(#1b1d22 0 25%, #15171b 0 50%) 0 0 / 14px 14px; cursor: pointer; transition: border-color .15s, transform .15s; }
  .gt:hover { border-color: var(--accent); transform: translateY(-1px); }
  .gt img { max-width: 100%; max-height: 90px; display: block; }
  /* Transisi */
  .trg { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; }
  .trt { display: flex; align-items: center; gap: 8px; height: 40px; padding: 0 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); color: var(--text-2); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; transition: border-color .15s, background .15s; text-align: left; }
  .trt .sw { flex: none; width: 22px; height: 16px; border-radius: 4px; background: linear-gradient(90deg, #3b4252 50%, #2F7BF0 50%); }
  .trt.k-fadeblack .sw { background: linear-gradient(90deg, #3b4252, #000, #2F7BF0); } .trt.k-fadewhite .sw { background: linear-gradient(90deg, #3b4252, #fff, #2F7BF0); }
  .trt.k-fade .sw { background: linear-gradient(90deg, #3b4252, #2F7BF0); } .trt.k-circleopen .sw { background: radial-gradient(circle, #2F7BF0 40%, #3b4252 42%); }
  .trt.k-zoomin .sw { background: radial-gradient(circle, #3b4252 30%, #2F7BF0 70%); } .trt.k-slideright .sw { background: linear-gradient(90deg, #2F7BF0 50%, #3b4252 50%); }
  .trt.k-wipeleft .sw { background: linear-gradient(90deg, #3b4252 60%, #2F7BF0 60%); }
  .trt:hover { border-color: var(--border-strong); color: var(--text); }
  .trt.on { border-color: var(--accent); background: var(--accent-soft); color: #fff; }
  .row-acts { display: flex; gap: 6px; margin-top: 10px; } .row-acts .btn { flex: 1; justify-content: center; }
  /* Musik */
  .mlist { display: grid; gap: 4px; margin-top: 10px; }
  .ml { display: flex; align-items: center; gap: 6px; padding: 5px 5px 5px 6px; border-radius: 9px; transition: background .15s; }
  .ml:hover { background: var(--surface-2); }
  .ml .tx { flex: 1; min-width: 0; } .ml b { display: block; font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ml small { font-size: 10.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .ml .ib.on { background: var(--accent); color: #fff; border-color: transparent; }
  /* Preset */
  .ps { display: flex; align-items: center; gap: 8px; padding: 9px 8px 9px 11px; border: 1px solid var(--border); border-radius: 11px; background: var(--surface-2); margin-top: 7px; }
  .ps .tx { flex: 1; min-width: 0; } .ps b { display: block; font-size: 12.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ps .ch { display: flex; gap: 8px; margin-top: 3px; color: var(--muted); font-size: 11px; } .ps .ch span { display: inline-flex; align-items: center; gap: 3px; }
  .ps .ch .i { width: 12px; height: 12px; }
  .ps-new { display: flex; gap: 6px; margin-bottom: 4px; } .ps-new .input { flex: 1; min-width: 0; height: 34px; font-size: 12.5px; }
  .ps-new[hidden] { display: none; }
  .nil { display: grid; justify-items: center; gap: 6px; padding: 26px 10px; text-align: center; color: var(--muted); font-size: 12px; }
  .nil .i { width: 26px; height: 26px; opacity: .6; }
  /* Preview */
  .stage { display: flex; flex-direction: column; min-height: 0; padding: 10px; }
  .stage:fullscreen { background: #000; padding: 16px; border-radius: 0; }
  /* Flex + margin auto: saat preview diperbesar (zoom), layar bisa di-scroll ke semua sisi */
  .screen-wrap { flex: 1; min-height: 0; display: flex; overflow: auto; scrollbar-width: thin; }
  .screen { position: relative; flex: none; margin: auto; background: #000; border-radius: 10px; box-shadow: 0 10px 40px rgba(0, 0, 0, .45); }
  /* Area aman (safe area) + garis bantu tepi, muncul saat item digeser */
  .safe { position: absolute; z-index: 2; border: 1px dashed rgba(255, 255, 255, .35); border-radius: 4px; pointer-events: none; opacity: 0; transition: opacity .15s; }
  .safe.on { opacity: 1; }
  .tguide.gl, .tguide.gr { top: 0; bottom: 0; width: 1px; } .tguide.gt, .tguide.gb { left: 0; right: 0; height: 1px; }
  /* Toolbar kecil di atas objek terpilih */
  .ftool { position: absolute; z-index: 4; display: flex; gap: 2px; padding: 3px; border-radius: 9px; background: rgba(20, 22, 27, .92); border: 1px solid var(--border-strong); box-shadow: 0 6px 18px rgba(0, 0, 0, .4); transform: translateX(-50%); white-space: nowrap; }
  .ftool[hidden] { display: none; }
  .ftool button { display: inline-flex; align-items: center; gap: 5px; height: 26px; padding: 0 9px; border: 0; border-radius: 6px; background: none; color: var(--text-2); font: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; }
  .ftool button .i { width: 13px; height: 13px; }
  .ftool button:hover { background: var(--surface-3); color: #fff; } .ftool button.del:hover { background: rgba(217, 58, 63, .2); color: #ff8a80; }
  .tbox.edit { border-style: dashed; }
  .pv-load { position: absolute; inset: 0; z-index: 1; display: grid; place-content: center; justify-items: center; gap: 8px; color: var(--muted); font-size: 12px; background: rgba(0, 0, 0, .35); pointer-events: none; border-radius: 10px; }
  .pv-load[hidden] { display: none; }
  .pvz { display: inline-flex; align-items: center; gap: 2px; }
  .pvz .lv { min-width: 44px; height: 28px; padding: 0 6px; border: 0; border-radius: 7px; background: none; color: var(--muted); font: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; font-variant-numeric: tabular-nums; }
  .pvz .lv:hover { background: var(--surface-2); color: var(--text); }
  .screen canvas { display: block; width: 100%; height: 100%; border-radius: 10px; }
  /* Kotak transform: geser = pindah, sudut = ubah ukuran */
  .tbox { position: absolute; z-index: 3; border: 1.5px solid #5B9BFF; box-shadow: 0 0 0 1px rgba(0, 0, 0, .35); cursor: move; touch-action: none; }
  .tbox[hidden] { display: none; }
  .tbox.fixed i { display: none; } .tbox.fixed { cursor: ns-resize; }
  .gt.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
  .tbox i { position: absolute; width: 12px; height: 12px; margin: -6px 0 0 -6px; border-radius: 3px; background: #fff; border: 1.5px solid #1E70F0; box-shadow: 0 1px 4px rgba(0, 0, 0, .5); }
  .tbox i[data-h="nw"] { left: 0; top: 0; cursor: nwse-resize; } .tbox i[data-h="ne"] { left: 100%; top: 0; cursor: nesw-resize; }
  .tbox i[data-h="sw"] { left: 0; top: 100%; cursor: nesw-resize; } .tbox i[data-h="se"] { left: 100%; top: 100%; cursor: nwse-resize; }
  .tbox .tsize { position: absolute; left: 50%; top: -30px; transform: translateX(-50%); padding: 2px 8px; border-radius: 6px; background: #1E70F0; color: #fff;
    font-size: 11px; font-weight: 700; white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity .15s; }
  .tbox.act .tsize { opacity: 1; }
  .tguide { position: absolute; z-index: 2; background: rgba(255, 64, 129, .9); pointer-events: none; }
  .tguide.v { top: 0; bottom: 0; left: 50%; width: 1px; } .tguide.h { left: 0; right: 0; top: 50%; height: 1px; }
  .tguide[hidden] { display: none; }
  .screen .empty-s { position: absolute; inset: 0; display: grid; place-content: center; justify-items: center; gap: 8px; color: var(--muted); font-size: 12.5px; pointer-events: none; }
  .screen .empty-s .i { width: 30px; height: 30px; opacity: .5; }
  .transport { flex: none; display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 10px; position: relative; }
  .transport .time { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; min-width: 104px; text-align: center; }
  .transport .play { width: 42px; height: 42px; border-radius: 50%; background: #fff; color: #000; }
  .transport .play:hover { background: #dfe6f2; color: #000; }
  .transport .end { position: absolute; right: 0; display: flex; align-items: center; gap: 2px; }
  .transport .start { position: absolute; left: 0; }
  /* Panel properti */
  .ptabs { display: grid; grid-template-columns: 1fr 1fr; gap: 3px; margin: 8px 10px 0; padding: 3px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); flex: none; }
  .ptabs button { height: 28px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; border-radius: 7px; background: none; color: var(--muted); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; }
  .ptabs button .i { width: 14px; height: 14px; }
  .ptabs button:hover { color: var(--text); } .ptabs button.on { background: var(--surface-3); color: #fff; box-shadow: inset 0 0 0 1px var(--border-strong); }
  /* Panel Layer: urutan tumpuk, paling atas = paling depan */
  .lyg { margin-top: 12px; } .lyg:first-child { margin-top: 2px; }
  .lyg-h { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin: 0 2px 6px; }
  .lyg-h .i { width: 13px; height: 13px; }
  .lyi { display: flex; align-items: center; gap: 8px; padding: 5px 8px 5px 4px; border: 1px solid transparent; border-radius: 9px; cursor: pointer; transition: background .15s, border-color .15s; }
  .lyi:hover { background: var(--surface-2); } .lyi.on { border-color: var(--accent); background: var(--accent-soft); }
  .lyi .gr { color: var(--muted); cursor: grab; display: grid; place-items: center; } .lyi .gr .i { width: 15px; height: 15px; }
  .lyi .th { flex: none; width: 46px; height: 28px; border-radius: 5px; background: #15171b center / contain no-repeat; display: grid; place-items: center; overflow: hidden; }
  .lyi .th img { max-width: 100%; max-height: 100%; }
  .lyi .tx { flex: 1; min-width: 0; } .lyi b { display: block; font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lyi small { font-size: 10.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .lyi.drag { opacity: .4; } .lyi.over-t { box-shadow: inset 0 2px 0 var(--accent); } .lyi.over-b { box-shadow: inset 0 -2px 0 var(--accent); }
  /* Stepper waktu: − [m:ss.s] + */
  .stp { display: grid; grid-template-columns: 24px minmax(0, 1fr) 24px; align-items: center; gap: 2px; padding: 3px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-2); }
  .stp > span { grid-column: 1 / -1; padding: 1px 5px 0; font-size: 10.5px; color: var(--muted); }
  .stp button { height: 24px; border: 0; border-radius: 6px; background: none; color: var(--muted); font: inherit; font-size: 14px; line-height: 1; cursor: pointer; }
  .stp button:hover { background: var(--surface-3); color: var(--text); }
  .stp input { width: 100%; min-width: 0; height: 24px; border: 0; background: none; color: var(--text); font: inherit; font-size: 12.5px; text-align: center; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
  .stp input::-webkit-inner-spin-button { display: none; } .stp input:focus { outline: none; } .stp:focus-within { border-color: var(--accent); }
  .lbl { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 600; color: var(--muted); margin: 14px 0 6px; }
  .lbl:first-child { margin-top: 2px; } .lbl .i { width: 14px; height: 14px; }
  .seg { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 3px; padding: 3px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
  .seg.c4 { grid-template-columns: repeat(4, minmax(0, 1fr)); } .seg.c5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
  .seg button { white-space: nowrap; height: 28px; display: grid; place-items: center; border: 0; border-radius: 7px; background: none; color: var(--muted); font: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; }
  .seg button .i { width: 16px; height: 16px; }
  .seg button:hover { color: var(--text); } .seg button.on { background: var(--surface-3); color: #fff; box-shadow: inset 0 0 0 1px var(--border-strong); }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
  .num { display: flex; align-items: center; gap: 6px; padding: 6px 9px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-2); font-size: 11.5px; color: var(--muted); }
  .num input { width: 100%; min-width: 0; border: 0; background: none; color: var(--text); font: inherit; font-size: 12.5px; font-variant-numeric: tabular-nums; }
  .num input:focus { outline: none; }
  .rng { display: flex; align-items: center; gap: 9px; margin-top: 6px; } .rng input[type=range] { flex: 1; min-width: 0; }
  .rng > .i { width: 15px; height: 15px; color: var(--muted); opacity: .8; }
  .rng .v { width: 40px; text-align: right; font-size: 11.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  /* Slider lembut: track tipis redup, bagian terisi biru pudar, kenop kecil (--p = posisi nilai, diisi JS) */
  .props input[type=range], .tl-bar input[type=range] { -webkit-appearance: none; appearance: none; height: 18px; margin: 0; background: transparent; cursor: pointer; --p: 50%; }
  .props input[type=range]::-webkit-slider-runnable-track, .tl-bar input[type=range]::-webkit-slider-runnable-track { height: 3px; border-radius: 3px;
    background: linear-gradient(90deg, rgba(143, 184, 255, .55) var(--p), rgba(255, 255, 255, .09) var(--p)); }
  .props input[type=range]::-moz-range-track, .tl-bar input[type=range]::-moz-range-track { height: 3px; border-radius: 3px; background: rgba(255, 255, 255, .09); }
  .props input[type=range]::-moz-range-progress, .tl-bar input[type=range]::-moz-range-progress { height: 3px; border-radius: 3px; background: rgba(143, 184, 255, .55); }
  .props input[type=range]::-webkit-slider-thumb, .tl-bar input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 12px; height: 12px; margin-top: -4.5px; border: 0; border-radius: 50%;
    background: #dfe5ee; box-shadow: 0 1px 3px rgba(0, 0, 0, .45); transition: transform .15s, background .15s; }
  .props input[type=range]::-moz-range-thumb, .tl-bar input[type=range]::-moz-range-thumb { width: 12px; height: 12px; border: 0; border-radius: 50%; background: #dfe5ee; box-shadow: 0 1px 3px rgba(0, 0, 0, .45); }
  .props input[type=range]:hover::-webkit-slider-thumb, .tl-bar input[type=range]:hover::-webkit-slider-thumb { transform: scale(1.15); background: #fff; }
  .props input[type=range]:focus { outline: none; }
  .props input[type=range]:focus-visible::-webkit-slider-thumb { box-shadow: 0 0 0 3px rgba(91, 155, 255, .3); }
  /* Nilai yang bisa diketik (posisi): acuan persis dalam persen */
  .rng .vn { display: flex; align-items: center; width: 58px; height: 26px; padding: 0 6px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface-2); font-size: 11px; color: var(--muted); }
  .rng .vn input { width: 100%; min-width: 0; border: 0; background: none; color: var(--text); font: inherit; font-size: 11.5px; text-align: right; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
  .rng .vn input::-webkit-inner-spin-button { display: none; }
  .rng .vn input:focus { outline: none; } .rng .vn:focus-within { border-color: var(--accent); }
  .tools { display: flex; gap: 4px; margin-top: 8px; }
  .fld { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-2); color: var(--text); font: inherit; font-size: 12.5px; resize: vertical; }
  .fld:focus { outline: none; border-color: var(--accent); }
  .gpick { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 5px; }
  .gpick button { display: grid; grid-template-rows: 38px auto; place-items: center; gap: 3px; padding: 4px 4px 5px; border: 1px solid var(--border); border-radius: 8px; background: #15171b; color: var(--muted); font: inherit; font-size: 10.5px; font-weight: 600; cursor: pointer; }
  .gpick button.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); color: #fff; }
  .gpick img { max-width: 100%; max-height: 34px; }
  .lbl .cnt { margin-left: auto; font-weight: 500; font-variant-numeric: tabular-nums; }
  .hint { font-size: 11px; color: var(--muted); line-height: 1.45; margin-top: 5px; }
  .trow { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 6px; margin-top: 8px; }
  .selw { position: relative; display: flex; align-items: center; gap: 6px; padding: 0 8px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); color: var(--muted); }
  .selw .i { width: 15px; height: 15px; flex: none; }
  .selw select { flex: 1; min-width: 0; height: 34px; border: 0; background: none; color: var(--text); font: inherit; font-size: 12px; appearance: none; cursor: pointer; }
  .selw select:focus { outline: none; } .selw option { background: var(--surface-2); }
  .colrs { display: grid; gap: 5px; }
  .colr { display: grid; grid-template-columns: minmax(0, 1fr) 28px 76px; align-items: center; gap: 6px; font-size: 11.5px; color: var(--text-2); }
  .colr .sw { position: relative; width: 28px; height: 28px; border-radius: 8px; border: 1px solid var(--border-strong); background: var(--c); cursor: pointer; }
  .colr .sw input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
  .colr .hex { width: 100%; height: 28px; padding: 0 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface-2); color: var(--text); font: inherit; font-size: 12px; font-family: ui-monospace, monospace; text-transform: uppercase; }
  .colr .hex:focus { outline: none; border-color: var(--accent); }
  .chk { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12px; color: var(--text-2); cursor: pointer; }
  .chk input { accent-color: var(--accent); width: 15px; height: 15px; }
  .anims { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 4px; }
  .anims button { height: 30px; padding: 0 2px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface-2); color: var(--muted); font: inherit; font-size: 10.5px; font-weight: 600; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .anims button:hover { color: var(--text); } .anims button.on { border-color: var(--accent); background: var(--accent-soft); color: #fff; }
  .cols { display: flex; gap: 6px; flex-wrap: wrap; }
  .cols label { position: relative; width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border-strong); background: var(--c); cursor: pointer; }
  .cols input { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
  .rlist { display: grid; gap: 6px; }
  .rr { display: flex; align-items: center; gap: 6px; padding: 7px 8px 7px 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
  .rr .tx { flex: 1; min-width: 0; } .rr b { display: block; font-size: 12px; } .rr small { display: block; font-size: 10.5px; color: var(--muted); }
  /* Timeline */
  .tl-pane { grid-column: 1 / -1; padding: 0; }
  .tl-bar { display: flex; align-items: center; gap: 4px; padding: 5px 10px; border-bottom: 1px solid var(--border); font-size: 11.5px; color: var(--muted); }
  .tl-bar .sp { flex: 1; } .tl-bar .sep { width: 1px; height: 18px; background: var(--border); margin: 0 4px; }
  .tl-bar input[type=range] { width: 110px; }
  .tl-scroll { overflow-x: auto; overflow-y: auto; max-height: 36vh; padding: 0 12px 8px 0; scrollbar-width: thin; }
  .tl-row { display: flex; width: max-content; min-width: 100%; }
  /* Kepala track (ikon, mata, kunci, audio) tetap terlihat saat timeline digeser ke samping */
  .tl-heads { position: sticky; left: 0; z-index: 8; flex: none; width: 104px; background: var(--surface); border-right: 1px solid var(--border); }
  .thd { position: absolute; left: 0; right: 0; display: flex; align-items: center; gap: 1px; padding: 0 6px 0 10px; }
  .thd > .i { width: 15px; height: 15px; color: var(--muted); margin-right: auto; }
  .thd .sl { width: 24px; flex: none; }
  .thd button { width: 24px; height: 24px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 6px; background: none; color: var(--muted); cursor: pointer; }
  .thd button .i { width: 14px; height: 14px; pointer-events: none; }
  .thd button:hover { background: var(--surface-2); color: var(--text); }
  .thd button.on { color: #ffb02e; }
  .tl { position: relative; flex: 1; min-width: 0; margin-left: 10px; user-select: none; touch-action: none; }
  .ruler { position: absolute; left: 0; right: 0; top: 0; height: 22px; cursor: pointer; }
  .ruler i { position: absolute; bottom: 3px; width: 1px; height: 5px; background: var(--border-strong); }
  .ruler i.maj { height: 9px; background: var(--muted); }
  .ruler span { position: absolute; top: 2px; padding-left: 4px; font-size: 10.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .lane { position: absolute; left: 0; border-radius: 8px; background: rgba(255, 255, 255, .025); }
  .lane > .i { position: absolute; left: -2px; top: 50%; width: 13px; height: 13px; margin-top: -6.5px; color: var(--border-strong); opacity: 0; }
  .track { position: absolute; left: 0; height: 62px; border-radius: 10px; background: var(--surface-2); }
  .track.empty { display: flex; align-items: center; justify-content: center; gap: 8px; border: 1.5px dashed var(--border-strong); background: none; color: var(--muted); font-size: 12px; }
  .track.empty .i { width: 16px; height: 16px; }
  .track.over { box-shadow: inset 0 0 0 2px var(--accent); }
  .clip { position: absolute; top: 0; height: 62px; border-radius: 9px; overflow: hidden; cursor: grab; background: #222 0 0 / auto 100% repeat-x;
    box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .14); transition: box-shadow .12s; }
  .clip::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0, 0, 0, .55), transparent 45%); pointer-events: none; }
  .clip .cn { position: absolute; left: 8px; top: 5px; right: 8px; z-index: 1; font-size: 11px; font-weight: 700; color: #fff; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; pointer-events: none; }
  .clip .cd { position: absolute; left: 8px; bottom: 5px; z-index: 1; padding: 0 5px; border-radius: 5px; background: rgba(0, 0, 0, .65); color: #fff; font-size: 10.5px; pointer-events: none; font-variant-numeric: tabular-nums; }
  .clip.on { box-shadow: inset 0 0 0 3px #fff; z-index: 2; }
  .clip.ghost { opacity: .35; }
  .h { position: absolute; top: 0; bottom: 0; width: 10px; z-index: 2; cursor: ew-resize; background: rgba(255, 255, 255, .18); }
  .h::after { content: ""; position: absolute; left: 3.5px; top: 30%; bottom: 30%; width: 3px; border-radius: 2px; background: #fff; }
  .h.l { left: 0; } .h.r { right: 0; }
  .clip:not(.on) .h, .it:not(.on) .h { opacity: 0; } .clip:hover .h, .it:hover .h { opacity: 1; }
  .tz { position: absolute; z-index: 3; height: 62px; pointer-events: none; border-radius: 6px; background: repeating-linear-gradient(135deg, rgba(91, 155, 255, .35) 0 5px, rgba(91, 155, 255, .12) 5px 10px); box-shadow: inset 0 0 0 1px rgba(91, 155, 255, .7); }
  .jn { position: absolute; z-index: 4; width: 24px; height: 24px; margin: -12px 0 0 -12px; display: grid; place-items: center; padding: 0; border: 1.5px solid var(--border-strong); border-radius: 50%; background: var(--surface); color: var(--text-2); cursor: pointer; opacity: 0; transition: opacity .15s, background .15s, transform .15s; }
  .jn .i { width: 13px; height: 13px; pointer-events: none; }
  .tl:hover .jn, .jn.has { opacity: 1; }
  .jn:hover { transform: scale(1.12); background: var(--surface-3); color: #fff; }
  .jn.has { border-color: #5B9BFF; color: #8fb8ff; } .jn.on { background: var(--accent); border-color: #fff; color: #fff; }
  .it { position: absolute; height: 24px; display: flex; align-items: center; gap: 5px; padding: 0 12px; border-radius: 7px; overflow: hidden; font-size: 11px; font-weight: 600; color: #fff; cursor: grab; white-space: nowrap; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .15); }
  .it .i { width: 13px; height: 13px; flex: none; pointer-events: none; } .it span:not(.h) { overflow: hidden; text-overflow: ellipsis; pointer-events: none; }
  .it.ov { background: linear-gradient(180deg, #7a4cd9, #5d36b5); }
  .it.mu { background: linear-gradient(180deg, #16936b, #0f7353); }
  .it.pip { background: #2a3a5c 0 0 / auto 100% repeat-x; }
  .it.pip::before { content: ""; position: absolute; inset: 0; background: rgba(20, 40, 90, .55); pointer-events: none; }
  .it.pip > :not(.h) { position: relative; }
  .it.pip .h { opacity: 1; background: rgba(255, 255, 255, .28); }
  .it.hid { opacity: .35; }
  .it.lk, .clip.lk { cursor: not-allowed; }
  .it.lk .h, .clip.lk .h { display: none; }
  .track.hid .clip { opacity: .35; }
  .pipmark { position: absolute; width: 3px; margin-left: -1.5px; border-radius: 2px; background: #5B9BFF; box-shadow: 0 0 10px #5B9BFF; z-index: 5; pointer-events: none; }
  .pipmark::after { content: "Overlay"; position: absolute; left: 6px; top: 0; padding: 1px 6px; border-radius: 5px; background: #1E70F0; color: #fff; font-size: 10px; font-weight: 700; white-space: nowrap; }
  .lane.over { box-shadow: inset 0 0 0 2px #5B9BFF; background: rgba(91, 155, 255, .08); }
  .it.on { box-shadow: inset 0 0 0 2px #fff; z-index: 2; }
  .it .h { width: 8px; } .it .h::after { left: 2.5px; width: 3px; top: 25%; bottom: 25%; }
  .ins { position: absolute; width: 4px; margin-left: -2px; border-radius: 3px; background: var(--accent); box-shadow: 0 0 10px var(--accent); z-index: 5; pointer-events: none; }
  .phead { position: absolute; top: 4px; bottom: 0; width: 2px; margin-left: -1px; background: #fff; z-index: 6; pointer-events: none; }
  /* Kenop playhead bisa diseret; area sentuhnya lebih besar dari bentuknya */
  .ph-knob { position: absolute; top: -3px; left: -5.5px; width: 13px; height: 15px; border-radius: 3px 3px 7px 7px; background: #fff; pointer-events: auto; cursor: grab; transition: transform .12s; }
  .ph-knob::before { content: ""; position: absolute; inset: -6px -8px; }
  .ph-knob:hover { transform: scale(1.12); } body.dragging .ph-knob { cursor: grabbing; }
  /* Rentang putar (in/out): bisa ditarik ujungnya atau digeser utuh */
  .prange { position: absolute; top: 0; height: 22px; z-index: 5; background: rgba(91, 155, 255, .18); border-left: 2px solid #5B9BFF; border-right: 2px solid #5B9BFF; cursor: grab; }
  .prange::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 2px; background: #5B9BFF; }
  .prange i { position: absolute; top: 0; bottom: 0; width: 10px; cursor: ew-resize; }
  .prange i.l { left: -6px; } .prange i.r { right: -6px; }
  .prdim { position: absolute; top: 22px; bottom: 0; z-index: 4; background: rgba(0, 0, 0, .28); pointer-events: none; }
  .tl-bar .hint-t { font-size: 11.5px; color: var(--muted); opacity: .8; }
  .tl-bar .zv { min-width: 40px; text-align: right; font-size: 11.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .clip canvas.film { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
  .clip .cn, .clip .cd { z-index: 2; }
  body.dragging, body.dragging * { cursor: grabbing !important; }
  @media (max-width: 1180px) { .ed { grid-template-columns: 58px 230px minmax(0, 1fr); } .ed .props { display: none; } }
  /* Modal render */
  .modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 16px; background: rgba(0, 0, 0, .6); backdrop-filter: blur(4px); }
  .modal[hidden] { display: none; }
  .m-card { width: 100%; max-width: 480px; max-height: calc(100vh - 32px); overflow-y: auto; padding: 22px; }
  .m-card h3 { margin: 0 0 4px; font-size: 17px; } .m-card > p { margin: 0 0 14px; font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .qopt { display: grid; grid-template-columns: 20px minmax(0, 1fr); gap: 12px; align-items: flex-start; padding: 12px 14px; border: 1px solid var(--border); border-radius: 14px; margin-top: 8px; cursor: pointer; transition: border-color .15s, background .15s; }
  .qopt:hover { border-color: var(--border-strong); }
  .qopt.on { border-color: var(--accent); background: var(--accent-soft); }
  .qopt input { margin: 2px 0 0; accent-color: var(--accent); width: 16px; height: 16px; }
  .qopt b { display: flex; align-items: center; gap: 8px; font-size: 14px; flex-wrap: wrap; }
  .qopt small { display: block; font-size: 12px; color: var(--muted); margin-top: 3px; line-height: 1.45; }
  .qopt .chips { margin-top: 10px; }
  .qopt.ai.on { border-color: #f5a524; background: rgba(245, 165, 36, .1); }
  .tag { padding: 1px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700; background: var(--surface-3); color: var(--text-2); }
  .tag.rec { background: rgba(245, 165, 36, .2); color: #ffc65c; }
  .m-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
  .rprog.ok { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; color: #4fd28a; }
  .rprog.ok span { flex: 1; min-width: 160px; color: var(--text-2); }
  .rprog { margin-top: 16px; padding: 12px 14px; border-radius: 12px; background: var(--surface-2); border: 1px solid var(--border); font-size: 12.5px; }
  .rprog .bar { height: 5px; margin-top: 8px; border-radius: 4px; background: var(--surface-3); overflow: hidden; } .rprog .bar i { display: block; height: 100%; background: var(--accent); transition: width .3s; }
  .rprog.err { color: #ff8a80; }
</style>
</head>
<body>
<div class="wrap">
  <div id="app"><div class="empty"><span class="spin"></span></div></div>
</div>

<div class="modal" id="rmodal" hidden>
  <div class="card m-card" role="dialog" aria-modal="true" aria-labelledby="rm-title">
    <h3 id="rm-title">Render</h3>
    <p id="rm-sub"></p>
    <div id="rm-opts"></div>
    <div id="rm-prog"></div>
    <div class="m-foot"><button class="btn ghost" id="rm-close">Tutup</button><button class="btn primary" id="rm-go">Render</button></div>
  </div>
</div>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script src="assets/gfx.js?v=<?= filemtime(__DIR__ . '/assets/gfx.js') ?>"></script>
<script>
const PID = <?= json_encode($pid) ?>;
const TRANSITIONS = <?= json_encode(TRANSITION_PRESETS, JSON_UNESCAPED_UNICODE) ?>;
const HGI = 'assets/hgi.svg?v=<?= filemtime(__DIR__ . '/assets/hgi.svg') ?>';
// Ikon Hugeicons (sprite assets/hgi.svg)
const hi = n => `<svg class="i" aria-hidden="true"><use href="${HGI}#h-${n}"/></svg>`;
const CHUNK = 8 * 1024 * 1024;
const RATIO_WH = { '9:16': [9, 16], '16:9': [16, 9], '1:1': [1, 1], '4:5': [4, 5], '3:4': [3, 4] };
const fmtT = s => { s = Math.max(0, +s || 0); const m = Math.floor(s / 60); return `${m}:${(s % 60).toFixed(1).padStart(4, '0')}`; };
const rid = () => Math.random().toString(36).slice(2, 10);
const r3 = v => Math.round(v * 1000) / 1000;
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

/* ==================== Daftar project ==================== */
async function listView() {
  const back = `<a class="back" href="index.php" title="Beranda" aria-label="Beranda">${hi('back')}</a>`;
  const d = await api('mproj_list');
  $('app').innerHTML = `<header class="topbar">${back}<a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a></header>
    <div class="card head"><div class="head-row"><h1>Edit manual</h1>
      <button class="btn primary" id="new">${hi('add')} Project baru</button></div></div>
    ${d.projects.length ? `<div class="plist">${d.projects.map(p => `<a class="card pcard" href="project.php?id=${p.id}">
        <div class="th" style="${p.thumb ? `background-image:url('${esc(p.thumb)}')` : ''}">${p.thumb ? '' : hi('video')}</div>
        <div class="bd"><b>${esc(p.name)}</b><small>${p.ratio} · ${p.clips} potongan · ${fmtT(p.duration)}${p.renders ? ` · ${p.renders} render` : ''}</small></div></a>`).join('')}</div>`
      : `<div class="card" style="margin-top:16px"><div class="nil">${hi('folder')}<b>Belum ada project</b></div></div>`}`;
  $('new').onclick = openNewProject;
}

/* ==================== Editor manual ==================== */
// sel = { t: 'clip' | 'ov' | 'mu' | 'tr', id } — tr memakai cid potongan yang menerima transisi
let P = null, info = null, sel = null, pps = 40, zoom = 1, T = 0, playing = false, saveT = null, uploads = {}, poll = null;
let lp = 'media', trPreset = 'fade', musicLib = [], presets = [], stopAt = null;
// range = rentang putar (in/out); pvZoom = zoom preview (1 = pas); ptab = tab panel kanan
let range = null, pvZoom = 1, ptab = 'props', unsaved = false, saveVer = 0;
// "m:ss.s" atau detik → detik
const parseT = v => { v = String(v).trim().replace(',', '.'); if (v.includes(':')) { const [m, x] = v.split(':'); return (+m || 0) * 60 + (+x || 0); } return +v; };
const cv = () => $('cv');
const media = mid => P.media[mid];
const clipLen = c => Math.max(0.05, c.out - c.in);
const total = () => P.timeline.reduce((a, c) => a + clipLen(c), 0);
const startOf = i => P.timeline.slice(0, i).reduce((a, c) => a + clipLen(c), 0);
const selItem = () => !sel ? null : sel.t === 'ov' ? P.overlays.find(o => o.oid === sel.id) : sel.t === 'mu' ? P.music.find(m => m.id === sel.id)
  : sel.t === 'pip' ? P.pips.find(q => q.id === sel.id) : P.timeline.find(c => c.cid === sel.id);
// Track timeline: gfx (teks & grafik), pip (overlay media), video (footage utama), music
const TRACKS = [['gfx', 'gfx', 'Teks & grafik', ['hide', 'lock']], ['pip', 'pip', 'Overlay media', ['hide', 'lock', 'mute']],
  ['video', 'video', 'Video utama', ['hide', 'lock', 'mute']], ['music', 'music', 'Musik', ['lock', 'mute']]];
const TK_OF = { ov: 'gfx', pip: 'pip', clip: 'video', tr: 'video', mu: 'music' };
const trk = k => P.tracks[k];
const locked = t => !!trk(TK_OF[t])?.lock;
const isSel = (t, id) => !!sel && sel.t === t && sel.id === id;
function clipAt(t) {
  let acc = 0;
  for (let i = 0; i < P.timeline.length; i++) {
    const L = clipLen(P.timeline[i]);
    if (t < acc + L - 1e-4 || i === P.timeline.length - 1) return { i, local: Math.max(0, Math.min(L, t - acc)) };
    acc += L;
  }
  return null;
}
function select(s) {
  sel = s;
  renderTimeline(); renderProps(); draw();
}

/* ---------- Simpan & riwayat (undo / redo) ---------- */
const ovData = o => ({ oid: o.oid, gfx: o.gfx, file: o.file, s: o.s, e: o.e, x: o.x, y: o.y, w: o.w, opacity: o.opacity ?? 100, anim: o.anim || null });
const snap = () => JSON.stringify({ timeline: P.timeline, overlays: P.overlays.map(ovData), music: P.music, pips: P.pips, tracks: P.tracks, fit: P.fit, ratio: P.ratio });
const hist = { u: [], r: [], last: null };
let recT = null;
function record() {
  clearTimeout(recT); recT = null;
  const s = snap();
  if (s === hist.last) return;
  if (hist.last) { hist.u.push(hist.last); if (hist.u.length > 100) hist.u.shift(); hist.r = []; }
  hist.last = s;
  updHist();
}
function updHist() { $('b-undo') && ($('b-undo').disabled = !hist.u.length); $('b-redo') && ($('b-redo').disabled = !hist.r.length); }
function undo(redo) {
  const from = redo ? hist.r : hist.u, to = redo ? hist.u : hist.r;
  if (recT) record();
  if (!from.length) return toast(redo ? 'Tidak ada yang bisa diulangi' : 'Tidak ada yang bisa diurungkan');
  pause();
  to.push(hist.last);
  hist.last = from.pop();
  const d = JSON.parse(hist.last);
  const ratioChanged = d.ratio !== P.ratio;
  Object.assign(P, d);
  P.overlays.forEach(o => { if (!o.file) scheduleGfx(o, true); });
  if (sel && !selItem() && sel.t !== 'tr') sel = null;
  if (ratioChanged) sizeScreen();
  updHist(); renderTimeline(); renderProps(); seek(Math.min(T, total()));
  clearTimeout(saveT); saveT = setTimeout(doSave, 300);
}
async function doSave() {
  const ver = saveVer;
  setSt('busy');
  try {
    const d = await api('mproj_save', { id: PID, name: P.name, ratio: P.ratio, fit: P.fit, timeline: P.timeline, overlays: P.overlays.filter(o => o.file).map(ovData), music: P.music, pips: P.pips, tracks: P.tracks });
    // Media yang sedang diproses worker bisa berubah: ambil data media terbaru, susunan tetap milik editor
    P.media = d.project.media;
    if (ver === saveVer) unsaved = false;
    setSt(unsaved ? 'dirty' : 'ok');
  } catch (err) { toast(err.message); setSt('err'); }
}
// Status simpan di bar atas (teks, seperti editor clip): dirty → busy → ok / err
function setSt(st) {
  const s = $('save-st');
  if (!s) return;
  const [icon, text] = { dirty: ['edit', 'Ada perubahan yang belum disimpan'], busy: [null, 'Menyimpan…'], ok: ['check', 'Semua perubahan tersimpan'], err: ['close', 'Gagal menyimpan'] }[st];
  s.className = 'st ' + st;
  s.innerHTML = (icon ? hi(icon) : '<span class="spin" style="width:13px;height:13px"></span>') + `<span>${text}</span>`;
}
// Simpan otomatis (ditunda 0,5 dtk saat mengedit); save(true) langsung & bisa ditunggu (mis. sebelum render)
async function save(now) {
  // Satu langkah undo per jeda edit (geser slider / mengetik tidak membuat banyak langkah)
  clearTimeout(recT); recT = setTimeout(record, 400);
  if (now) record();
  clearTimeout(saveT);
  unsaved = true; saveVer++;
  setSt('dirty');
  if (now) { await flushGfx(); return doSave(); }
  saveT = setTimeout(doSave, 500);
}

async function load(first) {
  let d;
  try { d = await api('mproj_get', null, '&id=' + PID); }
  catch (err) { $('app').innerHTML = `<div class="card" style="margin-top:20px"><div class="nil"><b>Project tidak ditemukan</b>${esc(err.message)}</div></div>`; return; }
  info = d;
  if (first) {
    P = d.project;
    P.overlays = P.overlays || [];
    P.music = P.music || [];
    P.pips = P.pips || [];
    P.tracks = Object.fromEntries(TRACKS.map(([k]) => [k, { hide: false, lock: false, mute: false, ...(P.tracks?.[k] || {}) }]));
    build();
    hist.last = snap();
  } else { P.media = d.project.media; P.renders = d.project.renders; P.render_job = d.project.render_job; if (!P.ratio_set && d.project.ratio !== P.ratio) { P.ratio = d.project.ratio; sizeScreen(); } }
  renderMedia(); renderProps(); paintRender();
  clearTimeout(poll);
  const busy = Object.values(P.media).some(m => ['uploading', 'processing', 'queued'].includes(m.status)) || ['queued', 'converting'].includes(info.render?.status);
  if (busy || Object.keys(uploads).length) poll = setTimeout(() => load(), 1500);
}

const MENUS = [['media', 'Media', 'media'], ['text', 'Teks', 'text'], ['gfx', 'Grafik', 'gfx'], ['music', 'Musik', 'music'], ['tr', 'Transisi', 'transition'], ['preset', 'Preset', 'preset']];
function build() {
  document.body.classList.add('ed-mode');
  $('app').innerHTML = `
    <div class="ed-top">
      <a class="ib" href="project.php" title="Semua project" aria-label="Semua project">${hi('back')}</a>
      <input class="name" id="pname" value="${esc(P.name)}" maxlength="100" aria-label="Nama project">
      <span class="st" id="save-st">${hi('check')}<span>Semua perubahan tersimpan</span></span><span class="sp"></span>
      <button class="ib" id="b-undo" title="Urungkan (Ctrl+Z)" aria-label="Urungkan" disabled>${hi('undo')}</button>
      <button class="ib" id="b-redo" title="Ulangi (Ctrl+Shift+Z)" aria-label="Ulangi" disabled>${hi('redo')}</button>
      <button class="btn primary" id="btn-render">${hi('render')} Render</button>
    </div>
    <div class="ed">
      <nav class="card rail" id="rail">${MENUS.map(([k, t, i]) => `<button data-lp="${k}" title="${t}">${hi(i)}${t}<span class="cnt" id="cnt-${k}"></span></button>`).join('')}</nav>
      <section class="card pane lp">
        <div class="lp-pane" id="lp-media"><div class="pane-h"><span class="t">Media</span><span class="muted" id="m-count" style="font-weight:500;font-size:11.5px"></span></div>
          <div class="pane-b"><label class="drop" id="drop">${hi('upload')}Unggah<input type="file" id="file-in" accept="video/*,image/*,.mkv" multiple hidden></label>
            <div class="media" id="media"></div></div></div>
        <div class="lp-pane" id="lp-text" hidden><div class="pane-h"><span class="t">Teks</span></div><div class="pane-b"><div class="gl one" id="g-text"></div></div></div>
        <div class="lp-pane" id="lp-gfx" hidden><div class="pane-h"><span class="t">Grafik</span></div><div class="pane-b" id="g-gfx"></div></div>
        <div class="lp-pane" id="lp-music" hidden><div class="pane-h"><span class="t">Musik</span>
            <label class="ib sm" title="Unggah musik" aria-label="Unggah musik">${hi('upload')}<input type="file" id="mu-in" accept="audio/*" hidden></label></div>
          <div class="pane-b"><div class="mlist" id="mlist" style="margin-top:0"></div></div></div>
        <div class="lp-pane" id="lp-tr" hidden><div class="pane-h"><span class="t">Transisi</span></div>
          <div class="pane-b"><div class="trg" id="tr-grid"></div>
            <div class="row-acts"><button class="btn sm" id="tr-all" title="Pasang di semua sambungan">${hi('layers')} Semua</button>
              <button class="btn sm ghost" id="tr-none" title="Hapus semua transisi">${hi('delete')} Hapus</button></div></div></div>
        <div class="lp-pane" id="lp-preset" hidden><div class="pane-h"><span class="t">Preset</span>
            <button class="ib sm" id="ps-add" title="Simpan gaya project ini sebagai preset" aria-label="Simpan preset">${hi('add')}</button></div>
          <div class="pane-b"><form class="ps-new" id="ps-form" hidden><input class="input" id="ps-name" maxlength="60" placeholder="Nama preset" autocomplete="off">
            <button class="btn sm primary" title="Simpan">${hi('check')}</button></form><div id="ps-list"></div></div></div>
      </section>
      <section class="card stage" id="stage">
        <div class="screen-wrap" id="screen-wrap"><div class="screen" id="screen"><canvas id="cv"></canvas><div class="safe" id="safe"></div>
          <i class="tguide v" id="gv" hidden></i><i class="tguide h" id="gh" hidden></i><i class="tguide gl" id="g-l" hidden></i><i class="tguide gr" id="g-r" hidden></i><i class="tguide gt" id="g-t" hidden></i><i class="tguide gb" id="g-b" hidden></i>
          <div class="tbox" id="tbox" hidden><i data-h="nw"></i><i data-h="ne"></i><i data-h="sw"></i><i data-h="se"></i><span class="tsize" id="tsize"></span></div>
          <div class="ftool" id="ftool" hidden><button data-fa="edit" title="Edit teks (Enter / klik dua kali)">${hi('edit')}Edit</button><button class="del" data-fa="del" title="Hapus (Delete)">${hi('delete')}Hapus</button></div>
          <div class="pv-load" id="pv-load" hidden><span class="spin"></span>Memuat video…</div>
          <div class="empty-s" id="empty-s">${hi('video')}Seret footage ke timeline</div></div></div>
        <div class="transport">
          <span class="pvz start"><button class="ib sm" id="pv-out" title="Perkecil preview">${hi('zoomout')}</button><button class="lv" id="pv-fit" title="Pas di layar">Pas</button><button class="ib sm" id="pv-in" title="Perbesar preview (Ctrl + scroll)">${hi('zoomin')}</button></span>
          <button class="ib" id="b-start" title="Ke awal (Home)">${hi('tostart')}</button>
          <button class="ib" id="b-prev" title="Potongan sebelumnya (↑)">${hi('prev')}</button>
          <button class="ib play" id="b-play" title="Putar / jeda (Spasi)">${hi('play')}</button>
          <button class="ib" id="b-next" title="Potongan berikutnya (↓)">${hi('next')}</button>
          <button class="ib" id="b-end" title="Ke akhir (End)">${hi('toend')}</button>
          <span class="time" id="time"></span>
          <span class="end"><button class="ib" id="b-full" title="Layar penuh (F / &#96;)">${hi('full')}</button></span>
        </div>
      </section>
      <section class="card pane props" id="props-pane"><div class="ptabs" id="ptabs"><button data-pt="props" class="on">${hi('settings')}Properti</button><button data-pt="layer">${hi('layers')}Layer</button></div>
        <div class="pane-h" id="props-h"></div><div class="pane-b" id="props"></div></section>
      <section class="card pane tl-pane">
        <div class="tl-bar">
          <button class="ib sm" id="b-split" title="Potong di playhead (S)">${hi('split')}</button>
          <button class="ib sm" id="b-dup" title="Duplikat (Ctrl+D)">${hi('copy')}</button>
          <button class="ib sm danger" id="b-del" title="Hapus (Delete)">${hi('delete')}</button>
          <button class="ib sm" id="b-range" title="Hapus rentang putar (atur dengan I / O)" disabled>${hi('ui-range')}</button>
          <span class="sep"></span><span id="tl-info"></span><span class="hint-t" id="tl-hint">Pilih item, taruh playhead, lalu potong</span><span class="sp"></span>
          <button class="ib sm" id="z-out" title="Perkecil timeline">${hi('zoomout')}</button>
          <input type="range" id="zoom" min="0.5" max="8" step="0.05" value="1" aria-label="Zoom timeline">
          <button class="ib sm" id="z-in" title="Perbesar timeline (Ctrl + scroll)">${hi('zoomin')}</button><span class="zv" id="z-val">100%</span></div>
        <div class="tl-scroll" id="tl-scroll"><div class="tl-row"><div class="tl-heads" id="tl-heads"></div><div class="tl" id="tl"></div></div></div>
      </section>
    </div>`;
  sizeScreen();
  new ResizeObserver(() => { sizeScreen(); renderTimeline(); }).observe($('screen-wrap'));
  bindEditor();
  try { const k = localStorage.getItem('mp-lp'); if (MENUS.some(m => m[0] === k)) lp = k; } catch {}
  showLp(lp);
  renderLeft();
  renderTimeline();
  seek(0);
}

/* ---------- Layar preview ---------- */
const SAFE_X = 64 / 1080, SAFE_Y = 192 / 1920, SNAP = 22 / 1080;
function setPvZoom(z) {
  pvZoom = clamp(Math.round(z * 100) / 100, 0.5, 3);
  if (Math.abs(pvZoom - 1) < 0.04) pvZoom = 1;
  sizeScreen();
}
function sizeScreen() {
  const [rw, rh] = RATIO_WH[P.ratio] || [9, 16], wrap = $('screen-wrap');
  if (!wrap) return;
  const W = wrap.clientWidth, H = wrap.clientHeight, k = Math.min(W / rw, H / rh) * pvZoom;
  const w = Math.floor(rw * k), h = Math.floor(rh * k);
  Object.assign($('screen').style, { width: w + 'px', height: h + 'px' });
  const c = cv(), dpr = Math.min(2, devicePixelRatio || 1, 2400 / Math.max(w, h));
  c.width = Math.round(w * dpr); c.height = Math.round(h * dpr);
  // Area aman: 64 px kiri/kanan & 192 px atas/bawah pada layar 1080×1920 (sama dengan editor clip)
  const sx = w * SAFE_X, sy = h * SAFE_Y;
  Object.assign($('safe').style, { left: sx + 'px', right: sx + 'px', top: sy + 'px', bottom: sy + 'px' });
  $('pv-fit').textContent = pvZoom === 1 ? 'Pas' : Math.round(pvZoom * 100) + '%';
  draw();
}
// Elemen video per media (preview ringan). Dua slot (urutan genap / ganjil) supaya potongan bersebelahan —
// termasuk dari media yang sama — bisa tampil bersamaan saat transisi.
const vids = new Map(), imgs = new Map();
function vidOf(m, slot = 0) {
  const key = m.mid + ':' + slot;
  let v = vids.get(key);
  if (!v) {
    v = document.createElement('video');
    v.preload = 'auto'; v.playsInline = true; v.src = m.preview;
    v.addEventListener('seeked', () => { if (!playing) draw(); });
    v.addEventListener('loadeddata', () => { if (!playing) draw(); });
    vids.set(key, v);
  }
  return v;
}
function imgOf(m) {
  let im = imgs.get(m.mid);
  if (!im) { im = new Image(); im.src = m.preview; im.onload = () => draw(); imgs.set(m.mid, im); }
  return im;
}
const srcOf = i => { const c = P.timeline[i], m = media(c.mid); return m.kind === 'image' ? imgOf(m) : vidOf(m, i % 2); };
const setTime = (v, t) => { if (v.tagName === 'VIDEO' && Math.abs(v.currentTime - t) > 0.01) v.currentTime = t; };
/*
 * Transform footage per potongan: tr = { s: ukuran (1 = pas layar / utuh), x, y: titik tengah (0..1 dari layar) }.
 * Tanpa tr: mengikuti mode isi layar (penuh / utuh). Rumus sama dengan mproj_graph() di mproject_lib.php.
 */
const coverRatio = (m, W, H) => Math.max(W / m.width, H / m.height) / Math.min(W / m.width, H / m.height);
function effTr(c, W, H) {
  if (c.tr) return c.tr;
  if (!W) [W, H] = RATIO_WH[P.ratio] || [9, 16];
  const m = media(c.mid), fit = c.fit || P.fit;
  return { s: fit === 'fill' ? coverRatio(m, W, H) : 1, x: 0.5, y: 0.5 };
}
// Kotak footage (x, y, w, h) di layar berukuran W×H
function layerRect(m, tr, W, H) {
  const k = Math.min(W / m.width, H / m.height), w = m.width * k * tr.s, h = m.height * k * tr.s;
  return { x: tr.x * W - w / 2, y: tr.y * H - h / 2, w, h };
}
// Gambar frame ke layar sesuai mode isi: penuh (crop), utuh + blur, utuh + hitam — sama dengan render
function paint(g, src, sw, sh, W, H, fit, tr, m) {
  g.fillStyle = '#000'; g.fillRect(0, 0, W, H);
  if (!sw || !sh) return;
  const cover = Math.max(W / sw, H / sh), contain = Math.min(W / sw, H / sh);
  if (tr) {
    if (fit !== 'black') {
      g.save(); g.filter = `blur(${Math.round(W / 30)}px) brightness(.85)`;
      g.drawImage(src, (W - sw * cover) / 2, (H - sh * cover) / 2, sw * cover, sh * cover); g.restore();
    }
    const r = layerRect(m, tr, W, H);
    g.drawImage(src, r.x, r.y, r.w, r.h);
    return;
  }
  if (fit === 'fill') { g.drawImage(src, (W - sw * cover) / 2, (H - sh * cover) / 2, sw * cover, sh * cover); return; }
  if (fit === 'blur') {
    g.save(); g.filter = `blur(${Math.round(W / 30)}px) brightness(.85)`;
    g.drawImage(src, (W - sw * cover) / 2, (H - sh * cover) / 2, sw * cover, sh * cover); g.restore();
  }
  g.drawImage(src, (W - sw * contain) / 2, (H - sh * contain) / 2, sw * contain, sh * contain);
}
function paintClip(g, i, W, H) {
  const c = P.timeline[i], m = media(c.mid), src = srcOf(i);
  paint(g, src, src.videoWidth || src.naturalWidth, src.videoHeight || src.naturalHeight, W, H, c.fit || P.fit, c.tr, m);
}
/* ---------- Transisi: berpusat di sambungan (sama dengan tpad + xfade di render) ---------- */
function tinDur(n) {
  const c = P.timeline[n];
  return n > 0 && c?.tin ? Math.min(c.tin.dur, clipLen(P.timeline[n - 1]), clipLen(c)) : 0;
}
// Transisi yang sedang berjalan di waktu t: n = potongan yang masuk, p = kemajuan 0..1, other = potongan yang frame-nya ditahan
function transAt(t) {
  const at = clipAt(t);
  if (!at) return null;
  const i = at.i, L = clipLen(P.timeline[i]);
  let d = tinDur(i);
  if (d && at.local < d / 2) return { n: i, p: 0.5 + at.local / d, other: i - 1, hold: 'last' };
  d = tinDur(i + 1);
  if (d && L - at.local < d / 2) return { n: i + 1, p: 0.5 - (L - at.local) / d, other: i + 1, hold: 'first' };
  return null;
}
const bufA = document.createElement('canvas'), bufB = document.createElement('canvas');
function blend(g, A, B, p, type, W, H) {
  g.save();
  g.clearRect(0, 0, W, H);
  switch (type) {
    case 'fadeblack': case 'fadewhite':
      g.drawImage(p < 0.5 ? A : B, 0, 0);
      g.fillStyle = type === 'fadeblack' ? '#000' : '#fff';
      g.globalAlpha = p < 0.5 ? p * 2 : (1 - p) * 2;
      g.fillRect(0, 0, W, H);
      break;
    case 'slideleft': g.drawImage(A, -p * W, 0); g.drawImage(B, (1 - p) * W, 0); break;
    case 'slideright': g.drawImage(A, p * W, 0); g.drawImage(B, -(1 - p) * W, 0); break;
    case 'wipeleft':
      g.drawImage(A, 0, 0);
      g.beginPath(); g.rect((1 - p) * W, 0, p * W, H); g.clip();
      g.drawImage(B, 0, 0);
      break;
    case 'circleopen':
      g.drawImage(A, 0, 0);
      g.beginPath(); g.arc(W / 2, H / 2, p * Math.hypot(W, H) / 2, 0, Math.PI * 2); g.clip();
      g.drawImage(B, 0, 0);
      break;
    case 'zoomin': {
      g.drawImage(B, 0, 0);
      const z = 1 + p;
      g.globalAlpha = 1 - p;
      g.drawImage(A, (W - W * z) / 2, (H - H * z) / 2, W * z, H * z);
      break;
    }
    default:
      g.drawImage(A, 0, 0);
      g.globalAlpha = p;
      g.drawImage(B, 0, 0);
  }
  g.restore();
}
/* ---------- Overlay media (footage / gambar di atas footage utama) ---------- */
function pipSrc(q) {
  const m = media(q.mid);
  if (!m) return null;
  if (m.kind === 'image') return imgOf(m);
  const key = 'pip:' + q.id;
  let v = vids.get(key);
  if (!v || v._mid !== q.mid) {
    v = document.createElement('video');
    v.preload = 'auto'; v.playsInline = true; v.src = m.preview; v._mid = q.mid;
    v.addEventListener('seeked', () => { if (!playing) draw(); });
    v.addEventListener('loadeddata', () => { if (!playing) draw(); });
    vids.set(key, v);
  }
  return v;
}
function pipRect(q, W, H) {
  const m = media(q.mid), w = q.w * W, h = w * (m?.height || 9) / (m?.width || 16);
  return { x: q.x * W - w / 2, y: q.y * H - h / 2, w, h };
}
const pipVisible = q => T >= q.s && T < Math.min(q.e, total());
// Posisi & suara tiap overlay media mengikuti waktu timeline (seperti musik)
function syncPips(force) {
  for (const q of P.pips) {
    const src = pipSrc(q);
    if (!src || src.tagName !== 'VIDEO') continue;
    if (!pipVisible(q)) { if (!src.paused) src.pause(); continue; }
    const want = q.in + (T - q.s);
    src.muted = trk('pip').mute;
    src.volume = Math.min(1, (q.vol ?? 100) / 100);
    if (!playing) { src.pause(); setTime(src, want); continue; }
    if (force || src.paused || Math.abs(src.currentTime - want) > 0.3) src.currentTime = want;
    if (src.paused) src.play().catch(() => {});
  }
  for (const [k, v] of vids) if (k.startsWith('pip:') && !P.pips.some(q => 'pip:' + q.id === k)) { v.pause(); vids.delete(k); }
}

/* ---------- Grafik & teks (overlay PNG) ---------- */
const ovImgs = new Map();
function ovImg(o) {
  if (o._prev) return o._prev;
  if (!o.file) return null;
  let im = ovImgs.get(o.file);
  if (!im) { im = new Image(); im.src = 'previews/overlays/' + o.file; im.onload = () => draw(); ovImgs.set(o.file, im); }
  return im.complete && im.naturalWidth ? im : null;
}
function ovRect(o, W, H) {
  const im = ovImg(o), iw = im ? im.naturalWidth || im.width : 900, ih = im ? im.naturalHeight || im.height : 260;
  const w = o.w * W, h = w * ih / iw;
  return { x: o.x * W - w / 2, y: o.y * H - h / 2, w, h };
}
// Muncul & hilang memudar — sama dengan fade alpha di render
/* Animasi masuk / keluar grafik & teks — sama dengan animPhase()/animAt() editor clip dan anim_expr() di render */
const ANIMS = [['none', 'Tidak ada'], ['fade', 'Fade'], ['up', 'Naik'], ['down', 'Turun'], ['left', 'Kiri'], ['right', 'Kanan'], ['zoom', 'Zoom'], ['pop', 'Pop']];
const ANIM_NONE = { in: 'none', out: 'none', din: 0.4, dout: 0.4 };
const GFX_ANIM = { in: 'up', out: 'fade', din: 0.5, dout: 0.4 };
function animPhase(kind, phase, p) {
  p = Math.min(1, Math.max(0, p));
  const D = 140, S = Math.sin, H = Math.PI / 2;
  if (phase === 'in') {
    const e = 1 - (1 - p) ** 3;
    return {
      fade: [0, 0, 1, p], up: [0, D * (1 - e), 1, p], down: [0, -D * (1 - e), 1, p], left: [D * (1 - e), 0, 1, p], right: [-D * (1 - e), 0, 1, p],
      zoom: [0, 0, 0.6 + 0.4 * e, p], pop: [0, 0, p < 0.6 ? Math.max(0.05, 1.12 * S(p / 0.6 * H)) : 1.12 - 0.12 * S((p - 0.6) / 0.4 * H), Math.min(1, p * 3)],
    }[kind] || [0, 0, 1, 1];
  }
  const i = p ** 3;
  return {
    fade: [0, 0, 1, 1 - p], up: [0, -D * i, 1, 1 - p], down: [0, D * i, 1, 1 - p], left: [-D * i, 0, 1, 1 - p], right: [D * i, 0, 1, 1 - p],
    zoom: [0, 0, 1 - 0.4 * i, 1 - p], pop: [0, 0, p < 0.35 ? 1 + 0.1 * S(p / 0.35 * H) : Math.max(0.05, 1.1 * (1 - S((p - 0.35) / 0.65 * H))), p < 0.6 ? 1 : 1 - (p - 0.6) / 0.4],
  }[kind] || [0, 0, 1, 1];
}
function animDur(a, len) {
  let din = a && a.in !== 'none' ? a.din : 0, dout = a && a.out !== 'none' ? a.dout : 0;
  if (din + dout > len * 0.9 && din + dout > 0) { const f = len * 0.9 / (din + dout); din *= f; dout *= f; }
  return [din, dout];
}
function animAt(a, s, e, time) {
  if (!a || (a.in === 'none' && a.out === 'none')) return { dx: 0, dy: 0, s: 1, a: 1 };
  const [din, dout] = animDur(a, e - s);
  const I = din > 0 ? animPhase(a.in, 'in', (time - s) / din) : [0, 0, 1, 1];
  const O = dout > 0 ? animPhase(a.out, 'out', (time - (e - dout)) / dout) : [0, 0, 1, 1];
  return { dx: I[0] + O[0], dy: I[1] + O[1], s: I[2] * O[2], a: Math.max(0, Math.min(1, I[3] * O[3])) };
}
const ovVisible = (o, t) => t >= o.s && t < o.e;
/*
 * Banner judul (template "full"): selebar layar, ukurannya tetap; pitanya selalu menjulur sampai bawah layar
 * dari posisi mana pun (sama dengan fitBand() di editor clip, dihitung untuk rasio project).
 */
const isFullGfx = o => !!o?.gfx && !!GFX[o.gfx.type]?.full;
const isHook = o => GFX[o?.gfx?.type]?.cat === 'Teks hook';
async function fitBand(o) {
  const g = o.gfx;
  if (!isFullGfx(o)) { g.fill = false; g.ext = 0; return; }
  o.w = 1; o.x = 0.5;
  if (g.fill === false) { g.ext = 0; return; }   // pita tidak diperpanjang (pilihan "Pita sampai bawah layar" dimatikan)
  const [rw, rh] = RATIO_WH[P.ratio] || [9, 16], SW = 1080, SH = SW * rh / rw;
  const sc = SW * o.w / (GFX_W + 36);                         // px layar per px desain (kanvas = lebar + margin 2×18)
  const h0 = (await gfxCanvas({ ...g, ext: 0 }, 1)).height;   // tinggi kanvas tanpa perpanjangan
  const top = o.y * SH - (h0 + Math.max(0, g.ext || 0)) * sc / 2;
  g.ext = Math.max(0, Math.min(4000, Math.ceil((SH - top) / sc - h0) + 4));
  o.y = Math.round((top + (h0 + g.ext) * sc / 2) / SH * 1e4) / 1e4;
}
const gfxJobs = new Map();
async function previewGfx(o) {
  await fitBand(o);
  o._prev = await gfxCanvas(o.gfx, 1);
  draw();
}
function scheduleGfx(o, now) {
  previewGfx(o);
  clearTimeout(o._t);
  o._t = setTimeout(() => uploadGfx(o), now ? 0 : 700);
}
async function uploadGfx(o) {
  o._t = null;
  const job = (async () => {
    await fitBand(o);
    const c = await gfxCanvas(o.gfx, 2);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    const fd = new FormData();
    fd.append('file', blob, 'grafik.png');
    const res = await fetch('api.php?action=overlay_upload', { method: 'POST', body: fd });
    const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
    if (!res.ok || d.error) throw new Error(d.error || 'Grafik gagal disimpan.');
    o.file = d.file;
    // Hanya file PNG yang berubah: bukan langkah undo baru
    if (!recT) hist.last = snap();
    clearTimeout(saveT); saveT = setTimeout(doSave, 300);
  })();
  gfxJobs.set(o.oid, job);
  try { await job; } catch (err) { toast(err.message); } finally { if (gfxJobs.get(o.oid) === job) gfxJobs.delete(o.oid); }
}
async function flushGfx() {
  P.overlays.forEach(o => { if (o._t) { clearTimeout(o._t); uploadGfx(o); } });
  await Promise.allSettled([...gfxJobs.values()]);
}

function draw() {
  const c = cv();
  if (!c || !P) return;
  const g = c.getContext('2d'), W = c.width, H = c.height;
  $('empty-s').hidden = P.timeline.length > 0;
  if (!P.timeline.length) $('pv-load').hidden = true;
  if (!P.timeline.length) { g.fillStyle = '#000'; g.fillRect(0, 0, W, H); updateTime(); boxShow(); return; }
  const at = clipAt(T), tr = transAt(T), src0 = srcOf(at.i);
  $('pv-load').hidden = trk('video').hide || src0.tagName !== 'VIDEO' || src0.readyState >= 2;
  if (trk('video').hide) { g.fillStyle = '#000'; g.fillRect(0, 0, W, H); }
  else if (!tr) paintClip(g, at.i, W, H);
  else {
    for (const b of [bufA, bufB]) if (b.width !== W || b.height !== H) { b.width = W; b.height = H; }
    paintClip(bufA.getContext('2d'), tr.n - 1, W, H);
    paintClip(bufB.getContext('2d'), tr.n, W, H);
    blend(g, bufA, bufB, tr.p, P.timeline[tr.n].tin.type, W, H);
  }
  if (!trk('pip').hide) for (const q of P.pips) {
    const src = pipSrc(q);
    if (!src || !pipVisible(q)) continue;
    const r = pipRect(q, W, H);
    try { g.drawImage(src, r.x, r.y, r.w, r.h); } catch {}
  }
  if (!trk('gfx').hide) for (const o of P.overlays) {
    const im = ovImg(o);
    if (!im || !ovVisible(o, T)) continue;
    const r = ovRect(o, W, H);
    // Saat jeda, grafik terpilih tampil penuh (tidak ikut fade) supaya mudah diatur
    const A = !playing && isSel('ov', o.oid) ? { dx: 0, dy: 0, s: 1, a: 1 } : animAt(o.anim, o.s, Math.min(o.e, total()), T), k = W / 1080;
    const cx = r.x + r.w / 2 + A.dx * k, cy = r.y + r.h / 2 + A.dy * k, w = r.w * A.s, h = r.h * A.s;
    g.save(); g.globalAlpha = A.a * (o.opacity ?? 100) / 100; g.drawImage(im, cx - w / 2, cy - h / 2, w, h); g.restore();
  }
  updateTime();
  boxShow();
}
// Kotak transform: grafik terpilih yang tampil, atau potongan terpilih yang sedang di layar (tidak saat diputar)
function boxShow() {
  const b = $('tbox');
  if (!b) return;
  const sc = $('screen'), W = sc.clientWidth, H = sc.clientHeight;
  let r = null, label = '';
  if (!playing && sel?.t === 'ov') {
    const o = selItem();
    if (o && ovVisible(o, T)) { r = ovRect(o, W, H); label = Math.round(o.w * 100) + '%'; }
  } else if (!playing && sel?.t === 'pip') {
    const q = selItem();
    if (q && pipVisible(q)) { r = pipRect(q, W, H); label = Math.round(q.w * 100) + '%'; }
  } else if (!playing && sel?.t === 'clip' && P.timeline.length) {
    const c = P.timeline[clipAt(T).i];
    if (c.cid === sel.id) { const tr = effTr(c, W, H); r = layerRect(media(c.mid), tr, W, H); label = Math.round(tr.s * 100) + '%'; }
  }
  const ft = $('ftool');
  if (!r) { b.hidden = true; ft.hidden = true; return; }
  b.classList.toggle('fixed', sel?.t === 'ov' && isFullGfx(selItem()));
  Object.assign(b.style, { left: r.x + 'px', top: r.y + 'px', width: r.w + 'px', height: r.h + 'px' });
  $('tsize').textContent = label;
  b.hidden = false;
  // Toolbar kecil di atas objek (pindah ke bawah kalau terlalu dekat tepi atas); disembunyikan saat digeser
  ft.hidden = b.classList.contains('act');
  ft.querySelector('[data-fa=edit]').hidden = sel.t !== 'ov';
  Object.assign(ft.style, { left: clamp(r.x + r.w / 2, 60, W - 60) + 'px', top: (r.y < 44 ? Math.min(H - 34, r.y + r.h + 8) : r.y - 38) + 'px' });
}
function updateTime() {
  $('time').textContent = `${fmtT(T)} / ${fmtT(total())}`;
  const ph = $('phead');
  if (ph) ph.style.left = T * pps + 'px';
  // Saat diputar, timeline ikut bergeser supaya playhead tetap terlihat (40 px dari tepi kiri)
  const sc = $('tl-scroll');
  if (playing && sc) {
    const x = $('tl').offsetLeft + T * pps, hw = $('tl-heads').offsetWidth;
    if (x > sc.scrollLeft + sc.clientWidth - 40 || x < sc.scrollLeft + hw) sc.scrollLeft = x - hw - 40;
  }
  if ($('b-split')) $('b-split').disabled = !canSplit();
}
// Potong hanya kalau playhead ada di dalam potongan (≥ 0,15 dtk dari ujungnya) dan track video tidak dikunci
function canSplit() {
  if (!P.timeline.length || trk('video').lock) return false;
  const at = clipAt(T), L = clipLen(P.timeline[at.i]);
  return at.local >= 0.15 && L - at.local >= 0.15;
}

/* ---------- Pemutaran ---------- */
let cur = null, lastTick = 0;
function seek(t) {
  T = Math.max(0, Math.min(total(), t));
  if (!P.timeline.length) { draw(); return; }
  const at = clipAt(T), cl = P.timeline[at.i], m = media(cl.mid);
  if (m.kind === 'video') {
    const v = vidOf(m, at.i % 2);
    if (cur && cur !== v) cur.pause();
    cur = v;
    setTime(v, cl.in + at.local);
    v.muted = trk('video').mute;
    if (playing) { v.volume = Math.min(1, (cl.vol ?? 100) / 100); v.play().catch(() => {}); }
  } else if (cur) { cur.pause(); cur = null; }
  holdOther();
  syncMusic(true);
  syncPips(true);
  draw();
}
// Saat transisi: potongan lain ditahan di frame pertama / terakhirnya
function holdOther() {
  const tr = transAt(T);
  if (!tr) return;
  const o = P.timeline[tr.other], src = srcOf(tr.other);
  if (src === cur) return;
  if (src.tagName === 'VIDEO') { src.pause(); setTime(src, tr.hold === 'first' ? o.in : Math.max(o.in, o.out - 0.04)); }
}
function play() {
  if (!P.timeline.length) return;
  // Rentang putar: mulai dari awal rentang kalau playhead di luarnya
  if (range && stopAt == null && (T < range.s || T >= range.e - 0.05)) T = range.s;
  else if (T >= total() - 0.05) T = 0;
  playing = true;
  lastTick = performance.now();
  $('b-play').innerHTML = hi('pause');
  boxShow();
  seek(T);
  requestAnimationFrame(tick);
}
function pause() {
  playing = false;
  stopAt = null;
  if (cur) cur.pause();
  auds.forEach(a => a.pause());
  for (const [k, v] of vids) if (k.startsWith('pip:')) v.pause();
  $('b-play') && ($('b-play').innerHTML = hi('play'));
}
function tick(now) {
  if (!playing) return;
  const dt = Math.min(0.25, (now - lastTick) / 1000);
  lastTick = now;
  const at = clipAt(T), i = at.i, cl = P.timeline[i], m = media(cl.mid), start = startOf(i);
  if (m.kind === 'video' && cur) {
    T = start + Math.max(0, cur.currentTime - cl.in);
    // Siapkan potongan berikutnya (posisi awalnya) sedikit sebelum potongan ini selesai
    const nx = P.timeline[i + 1];
    if (nx && media(nx.mid).kind === 'video' && cl.out - cur.currentTime < 0.6 + tinDur(i + 1) / 2) { const nv = srcOf(i + 1); if (nv !== cur) { nv.pause(); setTime(nv, nx.in); } }
    if (cur.currentTime >= cl.out - 0.02 || cur.ended) T = start + clipLen(cl);
  } else T += dt;
  if (stopAt != null && T >= stopAt) { pause(); draw(); return; }
  if (range && stopAt == null && T >= range.e) { T = range.e; pause(); seek(T); return; }
  if (T >= start + clipLen(cl) - 1e-3) {
    if (i >= P.timeline.length - 1) { T = total(); pause(); draw(); return; }
    seek(start + clipLen(cl) + 1e-3);
  }
  syncMusic();
  syncPips();
  draw();
  requestAnimationFrame(tick);
}
/* Musik latar: elemen audio per item, diputar mengikuti waktu timeline (volume & fade sama dengan render) */
const auds = new Map();
function audOf(mu) {
  let a = auds.get(mu.id);
  if (!a || a._f !== mu.file) { a?.pause(); a = new Audio('previews/music/' + mu.file); a.preload = 'auto'; a._f = mu.file; auds.set(mu.id, a); }
  return a;
}
function musicGain(mu, t) {
  const len = Math.min(mu.e, total()) - mu.s, k = t - mu.s;
  let g = mu.vol / 100;
  if (mu.fin > 0) g *= Math.min(1, k / Math.min(mu.fin, len / 2));
  if (mu.fout > 0) g *= Math.min(1, (len - k) / Math.min(mu.fout, len / 2));
  return clamp(g, 0, 1);
}
function syncMusic(force) {
  for (const mu of P.music) {
    const a = audOf(mu), on = playing && !trk('music').mute && T >= mu.s && T < Math.min(mu.e, total());
    if (!on) { if (!a.paused) a.pause(); continue; }
    const want = mu.off + (T - mu.s);
    if (force || a.paused || Math.abs(a.currentTime - want) > 0.3) a.currentTime = want;
    a.volume = musicGain(mu, T);
    if (a.paused) a.play().catch(() => {});
  }
  for (const [id, a] of auds) if (!P.music.some(m => m.id === id)) { a.pause(); auds.delete(id); }
}

/* ---------- Panel kiri ---------- */
function showLp(k) {
  lp = k;
  try { localStorage.setItem('mp-lp', k); } catch {}
  document.querySelectorAll('#rail [data-lp]').forEach(b => b.classList.toggle('on', b.dataset.lp === k));
  document.querySelectorAll('.lp-pane').forEach(p => { p.hidden = p.id !== 'lp-' + k; });
  if (k === 'music' && !musicLib.length) loadMusic();
  if (k === 'preset') loadPresets();
}
function renderMedia() {
  const list = Object.values(P.media).sort((a, b) => a.added_at - b.added_at);
  $('m-count').textContent = list.length || '';
  const ups = Object.values(uploads).filter(u => !u.mid || !P.media[u.mid] || P.media[u.mid].status === 'uploading');
  setHTML($('media'), list.filter(m => !ups.some(u => u.mid === m.mid)).map(mediaCard).join('')
    + ups.map(u => `<div class="mc busy"><div class="th"></div><div class="nm">${esc(u.name)}</div><div class="bar"><i style="width:${u.pct}%"></i></div></div>`).join(''));
}
function mediaCard(m) {
  const ready = m.status === 'done';
  return `<div class="mc${ready ? '' : ' busy'}" ${ready ? `draggable="true" data-mid="${m.mid}"` : ''} title="${esc(m.name)}">
    <div class="th" style="${m.thumb ? `background-image:url('${esc(m.thumb)}')` : ''}"><span class="kind">${hi(m.kind === 'image' ? 'image' : 'video')}</span>
      ${ready && m.kind === 'video' ? `<span class="dur">${fmtT(m.duration)}</span>` : ''}
      <span class="acts">${ready ? `<button data-add="${m.mid}" title="Tambah ke timeline">${hi('add')}</button><button data-addpip="${m.mid}" title="Tambah sebagai overlay">${hi('pip')}</button>` : ''}<button class="rm" data-mdel="${m.mid}" title="Hapus media">${hi('delete')}</button></span></div>
    <div class="nm">${esc(m.name)}</div>
    ${m.status === 'error' ? `<div class="err">${esc(m.error || 'Gagal diproses')}</div>` : !ready ? `<div class="bar"><i style="width:${m.progress || 5}%"></i></div>` : ''}
  </div>`;
}
// Gambar kecil tiap template (dibuat sekali) untuk menu Teks / Grafik & pilihan template di panel kanan
const gfxThumbs = {};
async function ensureGfxThumbs() {
  for (const [k, t] of Object.entries(GFX)) {
    if (gfxThumbs[k]) continue;
    gfxThumbs[k] = (await gfxCanvas({ type: k, ...t, fill: false }, 0.4)).toDataURL('image/png');
    document.querySelectorAll(`[data-gthumb="${k}"]`).forEach(el => { el.innerHTML = `<img src="${gfxThumbs[k]}" alt="">`; });
  }
}
const gthumb = k => `<span data-gthumb="${k}">${gfxThumbs[k] ? `<img src="${gfxThumbs[k]}" alt="">` : ''}</span>`;
function renderLeft() {
  const tile = ([k, t]) => `<button class="gt" data-gfx-add="${k}" title="${esc(t.cat)} · ${esc(t.name)}">${gthumb(k)}</button>`;
  $('g-text').innerHTML = Object.entries(GFX).filter(([, t]) => t.cat === 'Teks hook').map(tile).join('');
  const cats = [...new Set(Object.values(GFX).map(g => g.cat))].filter(c => c !== 'Teks hook');
  $('g-gfx').innerHTML = cats.map(c => `<div class="sec">${esc(c)}</div><div class="gl${c === 'Banner judul' || c === 'Kartu kutipan' ? ' one' : ''}">`
    + Object.entries(GFX).filter(([, t]) => t.cat === c).map(tile).join('') + '</div>').join('');
  renderTrGrid();
  markGfxTiles();
  ensureGfxThumbs();
}
function trGrid(curType) {
  return Object.entries(TRANSITIONS).map(([k, t]) => `<button class="trt k-${k}${curType === k ? ' on' : ''}" data-trk="${k}"><span class="sw"></span>${esc(t.label)}</button>`).join('');
}
function renderTrGrid() {
  const c = sel?.t === 'tr' ? selItem() : null;
  $('tr-grid').innerHTML = trGrid(c?.tin?.type || trPreset);
}
async function loadMusic() {
  try { musicLib = (await api('music_list')).music; } catch { musicLib = []; }
  renderMusic();
}
let prevAud = null, prevFile = '';
function renderMusic() {
  $('mlist').innerHTML = musicLib.length ? musicLib.map(m => `<div class="ml"><button class="ib sm${prevFile === m.file ? ' on' : ''}" data-mprev="${m.file}" title="Dengarkan">${hi(prevFile === m.file ? 'pause' : 'play')}</button>
      <div class="tx"><b title="${esc(m.name)}">${esc(m.name)}</b><small>${fmtT(m.dur)}</small></div>
      <button class="ib sm" data-madd="${m.file}" title="Tambah ke timeline">${hi('add')}</button></div>`).join('')
    : `<label class="nil" style="cursor:pointer">${hi('music')}Unggah musik<input type="file" accept="audio/*" data-mu-up hidden></label>`;
}
async function uploadMusic(file) {
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  toast('Mengunggah musik…');
  try {
    const res = await fetch('api.php?action=music_upload', { method: 'POST', body: fd });
    const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
    if (!res.ok || d.error) throw new Error(d.error || 'Unggah gagal.');
    musicLib = d.music;
    renderMusic();
    addMusic(musicLib[0].file);
  } catch (err) { toast(err.message); }
}
function addMusic(file) {
  const lib = musicLib.find(m => m.file === file);
  if (!lib) return;
  if (!P.timeline.length) return toast('Tambahkan footage ke timeline dulu.');
  const s = P.music.length ? Math.min(T, Math.max(0, total() - 1)) : 0;
  const mu = { id: rid(), file, name: lib.name, dur: lib.dur, s: r3(s), e: r3(Math.min(total(), s + lib.dur)), off: 0, vol: 40, fin: 0, fout: 1.5 };
  P.music.push(mu);
  save(); select({ t: 'mu', id: mu.id });
}
// Ganti template grafik: teks & label yang sudah diubah user tetap, warna ikut template baru
function switchGfx(o, type) {
  const old = GFX[o.gfx.type] || {}, t = GFX[type], g = o.gfx;
  const keepText = g.text && g.text !== old.text, keepLabel = g.label && g.label !== old.label;
  o.gfx = { ...g, type, c1: t.c1, c2: t.c2, c3: t.c3, c4: t.c4 || '', c5: t.c5 || '',
    text: keepText ? g.text : t.text, label: t.noLabel ? '' : keepLabel ? g.label : t.label, ext: 0 };
  if (!!old.full !== !!t.full) { Object.assign(o, defaultBox(t)); o.gfx.fill = !!t.full; }
  scheduleGfx(o, true);
}
function defaultBox(pre) {
  const [rw, rh] = RATIO_WH[P.ratio] || [9, 16], a = rw / rh;
  return { x: 0.5, y: pre.full ? 0.82 : 0.28, w: pre.full ? 1 : a > 1.2 ? 0.48 : a > 0.9 ? 0.7 : 0.84 };
}
function markGfxTiles() {
  const cur = P.overlays.find(o => !isHook(o))?.gfx.type;
  document.querySelectorAll('#g-gfx [data-gfx-add]').forEach(b => b.classList.toggle('on', b.dataset.gfxAdd === cur));
}
function addGfx(type) {
  if (!P.timeline.length) return toast('Tambahkan footage ke timeline dulu.');
  if (trk('gfx').lock) return toast('Track teks & grafik sedang dikunci.');
  const pre = GFX[type], hook = pre.cat === 'Teks hook', tot = total();
  // Grafik hanya satu per project: klik variasi lain = ganti template grafik yang sudah ada
  const ex = !hook && P.overlays.find(o => !isHook(o));
  if (ex) {
    if (ex.gfx.type !== type) switchGfx(ex, type);
    pause();
    sel = { t: 'ov', id: ex.oid };
    if (!ovVisible(ex, T)) T = ex.s;
    save(); renderTimeline(); renderProps(); seek(T); markGfxTiles();
    return;
  }
  // Teks hook: 5 dtk dari playhead; grafik lain: sepanjang video
  const s = hook ? Math.min(T, Math.max(0, tot - 0.5)) : 0, e = hook ? Math.min(tot, s + 5) : tot;
  const o = { oid: rid(), file: '', s: r3(s), e: r3(Math.max(s + 0.5, e)), ...defaultBox(pre),
    gfx: { type, label: pre.label, text: pre.text, c1: pre.c1, c2: pre.c2, c3: pre.c3, ...(pre.c4 ? { c4: pre.c4, c5: pre.c5 } : {}), fill: !!pre.full },
    opacity: 100, anim: { ...GFX_ANIM } };
  P.overlays.push(o);
  pause();
  if (!ovVisible(o, T)) T = o.s;
  sel = { t: 'ov', id: o.oid };
  scheduleGfx(o, true);
  save(); renderTimeline(); renderProps(); seek(T); markGfxTiles();
}
// Transisi masuk ke potongan cid (dibuat dengan template terpilih bila belum ada)
function setTransition(cid, type) {
  const c = P.timeline.find(x => x.cid === cid);
  if (!c || !P.timeline.indexOf(c)) return;
  c.tin = { type: type || c.tin?.type || trPreset, dur: c.tin?.dur || 0.5 };
  save();
}
// Putar grafik dari awal (animasi masuk) atau menjelang akhir (animasi keluar), seperti di editor clip
function playAnim(o, phase) {
  const [, dout] = animDur(o.anim, o.e - o.s);
  pause();
  sel = null; // tanpa kotak pilihan supaya animasinya terlihat utuh
  seek(phase === 'out' ? Math.max(o.s, o.e - dout - 0.6) : Math.max(0, o.s - 0.05));
  sel = { t: 'ov', id: o.oid };
  stopAt = Math.min(total(), o.e + 0.05);
  play();
  stopAt = Math.min(total(), o.e + 0.05);
}
function previewTransition(cid) {
  const i = P.timeline.findIndex(c => c.cid === cid), d = tinDur(i);
  if (i < 1 || !d) return;
  const J = startOf(i);
  pause();
  seek(Math.max(0, J - d / 2 - 0.5));
  stopAt = J + d / 2 + 0.5;
  play();
  stopAt = J + d / 2 + 0.5;
}

/* ---------- Preset: gaya project yang bisa dipakai ulang ---------- */
async function loadPresets() {
  try { presets = (await api('mproj_presets')).presets; } catch { presets = []; }
  renderPresets();
}
function presetData() {
  const tot = total(), full = x => x.s < 0.05 && x.e > tot - 0.1;
  return { fit: P.fit, tr: P.timeline.find(c => c.tin)?.tin || null,
    ov: P.overlays.filter(o => o.file).map(o => ({ ...ovData(o), full: full(o) })),
    mu: P.music.map(m => ({ ...m, full: full(m) })) };
}
function renderPresets() {
  $('ps-list').innerHTML = presets.length ? presets.map(p => {
    const d = p.data || {};
    return `<div class="ps" data-ps="${p.id}"><div class="tx"><b>${esc(p.name)}</b><div class="ch">
        ${d.ov?.length ? `<span title="Grafik & teks">${hi('gfx')}${d.ov.length}</span>` : ''}${d.mu?.length ? `<span title="Musik">${hi('music')}${d.mu.length}</span>` : ''}
        ${d.tr ? `<span title="Transisi">${hi('transition')}${esc(TRANSITIONS[d.tr.type]?.label || '')}</span>` : ''}</div></div>
      <button class="ib sm" data-ps-use title="Pakai">${hi('check')}</button><button class="ib sm danger" data-ps-del title="Hapus">${hi('delete')}</button></div>`;
  }).join('') : `<button class="nil" id="ps-empty" style="width:100%;border:0;background:none;cursor:pointer">${hi('bookmark')}Simpan gaya project ini</button>`;
}
function applyPreset(p) {
  const d = p.data || {}, tot = total();
  if (!P.timeline.length) return toast('Tambahkan footage ke timeline dulu.');
  pause();
  if (d.fit) P.fit = d.fit;
  if (d.tr) P.timeline.forEach((c, i) => { if (i) c.tin = { ...d.tr }; });
  // Grafik hanya satu: grafik dari preset menggantikan grafik yang sudah ada
  if ((d.ov || []).some(o => GFX[o.gfx?.type]?.cat !== 'Teks hook')) P.overlays = P.overlays.filter(o => isHook(o));
  for (const o of d.ov || []) {
    const s = o.full ? 0 : Math.min(o.s, Math.max(0, tot - 0.5)), e = o.full ? tot : Math.min(tot, Math.max(s + 0.5, o.e));
    P.overlays.push({ oid: rid(), gfx: o.gfx, file: o.file, x: o.x, y: o.y, w: o.w, opacity: o.opacity ?? 100, anim: o.anim || null, s: r3(s), e: r3(e) });
  }
  for (const m of d.mu || []) {
    const s = m.full ? 0 : Math.min(m.s, Math.max(0, tot - 1));
    P.music.push({ id: rid(), file: m.file, name: m.name, dur: m.dur, off: m.off, vol: m.vol, fin: m.fin, fout: m.fout, s: r3(s), e: r3(Math.min(tot, s + m.dur - m.off)) });
  }
  save(); renderTimeline(); renderProps(); seek(T);
  toast(`Preset "${p.name}" dipakai`);
}

async function uploadFiles(list) {
  for (const file of list) {
    const key = rid();
    uploads[key] = { name: file.name, pct: 0, mid: '' };
    renderMedia();
    const n = Math.max(1, Math.ceil(file.size / CHUNK));
    try {
      for (let i = 0; i < n; i++) {
        const fd = new FormData();
        fd.append('id', PID); fd.append('idx', i); fd.append('total', n); fd.append('name', file.name); fd.append('size', file.size);
        if (uploads[key].mid) fd.append('mid', uploads[key].mid);
        fd.append('chunk', file.slice(i * CHUNK, (i + 1) * CHUNK), 'chunk');
        const res = await fetch('api.php?action=mproj_upload', { method: 'POST', body: fd });
        const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
        if (!res.ok || d.error) throw new Error(d.error || 'Unggah gagal.');
        uploads[key].mid = d.mid;
        uploads[key].pct = (i + 1) / n * 100;
        renderMedia();
      }
    } catch (err) { toast(`${file.name}: ${err.message}`); }
    delete uploads[key];
    await load();
  }
}
const newClip = m => ({ cid: rid(), mid: m.mid, in: 0, out: m.kind === 'image' ? 3 : m.duration, vol: 100, fit: null });
function addClips(mids, at) {
  const add = mids.map(media).filter(m => m?.status === 'done').map(newClip);
  if (!add.length) return;
  P.timeline.splice(at ?? P.timeline.length, 0, ...add);
  P.timeline[0].tin = null;
  sel = { t: 'clip', id: add[0].cid };
  save(); renderTimeline(); renderProps(); seek(startOf(P.timeline.findIndex(c => c.cid === sel.id)));
}

// Media sebagai overlay di atas footage utama, mulai di detik t (lebih kecil dari layar supaya terlihat sebagai overlay)
function addPip(mid, t = T) {
  const m = media(mid);
  if (!m || m.status !== 'done') return;
  if (!P.timeline.length) return addClips([mid]);
  if (trk('pip').lock) return toast('Track overlay media sedang dikunci.');
  const tot = total(), s = r3(clamp(t, 0, Math.max(0, tot - 0.5))), len = m.kind === 'image' ? 3 : m.duration;
  const q = { id: rid(), mid, s, e: r3(s + Math.min(len, Math.max(0.5, tot - s))), in: 0, x: 0.5, y: 0.5, w: 0.5, vol: 100 };
  P.pips.push(q);
  pause();
  sel = { t: 'pip', id: q.id };
  save(); renderTimeline(); renderProps(); seek(s + 0.01);
}

/* ---------- Timeline: grafik, overlay media, video + transisi, musik ---------- */
const LANE = 24, GAP = 4, VH = 62;
// Item yang bertumpuk waktunya dibagi ke beberapa baris
function packRows(items) {
  const ends = [], row = new Map();
  for (const it of [...items].sort((a, b) => a.s - b.s)) {
    let r = ends.findIndex(e => e <= it.s + 1e-3);
    if (r < 0) { r = ends.length; ends.push(0); }
    ends[r] = it.e;
    row.set(it, r);
  }
  return [row, Math.max(1, ends.length)];
}
let lay = null;
function renderTimeline() {
  const sc = $('tl-scroll');
  if (!sc) return;
  const dur = total(), vw = Math.max(200, sc.clientWidth - 126);
  // Skala tetap selama menyeret (durasi berubah saat ujung potongan ditarik), supaya potongan tidak "meloncat"
  if (!document.body.classList.contains('dragging')) pps = Math.max(8, (vw / Math.max(10, dur)) * zoom);
  const width = Math.max(vw, dur * pps + 120);
  const step = [1, 2, 5, 10, 15, 30, 60, 120].find(s => s * pps >= 70) || 300;
  let ruler = '';
  for (let s = 0; s <= width / pps; s += step / 5) {
    const k = Math.round(s / (step / 5)), x = s * pps;
    ruler += k % 5 === 0 ? `<i class="maj" style="left:${x}px"></i><span style="left:${x}px">${fmtT(s).replace(/\.\d$/, '')}</span>` : `<i style="left:${x}px"></i>`;
  }
  const [oRow, oN] = packRows(P.overlays), [qRow, qN] = packRows(P.pips), [mRow, mN] = packRows(P.music);
  const rowsH = n => n * (LANE + GAP) - GAP;
  const ovTop = 26, pipTop = ovTop + rowsH(oN) + 6, vTop = pipTop + rowsH(qN) + 8, muTop = vTop + VH + 8, H = muTop + rowsH(mN) + 4;
  lay = { vTop, ovTop, pipTop, muTop, qN };
  const vl = trk('video').lock;
  let acc = 0, clips = '', zones = '', jns = '';
  P.timeline.forEach((c, i) => {
    const m = media(c.mid), L = clipLen(c), x = acc * pps, w = Math.max(14, L * pps - 3);
    clips += `<div class="clip${isSel('clip', c.cid) ? ' on' : ''}${vl ? ' lk' : ''}" data-cid="${c.cid}" style="left:${x}px;width:${w}px;background-image:url('${esc(m?.thumb || '')}')">
      <span class="h l" data-trim="in"></span><span class="cn">${hi(m?.kind === 'image' ? 'image' : 'video')} ${i + 1}. ${esc(m?.name || '')}</span><span class="cd">${L.toFixed(1)}s</span><span class="h r" data-trim="out"></span></div>`;
    if (i) {
      const d = tinDur(i);
      if (d) zones += `<i class="tz" style="left:${x - d / 2 * pps}px;width:${d * pps}px;top:${vTop}px"></i>`;
      if (!vl || c.tin) jns += `<button class="jn${c.tin ? ' has' : ''}${isSel('tr', c.cid) ? ' on' : ''}" data-jn="${c.cid}" style="left:${x}px;top:${vTop + VH / 2}px"
        title="${c.tin ? esc(TRANSITIONS[c.tin.type]?.label || '') : 'Tambah transisi'}">${hi(c.tin ? 'transition' : 'add')}</button>`;
    }
    acc += L;
  });
  const idOf = (k, it) => k === 'ov' ? it.oid : it.id;
  const item = (k, it, top, icon, label, bg = '') => {
    const t = trk(TK_OF[k]);
    return `<div class="it ${k}${isSel(k, idOf(k, it)) ? ' on' : ''}${t.lock ? ' lk' : ''}${t.hide || (k === 'mu' && t.mute) ? ' hid' : ''}" data-${k}="${idOf(k, it)}"
    style="left:${it.s * pps}px;width:${Math.max(16, (it.e - it.s) * pps - 2)}px;top:${top}px${bg ? `;background-image:url('${esc(bg)}')` : ''}"><span class="h l" data-it="l"></span>${hi(icon)}<span>${esc(label)}</span><span class="h r" data-it="r"></span></div>`;
  };
  const ovs = P.overlays.map(o => item('ov', o, ovTop + oRow.get(o) * (LANE + GAP), GFX[o.gfx.type]?.cat === 'Teks hook' ? 'text' : 'gfx', o.gfx.text || GFX[o.gfx.type]?.name || '')).join('');
  const pips = P.pips.map(q => { const m = media(q.mid); return item('pip', q, pipTop + qRow.get(q) * (LANE + GAP), m?.kind === 'image' ? 'image' : 'video', m?.name || '', m?.thumb); }).join('');
  const mus = P.music.map(m => item('mu', m, muTop + mRow.get(m) * (LANE + GAP), 'music', m.name)).join('');
  const lanes = [['gfx', ovTop, rowsH(oN)], ['pip', pipTop, rowsH(qN)], ['music', muTop, rowsH(mN)]]
    .map(([k, top, h]) => `<div class="lane" data-lane="${k}" style="top:${top}px;height:${h}px;width:${width}px"></div>`).join('');
  const tw = P.timeline.length ? Math.max(vw, dur * pps + 60) : 0;
  Object.assign($('tl').style, { width: width + 'px', height: H + 'px' });
  const pr = range ? `<div class="prange" id="prange" style="left:${range.s * pps}px;width:${Math.max(4, (range.e - range.s) * pps)}px" title="Rentang putar — seret untuk menggeser"><i class="l" data-pr="s"></i><i class="r" data-pr="e"></i></div>
    <div class="prdim" style="left:0;width:${range.s * pps}px"></div><div class="prdim" style="left:${range.e * pps}px;right:0"></div>` : '';
  $('tl').innerHTML = `<div class="ruler" id="ruler">${ruler}</div>${pr}${lanes}
    <div class="track${P.timeline.length ? '' : ' empty'}${trk('video').hide ? ' hid' : ''}" id="track" style="top:${vTop}px;width:${tw ? tw + 'px' : '100%'}">${P.timeline.length ? clips : hi('video') + 'Seret footage ke sini'}</div>
    ${zones}${jns}${ovs}${pips}${mus}
    <div class="phead" id="phead" style="left:${T * pps}px"><i class="ph-knob" title="Seret untuk memindah playhead"></i></div>`;
  // Kepala track: ikon jenis track + sembunyikan / kunci / bisukan
  const tops = { gfx: [ovTop, rowsH(oN)], pip: [pipTop, rowsH(qN)], video: [vTop, VH], music: [muTop, rowsH(mN)] };
  const FL = { hide: ['eye', 'eyeoff', 'Sembunyikan', 'Tampilkan'], lock: ['unlock', 'lock', 'Kunci', 'Buka kunci'], mute: ['volume', 'mute', 'Bisukan', 'Bunyikan'] };
  $('tl-heads').style.height = H + 'px';
  $('tl-heads').innerHTML = TRACKS.map(([k, icon, name, flags]) => `<div class="thd" style="top:${tops[k][0]}px;height:${tops[k][1]}px" title="${name}">${hi(icon)}
    ${['hide', 'lock', 'mute'].map(f => { if (!flags.includes(f)) return '<span class="sl"></span>'; const on = trk(k)[f], [i0, i1, t0, t1] = FL[f]; return `<button class="${on ? 'on' : ''}" data-tk="${k}" data-tf="${f}" title="${on ? t1 : t0}">${hi(on ? i1 : i0)}</button>`; }).join('')}</div>`).join('');
  $('tl-info').textContent = P.timeline.length ? `${P.timeline.length} potongan · ${fmtT(dur)}` : '';
  const it = selItem();
  $('b-del').disabled = !it;
  $('b-dup').disabled = !it || sel.t === 'tr';
  $('b-range').disabled = !range;
  $('tl-hint').hidden = !!sel || !P.timeline.length;
  // Jumlah item di tiap menu kiri
  const cnt = { media: Object.keys(P.media).length, text: P.overlays.filter(isHook).length, gfx: P.overlays.filter(o => !isHook(o)).length,
    music: P.music.length, tr: P.timeline.filter(c => c.tin).length, preset: presets.length };
  for (const [k, n] of Object.entries(cnt)) { const el = $('cnt-' + k); if (el) el.textContent = n || ''; }
  updateTime();
  paintFilm();
}
/* ---------- Filmstrip & waveform potongan video (dibuat di browser dari preview, sekali per media) ---------- */
const film = new Map(), waves = new Map();
let filmRaf = 0;
const paintFilmSoon = () => { if (!filmRaf) filmRaf = requestAnimationFrame(() => { filmRaf = 0; paintFilm(); }); };
function filmOf(m) {
  let f = film.get(m.mid);
  if (f) return f;
  const step = Math.max(0.5, m.duration / 48), n = Math.max(1, Math.ceil(m.duration / step));
  f = { step, frames: new Array(n).fill(null) };
  film.set(m.mid, f);
  (async () => {
    const v = document.createElement('video');
    v.muted = true; v.preload = 'auto'; v.src = m.preview;
    await new Promise(r => { v.onloadeddata = r; v.onerror = r; setTimeout(r, 8000); });
    const th = 62, tw = Math.round(th * ((m.width / m.height) || 16 / 9));
    for (let k = 0; k < n; k++) {
      await new Promise(r => { v.onseeked = r; setTimeout(r, 1500); v.currentTime = Math.min(Math.max(0, m.duration - 0.05), k * step + 0.02); });
      const c = document.createElement('canvas');
      c.width = tw; c.height = th;
      try { c.getContext('2d').drawImage(v, 0, 0, tw, th); f.frames[k] = c; } catch {}
      if (k % 4 === 3 || k === n - 1) paintFilmSoon();
    }
    v.removeAttribute('src'); v.load();
  })();
  return f;
}
async function loadWave(m) {
  if (waves.has(m.mid) || !m.audio) return;
  waves.set(m.mid, null);
  try {
    const buf = await (await fetch(m.preview)).arrayBuffer();
    const ab = await new OfflineAudioContext(1, 44100, 44100).decodeAudioData(buf);
    // Puncak per 1/50 dtk
    const ch = ab.getChannelData(0), per = Math.max(1, Math.floor(ab.sampleRate / 50)), n = Math.ceil(ch.length / per), pk = new Float32Array(n);
    for (let i = 0; i < n; i++) { let mx = 0; for (let j = i * per, e = Math.min(ch.length, j + per); j < e; j += 4) { const a = Math.abs(ch[j]); if (a > mx) mx = a; } pk[i] = mx; }
    waves.set(m.mid, pk);
    paintFilmSoon();
  } catch {}
}
function paintFilm() {
  document.querySelectorAll('#track .clip').forEach(el => {
    const c = P.timeline.find(x => x.cid === el.dataset.cid), m = c && media(c.mid);
    if (!m || m.status !== 'done' || m.kind !== 'video') return;
    let cvs = el.querySelector('canvas.film');
    if (!cvs) { cvs = document.createElement('canvas'); cvs.className = 'film'; el.prepend(cvs); }
    const W = el.clientWidth, H = el.clientHeight, dpr = Math.min(2, devicePixelRatio || 1);
    cvs.width = Math.round(W * dpr); cvs.height = Math.round(H * dpr);
    const g = cvs.getContext('2d');
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    const f = filmOf(m), tw = H * ((m.width / m.height) || 16 / 9);
    for (let x = 0; x < W; x += tw) {
      const t = c.in + x / pps, fr = f.frames[Math.min(f.frames.length - 1, Math.floor(t / f.step))];
      if (fr) g.drawImage(fr, x, 0, tw, H);
    }
    // Waveform di bagian bawah potongan (ikut volume potongan)
    loadWave(m);
    const pk = waves.get(m.mid);
    if (!pk) return;
    const bh = 16, mid = H - bh / 2, gain = Math.min(2, (c.vol ?? 100) / 100);
    g.fillStyle = 'rgba(0, 0, 0, .5)'; g.fillRect(0, H - bh, W, bh);
    g.fillStyle = trk('video').mute ? 'rgba(255, 255, 255, .25)' : 'rgba(143, 184, 255, .9)';
    for (let x = 0; x < W; x += 2) {
      const i = Math.floor((c.in + x / pps) * 50), a = Math.min(1, (pk[i] || 0) * gain), h = Math.max(1, a * (bh - 4));
      g.fillRect(x, mid - h / 2, 1.3, h);
    }
  });
}
// Indeks sisipan dari posisi x (di antara potongan)
function insertIndex(clientX) {
  const x = clientX - $('tl').getBoundingClientRect().left;
  let acc = 0;
  for (let i = 0; i < P.timeline.length; i++) {
    const L = clipLen(P.timeline[i]) * pps;
    if (x < acc + L / 2) return i;
    acc += L;
  }
  return P.timeline.length;
}
function showIns(i) {
  let m = $('ins');
  if (i == null) { m?.remove(); return; }
  if (!m) { m = document.createElement('div'); m.id = 'ins'; m.className = 'ins'; $('tl').appendChild(m); }
  Object.assign(m.style, { left: startOf(i) * pps + 'px', top: lay.vTop - 6 + 'px', height: VH + 12 + 'px' });
}
// Penanda posisi overlay media saat media diseret ke atas footage utama
function showPipMark(t) {
  let m = $('pipmark');
  $('tl').querySelector('[data-lane="pip"]')?.classList.toggle('over', t != null);
  if (t == null) { m?.remove(); return; }
  if (!m) { m = document.createElement('div'); m.id = 'pipmark'; m.className = 'pipmark'; $('tl').appendChild(m); }
  Object.assign(m.style, { left: t * pps + 'px', top: lay.pipTop - 2 + 'px', height: lay.qN * (LANE + GAP) - GAP + 4 + 'px' });
}

/* ---------- Panel properti (kanan) ---------- */
const head = (icon, title, acts = '') => `<span class="t">${hi(icon)}<span>${esc(title)}</span></span><span class="acts">${acts}</span>`;
const actBtns = (dup = true) => (dup ? `<button class="ib sm" data-act="dup" title="Duplikat">${hi('copy')}</button>` : '')
  + `<button class="ib sm danger" data-act="del" title="Hapus">${hi('delete')}</button><button class="ib sm" data-act="close" title="Tutup (Esc)">${hi('close')}</button>`;
const pct = (v, min, max) => `--p:${Math.round(clamp((v - min) / (max - min), 0, 1) * 1000) / 10}%`;
// Slider dengan kolom angka (bisa diketik) — dipakai untuk posisi supaya punya acuan persen yang konsisten
const rngN = (k, v, min, max, icon, title) => `<div class="rng" title="${title}">${hi(icon)}<input type="range" data-k="${k}" min="${min}" max="${max}" step="1" value="${v}" style="${pct(v, min, max)}" aria-label="${title}">
  <label class="vn"><input type="number" data-kn="${k}" min="${min}" max="${max}" step="1" value="${v}" aria-label="${title} (persen)">%</label></div>`;
const rng = (k, v, min, max, step, unit, icon, title) => `<div class="rng" title="${title}">${hi(icon)}<input type="range" data-k="${k}" data-u="${unit}" min="${min}" max="${max}" step="${step}" value="${v}" style="${pct(v, min, max)}" aria-label="${title}"><span class="v">${v}${unit}</span></div>`;
// Stepper: − [nilai] + (±0,1 dtk); waktu ditulis m:ss.s, durasi dalam detik
const num = (k, v, label, time = true) => `<div class="stp"><span>${label}</span><button type="button" data-st="-1" tabindex="-1" aria-label="Kurangi 0,1 dtk">−</button>
  <input ${time ? 'class="tm" type="text" inputmode="decimal"' : 'type="number" step="0.1" min="0"'} data-k="${k}" value="${time ? fmtT(v) : (+v).toFixed(1)}" spellcheck="false" aria-label="${label}">
  <button type="button" data-st="1" tabindex="-1" aria-label="Tambah 0,1 dtk">+</button></div>`;
function renderProps() {
  document.querySelectorAll('#ptabs [data-pt]').forEach(b => b.classList.toggle('on', b.dataset.pt === ptab));
  if (ptab === 'layer') return layerProps();
  const it = selItem();
  if (!it || !sel) return projectProps();
  if (sel.t === 'clip') {
    const c = it, m = media(c.mid), img = m.kind === 'image', i = P.timeline.indexOf(c), s = Math.round(effTr(c).s * 100);
    $('props-h').innerHTML = head(img ? 'image' : 'video', `Potongan ${i + 1}`, `<button class="ib sm" data-act="split" title="Potong di playhead (S)">${hi('split')}</button>` + actBtns());
    setHTML($('props'), `${img ? `<div class="lbl">Durasi</div><div class="row2">${num('out', c.out, 'Detik', false)}</div>`
      : `<div class="lbl">Potongan</div><div class="row2">${num('in', c.in, 'Mulai')}${num('out', c.out, 'Selesai')}</div>
        <div class="lbl">Volume</div>${rng('vol', c.vol ?? 100, 0, 200, 5, '%', (c.vol ?? 100) ? 'ui-vol' : 'ui-mute', 'Volume')}`}
      <div class="lbl">Ukuran & posisi</div>${rng('scale', s, 10, 400, 1, '%', 'ui-scale', 'Ukuran')}
      ${rngN('ty', Math.round(effTr(c).y * 100), -50, 150, 'ui-posy', 'Posisi vertikal (50% = tengah layar)')}
      <div class="tools"><button class="ib sm" data-trq="fit" title="Pas layar">${hi('ui-fit')}</button><button class="ib sm" data-trq="cover" title="Penuhi layar">${hi('ui-cover')}</button>
        <button class="ib sm" data-trq="center" title="Ke tengah">${hi('ui-center')}</button></div>
      <div class="lbl">Latar</div>
      <div class="seg c4">${[['', 'Auto'], ['fill', 'Penuh'], ['blur', 'Blur'], ['black', 'Hitam']].map(([v, t]) => `<button data-cfit="${v}" class="${(c.fit || '') === v ? 'on' : ''}">${t}</button>`).join('')}</div>`);
    return;
  }
  if (sel.t === 'ov') {
    // Sama dengan panel grafik editor clip: template, label, teks, perataan & ketebalan, ukuran, jarak baris, warna (hex), waktu, ukuran, opacity, animasi
    const o = it, g = o.gfx, pre = GFX[g.type] || GFX.hook, hook = pre.cat === 'Teks hook';
    $('props-h').innerHTML = head(hook ? 'text' : 'gfx', `${hook ? 'Teks' : 'Grafik'} · ${pre.name}`, actBtns(hook));
    const cval = k => g[k] || (k === 'c4' ? g.c1 : k === 'c5' ? g.c3 : '#FFFFFF');
    const col = (k, n) => `<div class="colr"><span>${esc(n)}</span><label class="sw" style="--c:${cval(k)}"><input type="color" data-gc="${k}" value="${cval(k).toLowerCase()}" aria-label="${esc(n)}"></label>
      <input class="hex" data-gh="${k}" value="${cval(k).slice(1)}" maxlength="7" spellcheck="false" aria-label="Kode warna ${esc(n)}"></div>`;
    const cn = pre.cn || [];
    const a = { ...ANIM_NONE, ...(o.anim || {}) };
    const animSec = (ph, title) => `<div class="lbl">${title}</div><div class="anims">${ANIMS.map(([k, n]) => `<button class="${a[ph] === k ? 'on' : ''}" data-anim="${ph}" data-v="${k}">${n}</button>`).join('')}</div>
      ${a[ph] !== 'none' ? rng('d' + ph, (+a['d' + ph]).toFixed(2), 0.1, 1.5, 0.05, 's', 'ui-dur', 'Durasi animasi') : ''}`;
    setHTML($('props'), `<div class="gpick">${Object.entries(GFX).filter(([, t]) => t.cat === pre.cat).map(([k, t]) => `<button data-gtype="${k}" class="${k === g.type ? 'on' : ''}" title="${esc(t.name)}">${gthumb(k)}<span>${esc(t.name)}</span></button>`).join('')}</div>
      ${pre.noLabel ? '' : `<div class="lbl">${hi('ui-label')}Label<span class="cnt">${g.label.length}/40</span></div><input class="fld" data-g="label" value="${esc(g.label)}" maxlength="40" spellcheck="false">
        ${rng('ls', g.ls ?? 100, 60, 200, 5, '%', 'ui-label', 'Ukuran label')}`}
      <div class="lbl">${hi('ui-text')}${hook ? 'Teks hook' : 'Judul'}<span class="cnt">${g.text.length}/200</span></div><textarea class="fld" data-g="text" rows="3" maxlength="200" spellcheck="false">${esc(g.text)}</textarea>
      ${pre.rich ? '<div class="hint">Apit kata dengan <b>*tanda bintang*</b> untuk warna sorotan, mis. *sahabat sendiri*. Label = baris sumber di bawah.</div>' : ''}
      <div class="trow"><div class="seg c4" style="padding:2px">${[['', 'Rata bawaan', 'reset'], ['left', 'Rata kiri', 'al-left'], ['center', 'Rata tengah', 'al-center'], ['right', 'Rata kanan', 'al-right']].map(([v, t, i]) =>
          `<button data-gal="${v}" class="${(g.ta || '') === v ? 'on' : ''}" title="${t}">${hi(i)}</button>`).join('')}</div>
        <label class="selw" title="Ketebalan teks">${hi('bold')}<select data-g="tw" aria-label="Ketebalan teks">${[['', 'Bawaan'], ['400', 'Regular'], ['500', 'Medium'], ['600', 'Semi bold'], ['700', 'Bold'], ['800', 'Extra bold'], ['900', 'Black']].map(([v, t]) =>
          `<option value="${v}" ${(g.tw || '') === v ? 'selected' : ''}>${t}</option>`).join('')}</select>${hi('chev')}</label></div>
      ${rng('ts', g.ts ?? 100, 60, 160, 5, '%', 'ui-tsize', 'Ukuran teks')}
      ${rng('lh', g.lh ?? 100, 70, 160, 5, '%', 'ui-lheight', 'Jarak baris')}
      <div class="lbl">${hi('ui-color')}Warna</div><div class="colrs">${cn[0] ? col('c1', cn[0]) : ''}${cn[1] ? col('c2', cn[1]) : ''}${cn[2] ? col('c3', cn[2]) : ''}${cn[3] && !pre.noLabel ? col('c4', cn[3]) + col('c5', cn[4]) : ''}</div>
      <div class="lbl">Tampil</div><div class="row2">${num('s', o.s, 'Mulai')}${num('e', o.e, 'Selesai')}</div>
      ${pre.full ? `<label class="chk"><input type="checkbox" data-gfill ${g.fill !== false ? 'checked' : ''}> Pita sampai bawah layar</label>`
        : `<div class="lbl">Ukuran</div>${rng('w', Math.round(o.w * 100), 30, 100, 1, '%', 'ui-width', 'Ukuran')}`}
      <div class="lbl">Opacity</div>${rng('op', o.opacity ?? 100, 0, 100, 1, '%', 'ui-opacity', 'Opacity')}
      <div class="tools">${pre.full ? '' : `<button class="ib sm" data-oq="center" title="Ke tengah">${hi('ui-center')}</button>`}<button class="ib sm" data-oq="full" title="Sepanjang video">${hi('ui-span')}</button></div>
      ${animSec('in', 'Animasi masuk')}${animSec('out', 'Animasi keluar')}
      <div class="row-acts"><button class="btn sm" data-act="aplay">${hi('play')} Putar animasi</button></div>`);
    ensureGfxThumbs();
    return;
  }
  if (sel.t === 'pip') {
    const q = it, m = media(q.mid), img = m?.kind === 'image';
    $('props-h').innerHTML = head('pip', m?.name || 'Overlay', actBtns());
    setHTML($('props'), `<div class="lbl">Tampil</div><div class="row2">${num('s', q.s, 'Mulai')}${num('e', q.e, 'Selesai')}</div>
      ${img ? '' : `<div class="row2" style="margin-top:6px">${num('in', q.in, 'Awal')}</div>`}
      <div class="lbl">Ukuran & posisi</div>${rng('w', Math.round(q.w * 100), 5, 200, 1, '%', 'ui-width', 'Lebar')}
      ${rngN('ty', Math.round(q.y * 100), -50, 150, 'ui-posy', 'Posisi vertikal (50% = tengah layar)')}
      <div class="tools"><button class="ib sm" data-oq="center" title="Ke tengah">${hi('ui-center')}</button><button class="ib sm" data-oq="fill" title="Selebar layar">${hi('ui-width')}</button>
        <button class="ib sm" data-oq="full" title="Sepanjang video">${hi('ui-span')}</button></div>
      ${img || !m?.audio ? '' : `<div class="lbl">Volume</div>${rng('pvol', q.vol ?? 100, 0, 200, 5, '%', (q.vol ?? 100) ? 'ui-vol' : 'ui-mute', 'Volume')}`}`);
    return;
  }
  if (sel.t === 'mu') {
    const m = it;
    $('props-h').innerHTML = head('music', m.name, actBtns());
    setHTML($('props'), `<div class="lbl">Volume</div>${rng('mvol', m.vol, 0, 100, 1, '%', m.vol ? 'ui-vol' : 'ui-mute', 'Volume')}
      <div class="lbl">Fade masuk / keluar</div><div class="row2">${num('fin', m.fin, 'In', false)}${num('fout', m.fout, 'Out', false)}</div>
      <div class="lbl">Posisi</div><div class="row2">${num('s', m.s, 'Mulai')}${num('e', m.e, 'Selesai')}</div>
      <div class="row2" style="margin-top:6px">${num('off', m.off, 'Dari lagu')}</div>`);
    return;
  }
  // Transisi
  const c = it, i = P.timeline.indexOf(c);
  $('props-h').innerHTML = head('transition', `Transisi ${i} → ${i + 1}`, actBtns(false));
  setHTML($('props'), `<div class="trg">${trGrid(c.tin?.type)}</div>
    <div class="lbl">Durasi</div>${rng('tdur', (c.tin?.dur || 0.5).toFixed(1), 0.2, 2, 0.1, ' dtk', 'ui-dur', 'Durasi transisi')}
    <div class="row-acts"><button class="btn sm" data-act="tplay">${hi('play')} Lihat</button><button class="btn sm" data-act="tall" title="Pasang di semua sambungan">${hi('layers')} Semua</button></div>`);
}
// Panel Layer: urutan tumpuk tiap kelompok (paling atas = paling depan); seret untuk mengubah urutan, klik untuk memilih
function layerProps() {
  $('props-h').innerHTML = head('layers', 'Layer');
  const row = (t, id, th, name, sub) => `<div class="lyi${isSel(t, id) ? ' on' : ''}" ${t !== 'clip' ? 'draggable="true"' : ''} data-ly="${t}" data-id="${id}">
    <span class="gr">${t !== 'clip' ? hi('grip') : ''}</span><span class="th">${th}</span><div class="tx"><b>${esc(name || '')}</b><small>${sub}</small></div></div>`;
  const img = src => src ? `<img src="${esc(src)}" alt="">` : '';
  const ovs = [...P.overlays].reverse().map(o => row('ov', o.oid, gthumb(o.gfx.type), o.gfx.text || GFX[o.gfx.type]?.name, `${fmtT(o.s)} – ${fmtT(o.e)}`)).join('');
  const pips = [...P.pips].reverse().map(q => { const m = media(q.mid); return row('pip', q.id, img(m?.thumb), m?.name || 'Overlay', `${fmtT(q.s)} – ${fmtT(q.e)}`); }).join('');
  const clips = P.timeline.map((c, i) => { const m = media(c.mid); return row('clip', c.cid, img(m?.thumb), `${i + 1}. ${m?.name || ''}`, `${fmtT(startOf(i))} · ${clipLen(c).toFixed(1)} dtk`); }).join('');
  const grp = (icon, title, body) => body ? `<div class="lyg"><div class="lyg-h">${hi(icon)}${title}</div>${body}</div>` : '';
  setHTML($('props'), (grp('gfx', 'Teks & grafik', ovs) + grp('pip', 'Overlay media', pips) + grp('video', 'Video utama', clips)) || `<div class="nil">${hi('layers')}Belum ada layer</div>`);
  ensureGfxThumbs();
}
function projectProps() {
  $('props-h').innerHTML = head('settings', 'Project');
  const renders = P.renders || [];
  setHTML($('props'), `<div class="lbl">Rasio</div>
    <div class="seg c5">${info.ratios.map(r => `<button data-ratio="${r}" class="${P.ratio === r ? 'on' : ''}">${r}</button>`).join('')}</div>
    <div class="lbl">Isi layar</div>
    <div class="seg">${[['fill', 'Penuh'], ['blur', 'Blur'], ['black', 'Hitam']].map(([v, t]) => `<button data-fit="${v}" class="${P.fit === v ? 'on' : ''}">${t}</button>`).join('')}</div>
    <div class="lbl">${hi('render')}Hasil render</div>
    ${renders.length ? `<div class="rlist">${renders.map(r => `<div class="rr"><div class="tx"><b>${r.w}×${r.h}</b><small>${new Date(r.at * 1000).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })} · ${fmtSize(r.size)}${r.note.includes('AI') ? ' · AI' : ''}</small></div>
      <a class="ib sm" href="downloads/${encodeURIComponent(r.file)}" download title="Download">${hi('download')}</a>
      <button class="ib sm danger" data-rdel="${r.job}" title="Hapus render">${hi('delete')}</button></div>`).join('')}</div>` : `<div class="nil" style="padding:16px">${hi('render')}Belum ada</div>`}`);
}

/* ---------- Render ---------- */
let rq = { mode: 'native', short: 0 };
function canvasOf(short) {
  const [rw, rh] = RATIO_WH[P.ratio], k = short / Math.min(rw, rh);
  return `${Math.round(rw * k / 2) * 2}×${Math.round(rh * k / 2) * 2}`;
}
function openRender() {
  if (!P.timeline.length) return toast('Timeline masih kosong.');
  rq = { mode: 'native', short: info.native };
  $('rmodal').hidden = false;
  paintRender();
}
function paintRender() {
  if ($('rmodal').hidden || !info) return;
  const nat = info.native, up = info.qualities.find(q => q > nat), r = info.render, active = ['queued', 'converting'].includes(r?.status);
  $('rm-sub').textContent = `${P.timeline.length} potongan · ${fmtT(total())} · ${P.ratio}`;
  const qName = q => q >= 2160 ? '4K' : q >= 1440 ? '2K' : q + 'p';
  setHTML($('rm-opts'), `
    <label class="qopt${rq.mode === 'native' ? ' on' : ''}"><input type="radio" name="rq" value="native" ${rq.mode === 'native' ? 'checked' : ''} ${active ? 'disabled' : ''}>
      <div><b>Sesuai footage · ${qName(nat)} <span class="tag">Bawaan</span></b><small>${canvasOf(nat)}</small></div></label>
    ${up ? `<label class="qopt ai${rq.mode === 'ai' ? ' on' : ''}"><input type="radio" name="rq" value="ai" ${rq.mode === 'ai' ? 'checked' : ''} ${active ? 'disabled' : ''}>
      <div><b>${hi('sparkles')} Upscale AI · ${qName(rq.mode === 'ai' ? rq.short : up)} <span class="tag rec">Rekomendasi</span></b>
        <small>${info.ai ? 'Lebih tajam, proses lebih lama.' : 'AI belum tersedia di komputer ini — diperbesar biasa.'}</small>
        ${rq.mode === 'ai' ? `<div class="chips">${info.qualities.filter(q => q > nat).map(q => `<button type="button" class="chip${rq.short === q ? ' on' : ''}" data-ai="${q}">${qName(q)} <small>${canvasOf(q)}</small></button>`).join('')}</div>` : ''}</div></label>` : ''}
    <label class="qopt${rq.mode === 'custom' ? ' on' : ''}"><input type="radio" name="rq" value="custom" ${rq.mode === 'custom' ? 'checked' : ''} ${active ? 'disabled' : ''}>
      <div><b>Pilih resolusi</b>
        ${rq.mode === 'custom' ? `<div class="chips">${info.qualities.map(q => `<button type="button" class="chip${rq.short === q ? ' on' : ''}" data-q="${q}">${qName(q)} <small>${canvasOf(q)}</small></button>`).join('')}</div>` : ''}</div></label>`);
  setHTML($('rm-prog'), r ? (active ? `<div class="rprog"><b>${esc(r.stage || 'Menyiapkan…')}</b><div class="bar"><i style="width:${r.progress || 2}%"></i></div></div>`
    : r.status === 'done' && r.file ? `<div class="rprog ok">${hi('check')}<span><b>Selesai</b> · ${esc(r.note || '')} · ${fmtSize(r.size)}</span>
        <a class="btn sm primary" href="downloads/${encodeURIComponent(r.file)}" download>${hi('download')} Download</a></div>`
    : r.status === 'error' ? `<div class="rprog err">${esc(r.error || 'Render gagal')}</div>` : '') : '');
  $('rm-go').disabled = active;
  $('rm-go').innerHTML = active ? '<span class="spin"></span> Merender…' : `${hi(rq.mode === 'ai' ? 'sparkles' : 'render')} Render`;
}

/* ---------- Event editor ---------- */
function bindEditor() {
  $('pname').onchange = e => { P.name = e.target.value.trim() || P.name; save(); };
  $('b-undo').onclick = () => undo(false);
  $('b-redo').onclick = () => undo(true);
  $('rail').addEventListener('click', e => { const b = e.target.closest('[data-lp]'); if (b) showLp(b.dataset.lp); });
  $('file-in').onchange = e => { uploadFiles([...e.target.files]); e.target.value = ''; };
  const drop = $('drop');
  ['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { if (e.dataTransfer.types.includes('Files')) { e.preventDefault(); drop.classList.add('over'); } }));
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); uploadFiles([...e.dataTransfer.files]); });
  // Media → timeline (seret) / tombol tambah / hapus
  $('media').addEventListener('dragstart', e => { const c = e.target.closest('[data-mid]'); if (c) { e.dataTransfer.setData('text/x-mid', c.dataset.mid); e.dataTransfer.effectAllowed = 'copy'; } });
  $('media').addEventListener('click', async e => {
    const a = e.target.closest('[data-add]'), d = e.target.closest('[data-mdel]'), q = e.target.closest('[data-addpip]');
    if (q) return addPip(q.dataset.addpip);
    if (a) {
      if (trk('video').lock) return toast('Track video sedang dikunci.');
      return addClips([a.dataset.add]);
    }
    if (d) {
      const m = media(d.dataset.mdel);
      if (!confirm(`Hapus "${m.name}"? Potongan dari media ini di timeline ikut dihapus.`)) return;
      P = { ...P, ...(await api('mproj_media_delete', { id: PID, mid: m.mid })).project, timeline: P.timeline.filter(c => c.mid !== m.mid), overlays: P.overlays, music: P.music,
        pips: P.pips.filter(q => q.mid !== m.mid), tracks: P.tracks };
      if (P.timeline[0]) P.timeline[0].tin = null;
      if (sel && !selItem()) sel = null;
      save(true); renderMedia(); renderTimeline(); renderProps(); seek(Math.min(T, total()));
    }
  });
  // Teks / grafik / musik / transisi / preset
  document.querySelector('.lp').addEventListener('click', e => {
    const g = e.target.closest('[data-gfx-add]'), tk = e.target.closest('[data-trk]'), mp = e.target.closest('[data-mprev]'), ma = e.target.closest('[data-madd]');
    if (g) return addGfx(g.dataset.gfxAdd);
    if (tk) {
      trPreset = tk.dataset.trk;
      if (sel?.t === 'tr') { setTransition(sel.id, trPreset); renderTimeline(); renderProps(); previewTransition(sel.id); }
      renderTrGrid();
      return;
    }
    if (mp) {
      if (prevFile === mp.dataset.mprev) { prevAud?.pause(); prevFile = ''; }
      else { prevAud?.pause(); prevAud = new Audio('previews/music/' + mp.dataset.mprev); prevAud.volume = 0.6; prevAud.play().catch(() => {}); prevFile = mp.dataset.mprev; prevAud.onended = () => { prevFile = ''; renderMusic(); }; }
      return renderMusic();
    }
    if (ma) return addMusic(ma.dataset.madd);
    if (e.target.closest('#ps-empty')) return $('ps-add').click();
    const ps = e.target.closest('[data-ps]'), p = ps && presets.find(x => x.id === ps.dataset.ps);
    if (p && e.target.closest('[data-ps-del]')) {
      if (!confirm(`Hapus preset "${p.name}"?`)) return;
      api('mproj_preset_delete', { id: p.id }).then(d => { presets = d.presets; renderPresets(); }).catch(err => toast(err.message));
    } else if (p && e.target.closest('[data-ps-use]')) applyPreset(p);
  });
  document.querySelector('.lp').addEventListener('change', e => {
    if (e.target.id === 'mu-in' || e.target.matches('[data-mu-up]')) { uploadMusic(e.target.files[0]); e.target.value = ''; }
  });
  $('tr-all').onclick = () => {
    if (P.timeline.length < 2) return;
    P.timeline.forEach((c, i) => { if (i) c.tin = { type: trPreset, dur: c.tin?.dur || 0.5 }; });
    save(); renderTimeline(); renderProps(); draw();
  };
  $('tr-none').onclick = () => {
    P.timeline.forEach(c => { c.tin = null; });
    if (sel?.t === 'tr') sel = null;
    save(); renderTimeline(); renderProps(); draw();
  };
  $('ps-add').onclick = () => {
    if (!P.overlays.length && !P.music.length && !P.timeline.some(c => c.tin)) return toast('Tambahkan teks, grafik, musik, atau transisi dulu.');
    $('ps-form').hidden = false;
    $('ps-name').value = `Preset ${presets.length + 1}`;
    $('ps-name').focus(); $('ps-name').select();
  };
  $('ps-form').onsubmit = async e => {
    e.preventDefault();
    await flushGfx();
    try { presets = (await api('mproj_preset_save', { name: $('ps-name').value.trim(), data: presetData() })).presets; $('ps-form').hidden = true; renderPresets(); toast('Preset disimpan'); }
    catch (err) { toast(err.message); }
  };
  $('ps-name').addEventListener('keydown', e => { if (e.key === 'Escape') $('ps-form').hidden = true; });

  const tlw = $('tl-scroll');
  // Media diseret ke timeline: di atas footage utama = overlay media; sejajar / kiri-kanan footage = footage tambahan
  const asPip = e => P.timeline.length > 0 && e.clientY - $('tl').getBoundingClientRect().top < lay.vTop - 2;
  const clearDrop = () => { showIns(null); showPipMark(null); $('track')?.classList.remove('over'); };
  tlw.addEventListener('dragover', e => {
    if (e.dataTransfer.types.includes('text/x-mid')) {
      e.preventDefault();
      if (asPip(e)) { showIns(null); $('track').classList.remove('over'); showPipMark(timeAt(e.clientX)); }
      else { showPipMark(null); showIns(insertIndex(e.clientX)); $('track').classList.add('over'); }
    }
    else if (e.dataTransfer.types.includes('Files')) { e.preventDefault(); $('track').classList.add('over'); }
  });
  tlw.addEventListener('dragleave', e => { if (!tlw.contains(e.relatedTarget)) clearDrop(); });
  tlw.addEventListener('drop', e => {
    e.preventDefault();
    const pip = asPip(e);
    clearDrop();
    const mid = e.dataTransfer.getData('text/x-mid');
    if (mid && pip) addPip(mid, timeAt(e.clientX));
    else if (mid) { if (trk('video').lock) return toast('Track video sedang dikunci.'); addClips([mid], insertIndex(e.clientX)); }
    else if (e.dataTransfer.files.length) uploadFiles([...e.dataTransfer.files]);
  });
  // Timeline: geser urutan / waktu, tarik ujung, klik = pilih; klik area kosong = lepas pilihan
  let drag = null, noSnap = false, lastEv = null, asRaf = 0;
  const timeAt = x => Math.max(0, (x - $('tl').getBoundingClientRect().left) / pps);
  const tlX = e => e.clientX - $('tl').getBoundingClientRect().left;
  // Magnet ke playhead, batas potongan & ujung item lain (8 px); tahan Alt untuk mematikan
  const snapT = (t, skip) => {
    if (noSnap) return t;
    const pts = [0, T, total(), ...P.timeline.map((_, i) => startOf(i)), ...P.overlays.filter(o => o !== skip).flatMap(o => [o.s, o.e]), ...P.music.filter(m => m !== skip).flatMap(m => [m.s, m.e]), ...P.pips.filter(q => q !== skip).flatMap(q => [q.s, q.e])];
    for (const p of pts) if (Math.abs(p - t) * pps < 8) return p;
    return t;
  };
  $('tl').addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    try { $('tl').setPointerCapture(e.pointerId); } catch {}
    pause();
    // Rentang putar: ujung = ubah awal/akhir, tengah = geser utuh
    if (e.target.closest('#prange')) {
      drag = { kind: 'range', side: e.target.dataset.pr || 'm', x0: tlX(e), s0: range.s, e0: range.e };
      document.body.classList.add('dragging');
      return;
    }
    // Kenop playhead: seret tanpa melepas pilihan
    if (e.target.closest('.ph-knob')) { drag = { kind: 'seek' }; document.body.classList.add('dragging'); return; }
    const jn = e.target.closest('[data-jn]');
    if (jn && trk('video').lock) return toast('Track video sedang dikunci.');
    if (jn) {
      const cid = jn.dataset.jn;
      if (!P.timeline.find(c => c.cid === cid).tin) setTransition(cid);
      sel = { t: 'tr', id: cid };
      renderTimeline(); renderProps(); renderTrGrid();
      if (lp !== 'tr') showLp('tr');
      previewTransition(cid); // klik transisi langsung memutarnya
      return;
    }
    const iel = e.target.closest('.it');
    if (iel && !iel.classList.contains('lk')) {
      const k = ['ov', 'pip', 'mu'].find(x => iel.classList.contains(x)), id = iel.dataset[k];
      const it = k === 'ov' ? P.overlays.find(o => o.oid === id) : k === 'pip' ? P.pips.find(q => q.id === id) : P.music.find(m => m.id === id);
      const side = e.target.closest('[data-it]')?.dataset.it;
      sel = { t: k, id };
      // Sumber bergeser saat ujung kiri ditarik: musik (off) & overlay video (in)
      const offKey = k === 'mu' ? 'off' : k === 'pip' && media(it.mid)?.kind === 'video' ? 'in' : null;
      const srcLen = k === 'mu' ? it.dur : offKey ? media(it.mid).duration : Infinity;
      drag = { kind: side ? 'itrim' : 'imove', k, it, side, x0: tlX(e), s0: it.s, e0: it.e, offKey, srcLen, off0: offKey ? it[offKey] || 0 : 0, moved: false };
      if (k !== 'mu' && !(it.s <= T && T < it.e)) T = it.s + 0.01;
      renderTimeline(); renderProps(); seek(T);
      return;
    }
    const el = e.target.closest('.clip:not(.lk)');
    if (!el) {
      // Penggaris: pindah playhead tanpa melepas pilihan; area kosong lain: lepas pilihan
      if (!e.target.closest('#ruler') && sel) { sel = null; renderTimeline(); renderProps(); }
      drag = { kind: 'seek' }; seek(timeAt(e.clientX)); return;
    }
    const c = P.timeline.find(x => x.cid === el.dataset.cid), h = e.target.closest('[data-trim]');
    if (!isSel('clip', c.cid)) { sel = { t: 'clip', id: c.cid }; renderProps(); }
    if (h) { drag = { kind: 'trim', side: h.dataset.trim, c, x0: tlX(e), in0: c.in, out0: c.out }; document.body.classList.add('dragging'); }
    else drag = { kind: 'move', c, x0: tlX(e), moved: false, at: timeAt(e.clientX) };
    renderTimeline();
  });
  // Seret dekat tepi kiri/kanan timeline: timeline ikut bergulir (zona 48 px, maks 32 px per frame)
  const autoScroll = () => {
    asRaf = 0;
    if (!drag || !lastEv) return;
    const sc = $('tl-scroll'), r = sc.getBoundingClientRect(), L = r.left + $('tl-heads').offsetWidth + 48, x = lastEv.clientX;
    const v = x < L ? -Math.min(32, (L - x) / 3) : x > r.right - 48 ? Math.min(32, (x - r.right + 48) / 3) : 0;
    if (!v) return;
    const before = sc.scrollLeft;
    sc.scrollLeft += v;
    if (sc.scrollLeft !== before) tlMove(lastEv);
    asRaf = requestAnimationFrame(autoScroll);
  };
  const tlMove = e => {
    if (!drag) return;
    lastEv = e;
    noSnap = e.altKey;
    if (!asRaf) asRaf = requestAnimationFrame(autoScroll);
    if (drag.kind === 'seek') return seek(timeAt(e.clientX));
    const dx = (tlX(e) - drag.x0) / pps;
    if (drag.kind === 'range') {
      const len = drag.e0 - drag.s0, tot = total();
      if (drag.side === 'm') { range.s = r3(clamp(drag.s0 + dx, 0, Math.max(0, tot - len))); range.e = r3(range.s + len); }
      else if (drag.side === 's') range.s = r3(clamp(snapT(drag.s0 + dx), 0, drag.e0 - 0.1));
      else range.e = r3(clamp(snapT(drag.e0 + dx), drag.s0 + 0.1, tot));
      renderTimeline();
      seek(drag.side === 'e' ? range.e : range.s);
      return;
    }
    if (drag.kind === 'imove' || drag.kind === 'itrim') {
      if (!drag.moved && Math.abs(tlX(e) - drag.x0) < 3) return;
      drag.moved = true;
      document.body.classList.add('dragging');
      const it = drag.it, len = drag.e0 - drag.s0, tot = total();
      if (drag.kind === 'imove') {
        let s = clamp(drag.s0 + dx, 0, Math.max(0, tot - 0.2));
        const sS = snapT(s, it), sE = snapT(s + len, it);
        s = sS !== s ? sS : sE !== s + len ? sE - len : s;
        it.s = r3(Math.max(0, s)); it.e = r3(it.s + len);
      } else if (drag.side === 'l') {
        const min = drag.offKey ? Math.max(0, drag.s0 - drag.off0) : 0;
        it.s = r3(clamp(snapT(drag.s0 + dx, it), min, drag.e0 - 0.2));
        if (drag.offKey) it[drag.offKey] = r3(drag.off0 + it.s - drag.s0);
      } else {
        const max = drag.offKey ? drag.s0 + drag.srcLen - drag.off0 : Math.max(tot, drag.e0);
        it.e = r3(clamp(snapT(drag.e0 + dx, it), drag.s0 + 0.2, max));
      }
      renderTimeline();
      if (drag.k !== 'mu') { T = drag.side === 'r' ? Math.max(it.s, it.e - 0.05) : it.s; seek(T); }
      return;
    }
    const c = drag.c, m = media(c.mid);
    if (drag.kind === 'trim') {
      if (m.kind === 'image') c.out = Math.round(Math.max(0.5, Math.min(60, drag.out0 + (drag.side === 'out' ? dx : -dx))) * 10) / 10;
      else if (drag.side === 'in') c.in = Math.round(Math.max(0, Math.min(c.out - 0.2, drag.in0 + dx)) * 100) / 100;
      else c.out = Math.round(Math.min(m.duration, Math.max(c.in + 0.2, drag.out0 + dx)) * 100) / 100;
      renderTimeline(); renderProps();
      const i = P.timeline.indexOf(c);
      seek(drag.side === 'in' ? startOf(i) : startOf(i) + clipLen(c) - 0.05);
      return;
    }
    if (!drag.moved && Math.abs(tlX(e) - drag.x0) < 5) return;
    if (!drag.moved) { drag.moved = true; document.body.classList.add('dragging'); $('tl').querySelector(`[data-cid="${c.cid}"]`)?.classList.add('ghost'); }
    drag.to = insertIndex(e.clientX);
    showIns(drag.to);
  };
  $('tl').addEventListener('pointermove', tlMove);
  const end = () => {
    lastEv = null;
    if (!drag) return;
    if (drag.kind === 'trim') save();
    if ((drag.kind === 'imove' || drag.kind === 'itrim') && drag.moved) { save(); renderProps(); }
    if (drag.kind === 'move') {
      if (drag.moved) {
        const from = P.timeline.indexOf(drag.c);
        let to = drag.to ?? from;
        if (to > from) to--;
        if (to !== from) { P.timeline.splice(from, 1); P.timeline.splice(to, 0, drag.c); P.timeline[0].tin = null; save(); }
        seek(startOf(P.timeline.indexOf(drag.c)));
      } else seek(drag.at);
      showIns(null);
      renderTimeline(); renderProps();
    }
    drag = null;
    document.body.classList.remove('dragging');
  };
  $('tl').addEventListener('pointerup', end);
  $('tl').addEventListener('pointercancel', end);
  $('tl-heads').addEventListener('click', e => {
    const b = e.target.closest('[data-tk]');
    if (!b) return;
    const t = trk(b.dataset.tk), f = b.dataset.tf;
    t[f] = !t[f];
    if (f === 'lock' && t.lock && sel && TK_OF[sel.t] === b.dataset.tk) sel = null;
    if (f === 'mute') { if (cur && b.dataset.tk === 'video') cur.muted = t.mute; syncMusic(); syncPips(); }
    save(); renderTimeline(); renderProps(); draw();
  });
  // Zoom timeline 50–800% (diingat di browser); setelah zoom, playhead dibawa ke tengah
  const setZoom = (z, recentre = true) => {
    zoom = clamp(Math.round(z * 100) / 100, 0.5, 8);
    $('zoom').value = zoom; $('zoom').style.cssText = pct(zoom, 0.5, 8);
    $('z-val').textContent = Math.round(zoom * 100) + '%';
    try { localStorage.setItem('mp-tl-zoom', zoom); } catch {}
    renderTimeline();
    if (recentre) { const sc = $('tl-scroll'); sc.scrollLeft = $('tl').offsetLeft + T * pps - sc.clientWidth / 2; }
  };
  $('zoom').oninput = e => setZoom(+e.target.value);
  $('z-in').onclick = () => setZoom(zoom * 1.25);
  $('z-out').onclick = () => setZoom(zoom / 1.25);
  // Ctrl/⌘ + scroll (atau pinch trackpad) = zoom timeline / preview
  $('tl-scroll').addEventListener('wheel', e => { if (!(e.ctrlKey || e.metaKey)) return; e.preventDefault(); setZoom(zoom * (e.deltaY < 0 ? 1.05 : 1 / 1.05)); }, { passive: false });
  $('screen-wrap').addEventListener('wheel', e => { if (!(e.ctrlKey || e.metaKey)) return; e.preventDefault(); setPvZoom(pvZoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1)); }, { passive: false });
  $('pv-in').onclick = () => setPvZoom(pvZoom * 1.25);
  $('pv-out').onclick = () => setPvZoom(pvZoom / 1.25);
  $('pv-fit').onclick = () => setPvZoom(1);
  try { const z = +localStorage.getItem('mp-tl-zoom'); setZoom(z >= 0.5 && z <= 8 ? z : 1, false); } catch { setZoom(1, false); }
  $('b-range').onclick = () => { range = null; renderTimeline(); toast('Rentang putar dihapus'); };
  // Transport
  $('b-play').onclick = () => playing ? pause() : play();
  $('b-prev').onclick = () => { pause(); const at = clipAt(T); if (!at) return; const s = startOf(at.i); seek(T - s > 0.3 || !at.i ? s : startOf(at.i - 1)); };
  $('b-next').onclick = () => { pause(); const at = clipAt(T); if (at && at.i < P.timeline.length - 1) seek(startOf(at.i + 1)); };
  $('b-full').onclick = toggleFull;
  $('b-start').onclick = () => { pause(); seek(range ? range.s : 0); };
  $('b-end').onclick = () => { pause(); seek(range ? range.e : total()); };
  $('b-split').onclick = split;
  $('b-del').onclick = removeSel;
  $('b-dup').onclick = duplicate;
  // Tab panel kanan & panel Layer
  $('ptabs').addEventListener('click', e => { const b = e.target.closest('[data-pt]'); if (b) { ptab = b.dataset.pt; renderProps(); } });
  let lyDrag = null;
  $('props').addEventListener('click', e => {
    const r = ptab === 'layer' && e.target.closest('.lyi');
    if (!r) return;
    const t = r.dataset.ly, id = r.dataset.id;
    if (locked(t)) return toast('Track ini sedang dikunci.');
    sel = { t, id };
    const it = selItem();
    pause();
    if (t === 'clip') seek(startOf(P.timeline.indexOf(it)));
    else if (!(it.s <= T && T < it.e)) seek(it.s + 0.01);
    renderTimeline(); renderProps(); draw();
  });
  $('props').addEventListener('dragstart', e => { const r = e.target.closest('.lyi[draggable]'); if (!r) return; lyDrag = r; r.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; });
  $('props').addEventListener('dragover', e => {
    const r = e.target.closest('.lyi');
    if (!lyDrag || !r || r === lyDrag || r.dataset.ly !== lyDrag.dataset.ly) return;
    e.preventDefault();
    const top = e.clientY < r.getBoundingClientRect().top + r.offsetHeight / 2;
    $('props').querySelectorAll('.over-t,.over-b').forEach(x => x.classList.remove('over-t', 'over-b'));
    r.classList.add(top ? 'over-t' : 'over-b');
  });
  $('props').addEventListener('dragend', () => { lyDrag?.classList.remove('drag'); lyDrag = null; $('props').querySelectorAll('.over-t,.over-b').forEach(x => x.classList.remove('over-t', 'over-b')); });
  $('props').addEventListener('drop', e => {
    const r = e.target.closest('.lyi');
    if (!lyDrag || !r || r === lyDrag || r.dataset.ly !== lyDrag.dataset.ly) return;
    e.preventDefault();
    // Urutan tampilan (atas = depan) dibalik jadi urutan array (akhir = paling depan)
    const t = r.dataset.ly, key = t === 'ov' ? 'overlays' : 'pips', idOf = x => t === 'ov' ? x.oid : x.id;
    const vis = [...P[key]].reverse(), from = vis.findIndex(x => idOf(x) === lyDrag.dataset.id), [moved] = vis.splice(from, 1);
    let to = vis.findIndex(x => idOf(x) === r.dataset.id);
    if (!(e.clientY < r.getBoundingClientRect().top + r.offsetHeight / 2)) to++;
    vis.splice(to, 0, moved);
    P[key] = vis.reverse();
    save(); renderProps(); draw();
  });
  // Panel properti
  $('props-h').addEventListener('click', e => {
    const a = e.target.closest('[data-act]')?.dataset.act;
    if (a === 'close') select(null);
    else if (a === 'del') removeSel();
    else if (a === 'dup') duplicate();
    else if (a === 'split') split();
  });
  $('props').addEventListener('click', async e => {
    const b = e.target.closest('button');
    if (!b) return;
    // Stepper ±0,1 dtk
    if (b.dataset.st) {
      const inp = b.parentElement.querySelector('input'), tm = inp.classList.contains('tm');
      const nv = Math.max(0, r3((tm ? parseT(inp.value) : +inp.value) + 0.1 * b.dataset.st));
      inp.value = tm ? fmtT(nv) : nv.toFixed(1);
      inp.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    const it = selItem();
    if (b.dataset.ratio) { P.ratio = b.dataset.ratio; P.ratio_set = true; P.overlays.filter(isFullGfx).forEach(o => scheduleGfx(o, true)); save(); sizeScreen(); renderProps(); }
    else if (b.dataset.fit) { P.fit = b.dataset.fit; save(); renderProps(); draw(); }
    else if (b.dataset.cfit !== undefined && it) { it.fit = b.dataset.cfit || null; it.tr = null; save(); renderProps(); draw(); }
    else if (b.dataset.trq && it) {
      const sc = $('screen'), t = { ...effTr(it, sc.clientWidth, sc.clientHeight) };
      if (b.dataset.trq === 'fit') Object.assign(t, { s: 1, x: 0.5, y: 0.5 });
      else if (b.dataset.trq === 'cover') Object.assign(t, { s: coverRatio(media(it.mid), sc.clientWidth, sc.clientHeight), x: 0.5, y: 0.5 });
      else Object.assign(t, { x: 0.5, y: 0.5 });
      it.tr = t; save(); renderProps(); draw();
    }
    else if (b.dataset.gtype && it) { switchGfx(it, b.dataset.gtype); save(); renderProps(); renderTimeline(); markGfxTiles(); }
    else if (b.dataset.oq && it) {
      if (b.dataset.oq === 'center') { it.x = 0.5; if (sel.t === 'pip') it.y = 0.5; }
      else if (b.dataset.oq === 'fill') Object.assign(it, { w: 1, x: 0.5, y: 0.5 });
      else if (sel.t === 'pip' && media(it.mid).kind === 'video') { it.s = 0; it.e = r3(Math.min(total(), media(it.mid).duration - it.in)); }
      else { it.s = 0; it.e = r3(total()); }
      save(); renderProps(); renderTimeline(); draw();
    }
    else if (b.dataset.trk && it) { trPreset = b.dataset.trk; setTransition(it.cid, trPreset); renderTimeline(); renderProps(); renderTrGrid(); previewTransition(it.cid); }
    else if (b.dataset.act === 'tplay' && it) previewTransition(it.cid);
    else if (b.dataset.gal !== undefined && it?.gfx) { it.gfx.ta = b.dataset.gal; scheduleGfx(it); save(); renderProps(); }
    else if (b.dataset.anim && it) { it.anim = { ...ANIM_NONE, ...(it.anim || {}), [b.dataset.anim]: b.dataset.v }; save(); renderProps(); playAnim(it, b.dataset.anim); }
    else if (b.dataset.act === 'aplay' && it) playAnim(it, 'in');
    else if (b.dataset.act === 'tall' && it?.tin) { P.timeline.forEach((c, i) => { if (i) c.tin = { ...it.tin }; }); save(); renderTimeline(); draw(); toast('Dipasang di semua sambungan'); }
    else if (b.dataset.rdel) {
      if (!confirm('Hapus hasil render ini? File-nya ikut dihapus dari folder downloads.')) return;
      P.renders = (await api('mproj_render_delete', { id: PID, job: b.dataset.rdel })).project.renders;
      renderProps();
    }
  });
  $('props').addEventListener('input', e => {
    const it = selItem(), k = e.target.dataset.k, out = e.target.nextElementSibling;
    if (!it) return;
    if (e.target.dataset.gc || e.target.dataset.gh) {
      // Warna dari pemilih warna atau kode hex (6 digit); keduanya saling diperbarui
      const ck = e.target.dataset.gc || e.target.dataset.gh, hex = e.target.dataset.gc ? e.target.value : '#' + e.target.value.replace(/^#/, '');
      if (!/^#[0-9a-f]{6}$/i.test(hex)) return;
      it.gfx[ck] = hex.toUpperCase();
      const row = e.target.closest('.colr');
      row.querySelector('.sw').style.setProperty('--c', hex);
      if (e.target.dataset.gc) row.querySelector('.hex').value = hex.slice(1).toUpperCase(); else row.querySelector('input[type=color]').value = hex.toLowerCase();
      scheduleGfx(it); save(); return;
    }
    if (e.target.dataset.g) {
      it.gfx[e.target.dataset.g] = e.target.value;
      const c = e.target.previousElementSibling?.querySelector('.cnt');
      if (c) c.textContent = `${e.target.value.length}/${e.target.maxLength}`;
      scheduleGfx(it); save(); renderTimeline(); return;
    }
    // Kolom angka posisi: nilai disalin ke slider pasangannya lalu diproses sama
    if (e.target.dataset.kn) {
      const rg = e.target.closest('.rng').querySelector('input[type=range]'), nv = +e.target.value;
      if (e.target.value === '' || isNaN(nv)) return;
      rg.value = nv;
      rg.style.cssText = pct(nv, +rg.min, +rg.max);
      return applyRange(it, e.target.dataset.kn, clamp(nv, +rg.min, +rg.max));
    }
    if (e.target.type === 'range') {
      e.target.style.cssText = pct(+e.target.value, +e.target.min, +e.target.max);
      const n = e.target.closest('.rng')?.querySelector('[data-kn]');
      if (n) n.value = e.target.value;
    }
    const v = +e.target.value;
    if (out?.classList.contains('v')) out.textContent = e.target.value + (e.target.dataset.u === ' dtk' ? ' dtk' : e.target.dataset.u || '%');
    if (k === 'ty') return applyRange(it, k, v);
    if (k === 'scale') { const sc = $('screen'); it.tr = { ...effTr(it, sc.clientWidth, sc.clientHeight), s: v / 100 }; draw(); }
    else if (k === 'vol') { it.vol = v; if (cur) cur.volume = Math.min(1, v / 100); }
    else if (k === 'ts' || k === 'ls' || k === 'lh') { it.gfx[k] = v; scheduleGfx(it); }
    else if (k === 'op') { it.opacity = v; draw(); }
    else if (k === 'din' || k === 'dout') { it.anim = { ...ANIM_NONE, ...(it.anim || {}), [k]: v }; }
    else if (k === 'w') { it.w = v / 100; draw(); }
    else if (k === 'mvol' || k === 'pvol') { it.vol = v; syncPips(); }
    else if (k === 'tdur' && it.tin) { it.tin.dur = v; renderTimeline(); draw(); }
    else return;
    save();
  });
  $('props').addEventListener('change', e => {
    const it = selItem(), k = e.target.dataset.k;
    if (it?.gfx && e.target.matches('[data-gfill]')) { it.gfx.fill = e.target.checked; scheduleGfx(it, true); save(); return; }
    const tm = e.target.classList.contains('tm');
    if (!it || !k || !(e.target.type === 'number' || tm)) return;
    const v = tm ? parseT(e.target.value) : parseFloat(e.target.value);
    if (isNaN(v)) return renderProps();
    if (sel.t === 'clip') {
      const m = media(it.mid);
      if (m.kind === 'image') it.out = Math.max(0.5, Math.min(60, v));
      else if (k === 'in') it.in = Math.max(0, Math.min(it.out - 0.2, v));
      else it.out = Math.min(m.duration, Math.max(it.in + 0.2, v));
      save(); renderTimeline(); renderProps(); seek(startOf(P.timeline.indexOf(it)));
      return;
    }
    // Panjang sumber: musik (dur − off), overlay video (durasi − in)
    const pv = sel.t === 'pip' && media(it.mid).kind === 'video', srcMax = () => sel.t === 'mu' ? it.s + it.dur - it.off : pv ? it.s + media(it.mid).duration - it.in : 3600;
    if (k === 's') { const len = it.e - it.s; it.s = r3(clamp(v, 0, Math.max(0, total() - 0.2))); if (sel.t === 'mu') it.e = r3(Math.max(it.s + 0.2, it.e)); else it.e = r3(Math.min(srcMax(), it.s + len)); }
    else if (k === 'e') it.e = r3(clamp(v, it.s + 0.2, srcMax()));
    else if (k === 'in' && pv) { it.in = r3(clamp(v, 0, Math.max(0, media(it.mid).duration - 0.2))); it.e = r3(Math.min(it.e, srcMax())); }
    else if (k === 'off') { it.off = r3(clamp(v, 0, Math.max(0, it.dur - 0.5))); it.e = r3(Math.min(it.e, it.s + it.dur - it.off)); }
    else if (k === 'fin' || k === 'fout') it[k] = Math.round(clamp(v, 0, 10) * 10) / 10;
    save(); renderTimeline(); renderProps(); draw();
  });
  // Layar preview: klik grafik / footage = pilih; geser = pindah; tarik sudut = ubah ukuran; klik kosong = lepas pilihan
  let tdrag = null;
  $('ftool').addEventListener('click', e => {
    const a = e.target.closest('[data-fa]')?.dataset.fa;
    if (a === 'edit') editSel(); else if (a === 'del') removeSel();
  });
  $('screen').addEventListener('dblclick', e => { if (sel?.t === 'ov' && !e.target.closest('#ftool')) editSel(); });
  $('screen').addEventListener('pointerdown', e => {
    if (!P.timeline.length || e.button !== 0 || e.target.closest('#ftool')) return;
    pause();
    const sc = $('screen'), W = sc.clientWidth, H = sc.clientHeight, box = sc.getBoundingClientRect();
    const px = e.clientX - box.left, py = e.clientY - box.top, h = e.target.closest('[data-h]');
    const inR = r => px >= r.x && px <= r.x + r.w && py >= r.y && py <= r.y + r.h;
    // Urutan dari paling atas: teks & grafik, overlay media, footage utama (track tersembunyi / terkunci dilewati)
    const free = k => !trk(k).hide && !trk(k).lock;
    let target = null, kind = null;
    if (h && sel && selItem()) { target = selItem(); kind = sel.t; }
    if (!target && free('gfx')) { target = [...P.overlays].reverse().find(o => ovVisible(o, T) && ovImg(o) && inR(ovRect(o, W, H))); kind = 'ov'; }
    if (!target && free('pip')) { target = [...P.pips].reverse().find(q => pipVisible(q) && inR(pipRect(q, W, H))); kind = 'pip'; }
    if (!target && free('video')) { const c = P.timeline[clipAt(T).i]; if (inR(layerRect(media(c.mid), effTr(c, W, H), W, H))) { target = c; kind = 'clip'; } }
    if (!target) { if (sel) select(null); return; }
    const isOv = kind !== 'clip', tid = kind === 'ov' ? target.oid : kind === 'pip' ? target.id : target.cid;
    if (!isSel(kind, tid)) { sel = { t: kind, id: tid }; renderTimeline(); renderProps(); }
    e.preventDefault();
    try { sc.setPointerCapture(e.pointerId); } catch {}
    const st = isOv ? { s: target.w, x: target.x, y: target.y } : { ...effTr(target, W, H) };
    const cx = box.left + st.x * W, cy = box.top + st.y * H;
    if (h && kind === 'ov' && isFullGfx(target)) return;
    tdrag = h ? { kind: 'scale', it: target, isOv, st, cx, cy, d0: Math.hypot(e.clientX - cx, e.clientY - cy) || 1, W, H }
      : { kind: 'move', it: target, isOv, st, x0: e.clientX, y0: e.clientY, W, H };
    $('tbox').classList.add('act');
    if (tdrag.kind === 'move') $('safe').classList.add('on');
    draw();
  });
  // Ukuran objek (pecahan layar) untuk magnet tepi area aman
  const objSize = (it, isOv, s, W, H) => {
    if (!isOv) { const r = layerRect(media(it.mid), { s, x: 0.5, y: 0.5 }, W, H); return [r.w / W, r.h / H]; }
    const r = it.oid ? ovRect({ ...it, w: s }, W, H) : pipRect({ ...it, w: s }, W, H);
    return [r.w / W, r.h / H];
  };
  const hit = { v: false, h: false, l: false, r: false, t: false, b: false };
  $('screen').addEventListener('pointermove', e => {
    if (!tdrag) return;
    const { it, isOv, st, W, H } = tdrag, t = { ...st };
    let gv = false, gh = false;
    Object.keys(hit).forEach(k => { hit[k] = false; });
    if (tdrag.kind === 'move') {
      t.x = st.x + (e.clientX - tdrag.x0) / W;
      t.y = st.y + (e.clientY - tdrag.y0) / H;
      // Magnet (22 px pada layar 1080): tengah layar & tepi area aman — sama dengan editor clip; Alt = tanpa magnet
      if (!e.altKey) {
        const [ow, oh] = objSize(it, isOv, st.s, W, H), sx = SNAP, sy = SNAP * W / H;
        if (Math.abs(t.x - 0.5) < sx) { t.x = 0.5; gv = hit.v = true; }
        else if (Math.abs(t.x - ow / 2 - SAFE_X) < sx) { t.x = SAFE_X + ow / 2; hit.l = true; }
        else if (Math.abs(t.x + ow / 2 - (1 - SAFE_X)) < sx) { t.x = 1 - SAFE_X - ow / 2; hit.r = true; }
        if (Math.abs(t.y - 0.5) < sy) { t.y = 0.5; gh = hit.h = true; }
        else if (Math.abs(t.y - oh / 2 - SAFE_Y) < sy) { t.y = SAFE_Y + oh / 2; hit.t = true; }
        else if (Math.abs(t.y + oh / 2 - (1 - SAFE_Y)) < sy) { t.y = 1 - SAFE_Y - oh / 2; hit.b = true; }
      }
      // Titik tengah tidak boleh keluar layar
      t.x = clamp(t.x, 0, 1); t.y = clamp(t.y, 0, 1);
    } else {
      t.s = st.s * Math.hypot(e.clientX - tdrag.cx, e.clientY - tdrag.cy) / tdrag.d0;
      const snaps = isOv ? [1] : [1, coverRatio(media(it.mid), W, H)];
      for (const s of snaps) if (Math.abs(t.s - s) / s < 0.03) t.s = s;
    }
    const R = v => Math.round(v * 1000) / 1000;
    if (isOv && isFullGfx(it)) { it.y = R(clamp(t.y, 0, 1)); gv = false; }
    else if (isOv) Object.assign(it, { w: R(clamp(t.s, 0.05, 2)), x: R(clamp(t.x, -0.5, 1.5)), y: R(clamp(t.y, -0.5, 1.5)) });
    else it.tr = { s: R(clamp(t.s, 0.1, 5)), x: R(t.x), y: R(t.y) };
    $('gv').hidden = !gv; $('gh').hidden = !gh;
    const sb = $('safe');
    Object.assign($('g-l').style, { left: sb.style.left }); Object.assign($('g-r').style, { left: `calc(100% - ${sb.style.right})` });
    Object.assign($('g-t').style, { top: sb.style.top }); Object.assign($('g-b').style, { top: `calc(100% - ${sb.style.bottom})` });
    $('g-l').hidden = !hit.l; $('g-r').hidden = !hit.r; $('g-t').hidden = !hit.t; $('g-b').hidden = !hit.b;
    draw();
  });
  const tend = () => {
    if (!tdrag) return;
    tdrag = null;
    $('tbox').classList.remove('act');
    $('safe').classList.remove('on');
    ['gv', 'gh', 'g-l', 'g-r', 'g-t', 'g-b'].forEach(k => { $(k).hidden = true; });
    // Banner judul pindah posisi: pitanya dihitung ulang supaya tetap menjulur sampai bawah layar
    if (sel?.t === 'ov' && isFullGfx(selItem())) scheduleGfx(selItem(), true);
    save(); renderProps(); draw();
  };
  $('screen').addEventListener('pointerup', tend);
  $('screen').addEventListener('pointercancel', tend);
  // Klik di area kosong (di luar item, panel, & kontrol) melepas pilihan — sama dengan editor clip
  document.addEventListener('pointerdown', e => {
    if (!sel || e.button !== 0) return;
    if (e.target.closest('#tl, #screen, .props, .lp, #rail, .modal, button, a, input, textarea, select, label, form')) return;
    select(null);
  }, true); // fase capture: sebelum timeline / layar dirender ulang oleh handler-nya sendiri
  // Render
  $('btn-render').onclick = openRender;
  $('rm-close').onclick = () => { $('rmodal').hidden = true; };
  $('rmodal').addEventListener('click', e => { if (e.target === $('rmodal')) $('rmodal').hidden = true; });
  $('rm-opts').addEventListener('change', e => {
    if (e.target.name !== 'rq') return;
    rq.mode = e.target.value;
    rq.short = rq.mode === 'native' ? info.native : rq.mode === 'ai' ? info.qualities.find(q => q > info.native) : (rq.short || info.native);
    paintRender();
  });
  $('rm-opts').addEventListener('click', e => {
    const q = e.target.closest('[data-q],[data-ai]');
    if (!q) return;
    e.preventDefault();
    rq.short = +(q.dataset.q || q.dataset.ai);
    paintRender();
  });
  $('rm-go').onclick = async () => {
    $('rm-go').disabled = true;
    try {
      await save(true); // susunan terbaru (termasuk PNG grafik) tersimpan sebelum render
      await api('mproj_render', { id: PID, short: rq.short || info.native, upscale: rq.mode === 'ai' });
      toast('Render dimulai');
    } catch (err) { toast(err.message); }
    load();
  };
  // Keyboard — sama dengan editor clip. Slider, checkbox & tombol tidak menahan shortcut; kolom teks/angka/dropdown menahan.
  const typing = el => !!el && (el.isContentEditable || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT'
    || (el.tagName === 'INPUT' && !['range', 'checkbox', 'radio', 'color', 'button', 'submit', 'file'].includes(el.type)));
  document.addEventListener('keydown', e => {
    if (!$('rmodal').hidden) { if (e.key === 'Escape') $('rmodal').hidden = true; return; }
    if (typing(document.activeElement)) { if (e.key === 'Escape') document.activeElement.blur(); return; }
    const mod = e.metaKey || e.ctrlKey, k = e.key.toLowerCase();
    if (mod && k === 'z') { e.preventDefault(); undo(e.shiftKey); }
    else if (mod && k === 'y') { e.preventDefault(); undo(true); }
    else if (mod && e.shiftKey && k === 'd') { e.preventDefault(); split(); }
    else if (mod && k === 'd') { e.preventDefault(); duplicate(); }
    else if (mod && k === 'b') { e.preventDefault(); setRange('s'); }
    else if (mod && k === 'e') { e.preventDefault(); setRange('e'); }
    else if (mod || e.altKey) return;
    else if (e.code === 'Space') { e.preventDefault(); document.activeElement?.blur?.(); playing ? pause() : play(); }
    else if (e.key === '`' || k === 'f') { e.preventDefault(); toggleFull(); }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && sel) { e.preventDefault(); removeSel(); }
    else if (k === 's') split();
    else if (k === 'i') setRange('s');
    else if (k === 'o') setRange('e');
    else if (e.key === 'Enter' && sel?.t === 'ov') { e.preventDefault(); editSel(); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); pause(); seek(T - (e.shiftKey ? 1 : 0.1)); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); pause(); seek(T + (e.shiftKey ? 1 : 0.1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); $('b-prev').click(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); $('b-next').click(); }
    else if (e.key === 'Home') { e.preventDefault(); $('b-start').click(); }
    else if (e.key === 'End') { e.preventDefault(); $('b-end').click(); }
    else if (e.key === 'Escape') { if (sel) select(null); else if (range) { range = null; renderTimeline(); } }
  });
  // Peringatan sebelum meninggalkan halaman kalau masih ada perubahan / grafik yang belum tersimpan
  window.addEventListener('beforeunload', e => {
    if (unsaved || gfxJobs.size || P.overlays.some(o => o._t)) { e.preventDefault(); e.returnValue = ''; }
  });
  document.addEventListener('fullscreenchange', () => {
    const on = !!document.fullscreenElement;
    $('b-full').innerHTML = hi(on ? 'unfull' : 'full');
    $('b-full').dataset.tip = on ? 'Keluar layar penuh (F / `)' : 'Layar penuh (F / `)';
  });
  document.addEventListener('fullscreenchange', () => setTimeout(sizeScreen, 50));
}
function toggleFull() {
  if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
  else $('stage').requestFullscreen?.().catch(() => {});
}
// Rentang putar: I / Ctrl+B = awal, O / Ctrl+E = akhir di posisi playhead
function setRange(side) {
  if (!P.timeline.length) return;
  const r = range ? { ...range } : { s: 0, e: r3(total()) };
  r[side] = r3(T);
  if (r.e - r.s < 0.1) { if (side === 's') r.e = r3(total()); else r.s = 0; }
  if (r.e - r.s < 0.1) return toast('Rentang putar terlalu pendek.');
  range = r;
  renderTimeline();
  toast(`${side === 's' ? 'Awal' : 'Akhir'} rentang putar: ${fmtT(T)}`);
}
// Edit teks grafik terpilih: buka tab Properti & fokus ke kolom teks (Enter / klik dua kali / tombol Edit)
function editSel() {
  if (sel?.t !== 'ov') return;
  ptab = 'props';
  renderProps();
  const ta = $('props').querySelector('[data-g=text]');
  if (!ta) return;
  ta.focus(); ta.select();
  ta.scrollIntoView({ block: 'center', behavior: 'smooth' });
  $('tbox').classList.add('edit');
  ta.addEventListener('blur', () => $('tbox').classList.remove('edit'), { once: true });
}
/* Tooltip mengambang untuk semua atribut title (menggantikan tooltip bawaan browser) */
const tipEl = document.createElement('div');
tipEl.className = 'tipbox';
document.body.appendChild(tipEl);
let tipFor = null;
function hideTip() { tipFor = null; tipEl.classList.remove('show'); }
function showTip(el) {
  const t = el.dataset.tip, m = /^(.*\S)\s*\(([^()%=]{1,18})\)$/.exec(t);
  tipEl.innerHTML = m ? `${esc(m[1])}<kbd>${esc(m[2])}</kbd>` : esc(t);
  tipEl.classList.remove('show', 'up');
  const r = el.getBoundingClientRect(), tw = tipEl.offsetWidth, th = tipEl.offsetHeight;
  let y = r.bottom + 8;
  if (y + th > innerHeight - 8) { y = r.top - th - 8; tipEl.classList.add('up'); }
  Object.assign(tipEl.style, { left: clamp(r.left + r.width / 2 - tw / 2, 8, innerWidth - tw - 8) + 'px', top: y + 'px' });
  requestAnimationFrame(() => { if (tipFor === el) tipEl.classList.add('show'); });
}
document.addEventListener('mouseover', e => {
  const el = e.target.closest?.('[title], [data-tip]');
  if (!el || el === tipFor) return;
  if (el.hasAttribute('title')) { el.dataset.tip = el.getAttribute('title'); el.removeAttribute('title'); }
  if (!el.dataset.tip || document.body.classList.contains('dragging')) return;
  tipFor = el;
  showTip(el);
});
document.addEventListener('mouseout', e => { if (tipFor && !tipFor.contains(e.relatedTarget)) hideTip(); });
document.addEventListener('pointerdown', hideTip, true);
document.addEventListener('scroll', hideTip, true);
// Posisi vertikal footage / overlay media dalam persen tinggi layar (titik tengah; 50% = tengah)
function applyRange(it, k, v) {
  if (k !== 'ty') return;
  if (sel.t === 'pip') it.y = r3(v / 100);
  else { const sc = $('screen'); it.tr = { ...effTr(it, sc.clientWidth, sc.clientHeight), y: r3(v / 100) }; }
  draw(); save();
}
// Potong potongan di posisi playhead jadi dua
function split() {
  if (!P.timeline.length) return;
  if (trk('video').lock) return toast('Track video sedang dikunci.');
  const at = clipAt(T), c = P.timeline[at.i], m = media(c.mid);
  if (at.local < 0.15 || clipLen(c) - at.local < 0.15) return toast('Geser playhead ke tengah potongan.');
  pause();
  const b = { ...c, cid: rid(), tin: null };
  if (m.kind === 'image') { b.out = Math.round((clipLen(c) - at.local) * 10) / 10; c.out = Math.round(at.local * 10) / 10; }
  else { const t = Math.round((c.in + at.local) * 100) / 100; b.in = t; c.out = t; }
  P.timeline.splice(at.i + 1, 0, b);
  sel = { t: 'clip', id: b.cid };
  save(); renderTimeline(); renderProps();
}
function duplicate() {
  const it = selItem();
  if (!it || sel.t === 'tr') return;
  if (sel.t === 'clip') {
    const i = P.timeline.indexOf(it), d = { ...it, cid: rid(), tin: null };
    P.timeline.splice(i + 1, 0, d);
    sel = { t: 'clip', id: d.cid };
  } else if (sel.t === 'ov') {
    if (!isHook(it)) return toast('Grafik hanya satu per project. Pilih variasi lain untuk menggantinya.');
    const d = { ...ovData(it), oid: rid(), gfx: { ...it.gfx }, y: r3(Math.min(1.4, it.y + 0.06)) };
    d._prev = it._prev;
    P.overlays.push(d);
    sel = { t: 'ov', id: d.oid };
  } else if (sel.t === 'pip') {
    const d = { ...it, id: rid(), x: r3(Math.min(1.4, it.x + 0.05)), y: r3(Math.min(1.4, it.y + 0.05)) };
    P.pips.push(d);
    sel = { t: 'pip', id: d.id };
  } else {
    const len = it.e - it.s, d = { ...it, id: rid(), s: r3(it.e), e: r3(it.e + len) };
    P.music.push(d);
    sel = { t: 'mu', id: d.id };
  }
  save(); renderTimeline(); renderProps(); draw();
}
function removeSel() {
  if (!sel) return;
  if (locked(sel.t)) return toast('Track ini sedang dikunci.');
  pause();
  if (sel.t === 'tr') { const c = selItem(); if (c) c.tin = null; }
  else if (sel.t === 'ov') P.overlays = P.overlays.filter(o => o.oid !== sel.id);
  else if (sel.t === 'mu') P.music = P.music.filter(m => m.id !== sel.id);
  else if (sel.t === 'pip') P.pips = P.pips.filter(q => q.id !== sel.id);
  else {
    const i = P.timeline.findIndex(x => x.cid === sel.id);
    if (i < 0) return;
    P.timeline.splice(i, 1);
    if (P.timeline[0]) P.timeline[0].tin = null;
  }
  sel = null;
  save(); renderTimeline(); renderProps(); seek(Math.min(T, total()));
}

if (PID) load(true); else listView();
</script>
</body>
</html>
