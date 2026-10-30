<?php
/**
 * Official order for one service record — printable and downloadable at any time.
 *
 * Laid out on the office letterhead supplied by the district administration:
 * Government of the Punjab emblem, three bold lines in Goudy Old Style, A4 with
 * the same margins as the Word template (top 1.43cm, left 2.54cm, right 1.79cm).
 *
 *   reports/order.php?table=postings&id=12                -> print / save as PDF
 *   reports/order.php?table=postings&id=12&download=doc   -> Word (.doc) download
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/order_ui.php';
require_login();

$table = (string)get('table', '');
$id    = get_int('id', 0);

if (!order_supported($table) || $id <= 0) {
    flash('danger', 'That record type does not produce an official order.');
    redirect('dashboard/index.php');
}

$o = order_build($table, $id);
if (!$o) {
    flash('danger', 'Record not found.');
    redirect('dashboard/index.php');
}
$emp = $o['employee'];
require_employee_access($emp);
if (is_employee_user() && ($table !== 'postings' || $o['is_voided'])) {
    flash('warning', 'Only your posting orders are available here.');
    redirect('employees/view.php?id=' . (int)$emp['id'] . '#tab-documents');
}

/* ---- the officer edited the letter ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_editor();
    require_employee_access($emp, true);     // only for employees in the officer's jurisdiction
    $default = ['order_no' => $o['default_order_no'], 'subject' => $o['default_subject'],
                'body' => $o['default_body'], 'copy_to' => $o['default_copy_to']];
    if (post('reset_order')) {
        order_reset_override($table, $id);
        log_activity('UPDATE', $table, $id, null, ['order' => 'reset to default'], 'Order wording reset to default', (int)$emp['id']);
        flash('success', 'The order was reset to the generated wording.');
    } else {
        order_save_override($table, $id, [
            'filing_no' => post('filing_no'), 'subject' => post('subject'),
            'body'      => post('body'),      'copy_to' => post('copy_to'),
        ], $default);
        log_activity('UPDATE', $table, $id, null, ['order' => 'edited', 'filing_no' => post('filing_no')],
            'Order document edited', (int)$emp['id']);
        flash('success', 'Order saved. It will print with your wording from now on.');
    }
    redirect('reports/order.php?table=' . rawurlencode($table) . '&id=' . $id);
}

$isDoc   = get('download') === 'doc';
/* letterhead, heading and signature come from the authority signing this
   order — the officer who issued it (see includes/signing.php) */
$signer  = $o['signer'];
$emblem  = $signer['show_emblem'];
$lines   = $signer['letterhead'];
$heading = $signer['heading'] ?: 'ORDER';
$copyTo  = $o['copy_to'];
$authName   = $signer['name'];
$authDesig  = $signer['designation'];
$authOffice = $signer['office'];
$emblemPath = ROOT_PATH . '/assets/img/punjab-emblem.jpg';
$signature  = signing_signature_src($signer['signature']);
$sigWidth   = max(60, min(400, (int)$signer['signature_width']));

/* Word downloads must carry the emblem inside the file, not as a link. */
$emblemSrc = asset('img/punjab-emblem.jpg');
if ($isDoc && is_file($emblemPath)) {
    $emblemSrc = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($emblemPath));
}

log_activity('EXPORT', 'reports', $id, null, ['order' => $table, 'format' => $isDoc ? 'doc' : 'print'],
    $o['kind'] . ' ' . $o['order_no'] . ' ' . ($isDoc ? 'downloaded' : 'printed') . ': ' . $emp['name'], (int)$emp['id']);

if ($isDoc) {
    $fname = preg_replace('/[^A-Za-z0-9._-]+/', '-', $o['order_no'] . '-' . $emp['employee_code']) . '.doc';
    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
}
?>
<!DOCTYPE html>
<html lang="en" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($o['kind']) ?> — <?= e($o['order_no']) ?></title>
<?php if (!$isDoc): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sorts+Mill+Goudy:ital@0;1&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php endif; ?>
<style>
@page { size: A4; margin: 1.43cm 0.85cm 2.54cm 1.1cm; }
body {
    font-family: 'Goudy Old Style', 'Sorts Mill Goudy', Garamond, Georgia, 'Times New Roman', serif;
    font-size: 12.5pt; line-height: 1.5; color: #000; background: #eef1f5; margin: 0;
}
.sheet {
    background: #fff; width: 21cm; min-height: 29.7cm; box-sizing: border-box;
    margin: 0 auto; padding: 1.43cm 1.79cm 2.54cm 2.54cm;
    box-shadow: 0 6px 30px rgba(15, 32, 22, .16);
}
/* ---- letterhead: geometry from the office Word template ----
   emblem  : floating, 3.07 x 2.73 cm, 1.31 cm left of the text column
   headings: block 6.96 cm wide, 10.5 cm from the left margin, centred in it,
             bold 14 pt, line spacing 0.9                                    */
.lh { position: relative; min-height: 2.9cm; margin-bottom: 14px; }
.lh .lh-emblem { position: absolute; left: -1.31cm; top: 0.14cm; width: 3.07cm; height: 2.73cm; object-fit: contain; }
.lh .lh-text   { position: absolute; left: 10.5cm; top: 0.43cm; width: 6.96cm; text-align: center; }
.lh .l { font-weight: bold; font-size: 14pt; line-height: 0.9; }


/* ---- order meta ---- */
.meta { display: flex; justify-content: space-between; align-items: flex-end; font-size: 12pt; }
.meta .no { font-weight: bold; }
h1.order-title {
    text-align: left; font-size: 15pt; font-weight: bold; letter-spacing: 2px;
    text-decoration: underline; text-underline-offset: 4px; margin: 18px 0 14px;
}
h1.order-title span { text-decoration: underline; }
.subject { margin-bottom: 14px; text-align: justify; font-weight: bold; text-transform: uppercase; }
.subject b { text-decoration: underline; }
.para { text-align: justify; margin: 0 0 11px; }
ol.paras { padding-left: 22px; margin: 0 0 11px; }
ol.paras li { text-align: justify; margin-bottom: 9px; }

/* ---- particulars ---- */
table.facts { border-collapse: collapse; width: 100%; margin: 4px 0 14px; font-size: 11.5pt; }
table.facts td { border: 1px solid #000; padding: 4px 8px; vertical-align: top; }
table.facts td.k { width: 34%; font-weight: bold; background: #f2f2f2; }

/* ---- signature: block stays on the right, its lines centred within it ---- */
.sign { margin-top: 34px; width: 7cm; margin-left: auto; text-align: center; }
.sign .nm { font-weight: bold; }
.sign img.sig { display: block; margin: 0 auto 2px; max-height: 2.2cm; object-fit: contain; }

/* ---- endorsement: the distribution list only ---- */
.endorse { margin-top: 30px; font-size: 12pt; }
.endorse ol { padding-left: 24px; margin: 6px 0 0; }
.endorse li { margin-bottom: 3px; }
.voided { border: 2pt solid #b00; color: #b00; font-weight: bold; text-align: center; padding: 6px; margin-bottom: 12px; letter-spacing: 1px; }

/* ================= editing workbench (screen only) =================
   Editor on the left, live document preview on the right.              */
.wb { display: flex; align-items: flex-start; gap: 22px; padding: 18px 22px 40px; font-family: Inter, system-ui, Arial, sans-serif; }
.wb-edit { flex: 0 0 400px; position: sticky; top: 18px; max-height: calc(100vh - 36px); overflow-y: auto; }
.wb-view { flex: 1 1 auto; min-width: 0; display: flex; justify-content: center; }
.wb-fit { transform-origin: top center; }
@media (max-width: 1180px) {
    .wb { flex-direction: column; padding: 14px; }
    .wb-edit { flex: 1 1 auto; position: static; width: 100%; max-height: none; }
    .wb-view { width: 100%; overflow-x: auto; }
}

.card-ed { background: #fff; border: 1px solid #dfe5ec; border-radius: 12px; box-shadow: 0 2px 10px rgba(15,32,22,.06); overflow: hidden; }
.card-ed + .card-ed { margin-top: 14px; }
.card-ed .hd {
    padding: 13px 16px; border-bottom: 1px solid #eef1f5; background: #fafbfc;
    font-weight: 650; font-size: 14px; color: #01411C; display: flex; align-items: center; gap: 8px;
}
.card-ed .hd .sub { font-weight: 400; font-size: 12px; color: #64748b; margin-left: auto; }
.card-ed .bd { padding: 14px 16px 16px; }

.fld { margin-bottom: 14px; }
.fld:last-child { margin-bottom: 0; }
.fld label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; font-weight: 650; color: #64748b; margin-bottom: 5px; }
.fld input[type=text], .fld textarea {
    width: 100%; box-sizing: border-box; font-family: Inter, system-ui, Arial, sans-serif; font-size: 13.5px;
    padding: 9px 11px; border: 1px solid #cbd5e1; border-radius: 8px; color: #0f172a; background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.fld input[type=text]:focus, .fld textarea:focus { outline: 0; border-color: #046A38; box-shadow: 0 0 0 3px rgba(4,106,56,.12); }
.fld textarea { line-height: 1.6; resize: vertical; }
.fld .hint { font-size: 11.5px; color: #64748b; margin-top: 5px; line-height: 1.45; }

.acts { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.btn-ed {
    font-family: inherit; font-size: 13px; font-weight: 550; padding: 9px 15px; border-radius: 8px;
    border: 1px solid #046A38; background: #046A38; color: #fff; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px; transition: filter .15s;
}
.btn-ed:hover { filter: brightness(1.08); }
.btn-ed.ghost { background: #fff; color: #046A38; }
.btn-ed.warn { background: #fff; color: #b42318; border-color: #f1c5c0; }
.badge-ed { font-size: 11px; font-weight: 650; padding: 3px 9px; border-radius: 999px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

.meta-list { list-style: none; margin: 0; padding: 0; font-size: 12.5px; }
.meta-list li { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px dashed #eef1f5; }
.meta-list li:last-child { border-bottom: 0; }
.meta-list .k { color: #64748b; }
.meta-list .v { font-weight: 600; text-align: right; }
.meta-list a { color: #046A38; }

.flashbar { margin-bottom: 14px; font-size: 13.5px; }
.flashbar .fb { padding: 10px 14px; border-radius: 8px; border: 1px solid transparent; margin-bottom: 8px; }
.flashbar .fb-success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.flashbar .fb-danger, .flashbar .fb-warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }

.toolbar { position: fixed; top: 12px; right: 12px; z-index: 10; font-family: Inter, Arial, sans-serif; font-size: 13px; }
.toolbar a, .toolbar button {
    display: inline-block; border: 1px solid #046A38; background: #046A38; color: #fff; text-decoration: none;
    padding: 7px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; margin-left: 4px;
}
.toolbar .alt { background: #fff; color: #046A38; }
@media print {
    body { background: #fff; }
    .wb { display: block; padding: 0; }
    .wb-view { display: block; }
    /* 1.1 + 1.44 = 2.54cm to the body text; 0.85 + 0.94 = 1.79cm on the right.
       The extra margin is what lets the letterhead overhang without being cut. */
    .sheet { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0 0.94cm 0 1.44cm; }
    .wb-fit { transform: none !important; height: auto !important; }
    .toolbar, .wb-edit, .flashbar { display: none !important; }
}
</style>
</head>
<body>
<?php if (!$isDoc): ?>
<div class="toolbar">
    <button onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>
    <a class="alt" href="<?= e(order_url($table, $id, '&download=doc')) ?>"><i class="bi bi-file-earmark-word"></i> Download Word</a>
    <a class="alt" href="<?= e(url('employees/view.php?id=' . (int)$emp['id'])) ?>">Profile</a>
</div>
<?php endif; ?>

<?php
/* The document itself. Rendered once here and placed by the layout below,
   so the Word download and the preview can never drift apart. */
ob_start(); ?>
<div class="sheet">

    <div class="lh">
        <?php if ($emblem): ?><img class="lh-emblem" src="<?= e($emblemSrc) ?>" alt="Government of the Punjab"><?php endif; ?>
        <div class="lh-text">
            <?php foreach ($lines as $l): if (trim((string)$l) === '') continue; ?>
                <div class="l"><?= e($l) ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($o['is_voided']): ?>
        <div class="voided">THIS RECORD HAS BEEN VOIDED &mdash; NOT A VALID ORDER<?= $o['void_reason'] ? '<br><span style="font-weight:normal;font-size:10pt">' . e($o['void_reason']) . '</span>' : '' ?></div>
    <?php endif; ?>

    <div class="meta">
        <div class="no">No. <?= e($o['order_no']) ?></div>
        <div>Dated: <?= e(fmt_date($o['order_date'])) ?></div>
    </div>

    <h1 class="order-title"><?= e($heading) ?></h1>

    <?php if ($o['subject'] !== ''): ?>
        <div class="subject"><b>Subject:</b> &nbsp;<?= e($o['subject']) ?></div>
    <?php endif; ?>

    <?php if (count($o['paras']) > 1): ?>
        <ol class="paras"><?php foreach ($o['paras'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ol>
    <?php else: ?>
        <?php foreach ($o['paras'] as $p): ?><p class="para"><?= e($p) ?></p><?php endforeach; ?>
    <?php endif; ?>

    <?php if ($o['facts']): ?>
        <table class="facts">
            <?php foreach ($o['facts'] as $k => $v): ?>
                <tr><td class="k"><?= e($k) ?></td><td><?= e($v) ?></td></tr>
            <?php endforeach; ?>
            <tr><td class="k">Employee Code</td><td><?= e($emp['employee_code']) ?> &nbsp;&middot;&nbsp; CNIC <?= e($emp['cnic']) ?></td></tr>
        </table>
    <?php endif; ?>

    <div class="sign">
        <?php if ($signature): ?><img class="sig" src="<?= e($signature) ?>" style="width:<?= $sigWidth ?>px" alt=""><?php endif; ?>
        <?php if ($authName !== ''): ?><div class="nm">(<?= e($authName) ?>)</div>
        <?php elseif (!$signature): ?><div class="nm">Sd/-</div><?php endif; ?>
        <?php if ($authDesig !== ''): ?><div><?= e($authDesig) ?></div><?php endif; ?>
        <?php if ($authOffice !== ''): ?><div><?= e($authOffice) ?></div><?php endif; ?>
    </div>

    <?php if ($copyTo): ?>
    <div class="endorse">
        <div>A copy is forwarded for information and necessary action to:&mdash;</div>
        <ol><?php foreach ($copyTo as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ol>
    </div>
    <?php endif; ?>

</div>
<?php $documentHtml = ob_get_clean(); ?>

<?php if ($isDoc) { echo $documentHtml; } else { ?>
<div class="wb">

    <?php if (can_manage_employee($emp)): $edited = !empty($o['override']); ?>
    <div class="wb-edit">
        <?php
        $fl = flash_render();
        if (trim($fl) !== '') {
            $fl = preg_replace('#<button[^>]*class="btn-close"[^>]*></button>#', '', $fl);
            $fl = str_replace(['alert alert-', 'alert-dismissible fade show py-2'], ['fb fb-', ''], $fl);
            echo '<div class="flashbar">' . $fl . '</div>';
        }
        ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="card-ed">
                <div class="hd">
                    <i class="bi bi-pencil-square"></i>Edit this order
                    <?php if ($edited): ?><span class="sub"><span class="badge-ed">edited</span></span><?php endif; ?>
                </div>
                <div class="bd">
                    <div class="fld">
                        <label for="ed_filing">Filing / Diary Number</label>
                        <input type="text" id="ed_filing" name="filing_no" maxlength="120" value="<?= e($o['order_no']) ?>">
                        <div class="hint">Type the number only &mdash; the word &ldquo;No.&rdquo; is printed for you. Generated: <?= e($o['default_order_no']) ?></div>
                    </div>
                    <div class="fld">
                        <label for="ed_subject">Subject</label>
                        <input type="text" id="ed_subject" name="subject" maxlength="300" value="<?= e($o['subject']) ?>">
                    </div>
                    <div class="fld">
                        <label for="ed_body">Body of the letter</label>
                        <textarea id="ed_body" name="body" rows="10"><?= e(implode("\n", $o['paras'])) ?></textarea>
                        <div class="hint">One paragraph per line. Two or more lines print as a numbered list, a single line as a plain paragraph.</div>
                    </div>
                    <div class="fld">
                        <label for="ed_cc">Copy forwarded to (CC)</label>
                        <textarea id="ed_cc" name="copy_to" rows="6"><?= e(implode("\n", $copyTo)) ?></textarea>
                        <div class="hint">One entry per line. Clear the box to print the order without the endorsement.</div>
                    </div>
                    <div class="acts">
                        <button type="submit" class="btn-ed"><i class="bi bi-check2"></i>Save &amp; preview</button>
                        <a class="btn-ed ghost" href="<?= e(order_url($table, $id)) ?>">Cancel</a>
                        <?php if ($edited): ?>
                            <button type="submit" name="reset_order" value="1" class="btn-ed warn"
                                    onclick="return confirm('Discard your wording and go back to the text generated from the service record?')">Reset to default</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <div class="card-ed">
            <div class="hd"><i class="bi bi-info-circle"></i>This order</div>
            <div class="bd">
                <ul class="meta-list">
                    <li><span class="k">Type</span><span class="v"><?= e($o['kind']) ?></span></li>
                    <li><span class="k">Employee</span><span class="v"><a href="<?= e(url('employees/view.php?id=' . (int)$emp['id'])) ?>"><?= e($emp['name']) ?></a></span></li>
                    <li><span class="k">Designation</span><span class="v"><?= e($emp['designation']) ?><?= $emp['pay_scale'] ? ' (' . e($emp['pay_scale']) . ')' : '' ?></span></li>
                    <li><span class="k">Department</span><span class="v"><?= e($emp['department']) ?></span></li>
                    <li><span class="k">Service reference</span><span class="v"><?= e($o['ref_no'] ?: '—') ?></span></li>
                    <li><span class="k">Order dated</span><span class="v"><?= e(fmt_date($o['order_date'])) ?></span></li>
                    <li><span class="k">Signed by</span><span class="v"><?= e(trim($authName . ($authDesig ? ', ' . $authDesig : '')) ?: 'Sd/-') ?></span></li>
                    <li><span class="k">Signing authority</span><span class="v" style="font-weight:500"><?= e($signer['why'] ?? '') ?></span></li>
                </ul>
                <div class="acts" style="margin-top:14px">
                    <button type="button" class="btn-ed ghost" onclick="window.print()"><i class="bi bi-printer"></i>Print / PDF</button>
                    <a class="btn-ed ghost" href="<?= e(order_url($table, $id, '&download=doc')) ?>"><i class="bi bi-file-earmark-word"></i>Word</a>
                    <a class="btn-ed ghost" href="<?= e(url('employees/view.php?id=' . (int)$emp['id'])) ?>"><i class="bi bi-person"></i>Profile</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="wb-view"><div class="wb-fit"><?= $documentHtml ?></div></div>
</div>

<script>
/* The sheet is a fixed A4 width. Scale it down (never up) so the whole page
   is visible beside the editor on narrower screens, and reserve exactly the
   scaled height so no empty space is left underneath. */
(function () {
    var fit = document.querySelector('.wb-fit');
    var col = document.querySelector('.wb-view');
    if (!fit || !col) return;
    var sheet = fit.querySelector('.sheet');

    function apply() {
        fit.style.transform = '';
        fit.style.height = '';
        var avail = col.clientWidth;
        var natural = sheet.offsetWidth;
        if (!avail || !natural) return;
        var scale = Math.min(1, avail / natural);
        if (scale >= 0.999) return;
        fit.style.transform = 'scale(' + scale + ')';
        fit.style.height = (sheet.offsetHeight * scale) + 'px';
    }
    apply();
    window.addEventListener('resize', apply);
    if (typeof ResizeObserver === 'function') new ResizeObserver(apply).observe(col);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(apply);
})();
</script>
<?php } ?>
</body>
</html>
