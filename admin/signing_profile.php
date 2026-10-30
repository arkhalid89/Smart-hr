<?php
/**
 * My Office & Signature — every officer maintains their own signing profile:
 * letterhead, the details printed under their signature, their default CC
 * list, the order heading and their scanned signature.
 *
 * The Super Admin can open any officer's profile with ?user=<id> to help set
 * it up; nobody else can edit someone else's.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/signing.php';
require_role('super_admin', 'officer');

$targetId = is_super_admin() ? get_int('user', 0) : (int)user_id();
if (is_super_admin() && !$targetId) {
    /* the Super Admin picks whose profile to open */
    $officers = db_all('SELECT u.id, u.full_name, dp.name AS department, dp.level, t.name AS tehsil, ds.name AS district
                          FROM users u JOIN roles r ON r.id = u.role_id AND r.name = "officer"
                          LEFT JOIN departments dp ON dp.id = u.department_id
                          LEFT JOIN tehsils t ON t.id = dp.tehsil_id LEFT JOIN districts ds ON ds.id = dp.district_id
                         WHERE u.is_deleted = 0 ORDER BY ds.name, FIELD(dp.level, "district", "tehsil", "department"), u.full_name');
    $page_title  = 'Signing Authorities';
    $active_menu = 'signing';
    $breadcrumbs = ['Administration' => null, 'Signing Authorities' => null];
    require ROOT_PATH . '/includes/header.php';
    ?>
    <div class="card"><div class="card-header"><span class="card-title">Officers</span>
        <span class="small text-muted">Each officer normally maintains their own profile</span></div>
    <div class="list-group list-group-flush">
    <?php foreach ($officers as $o): ?>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" href="<?= url('admin/signing_profile.php?user=' . (int)$o['id']) ?>">
            <i class="bi bi-pen fs-5 text-primary"></i>
            <div class="flex-grow-1"><div class="fw-semibold"><?= e($o['full_name']) ?></div>
                <div class="small text-muted"><?= $o['department'] ? e($o['department']) . ' &middot; ' . e(level_label($o['level'])) : 'No department assigned' ?></div></div>
            <?php $has = (int)db_value('SELECT COUNT(*) FROM signing_profiles WHERE user_id = ? AND signature_file IS NOT NULL AND signature_file <> ""', [(int)$o['id']], 0); ?>
            <span class="badge <?= $has ? 'bg-success-subtle text-success-emphasis border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' ?>"><?= $has ? 'Signature on file' : 'No signature yet' ?></span>
        </a>
    <?php endforeach; ?>
    <?php if (!$officers): ?><div class="empty-state p-4">No officer accounts yet. Create them in Users &amp; Roles.</div><?php endif; ?>
    </div></div>
    <?php
    require ROOT_PATH . '/includes/footer.php';
    exit;
}

$profile = signing_profile($targetId);
if (!$profile) {
    flash('danger', 'Signing profiles belong to officers with a department.');
    redirect(is_super_admin() ? 'admin/signing_profile.php' : 'dashboard/index.php');
}
$owner  = $profile['user'];
$isSelf = $targetId === (int)user_id();
$self   = 'admin/signing_profile.php' . (is_super_admin() && !$isSelf ? '?user=' . $targetId : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $errors = [];
    if (post('remove_signature')) {
        signing_remove_signature($targetId);
    } elseif (!empty($_FILES['signature_file']['name'])) {
        if ($err = signing_store_signature($targetId, $_FILES['signature_file'])) $errors[] = $err;
    }
    if (trim((string)post('authority_designation')) === '') $errors[] = 'The designation printed under the signature is required.';
    if ($errors) {
        flash('danger', implode(' ', $errors));
    } else {
        signing_profile_save($targetId, $_POST);
        log_activity('UPDATE', 'signing_profiles', $targetId, null, ['letterhead_2' => post('letterhead_2'), 'designation' => post('authority_designation')],
            'Signing profile updated for ' . $owner['full_name']);
        flash('success', 'Your office details and signature are saved. Orders you issue will print with them.');
    }
    redirect($self);
}

$sigSrc = signing_signature_src($profile['signature_file']);
$issued = (int)db_value('SELECT
        (SELECT COUNT(*) FROM postings WHERE created_by = ?) + (SELECT COUNT(*) FROM promotions WHERE created_by = ?) +
        (SELECT COUNT(*) FROM increments WHERE created_by = ?) + (SELECT COUNT(*) FROM leaves WHERE created_by = ?) +
        (SELECT COUNT(*) FROM awards WHERE created_by = ?) + (SELECT COUNT(*) FROM punishments WHERE created_by = ?) +
        (SELECT COUNT(*) FROM inquiries WHERE created_by = ?)', array_fill(0, 7, $targetId), 0);

$page_title  = $isSelf ? 'My Office & Signature' : 'Signing Profile — ' . $owner['full_name'];
$ownerDept = db_row('SELECT name, level FROM departments WHERE id = ?', [(int)$owner['department_id']]);
$page_subtitle = ($ownerDept ? $ownerDept['name'] . ' · ' . level_label($ownerDept['level']) : '');
$active_menu = 'signing';
$breadcrumbs = $isSelf ? ['My Office & Signature' => null] : ['Signing Authorities' => 'admin/signing_profile.php', $owner['full_name'] => null];
$emblem = asset('img/punjab-emblem.jpg');
require ROOT_PATH . '/includes/header.php';
?>
<form method="post" enctype="multipart/form-data" novalidate id="sigForm">
<?= csrf_field() ?>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card mb-3">
            <div class="card-header"><span class="card-title"><i class="bi bi-building me-1 text-primary"></i>Letterhead</span>
                <span class="small text-muted">Printed beside the Government of the Punjab emblem</span></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-4"><label class="form-label">Line 1</label><input type="text" name="letterhead_1" class="form-control lp" maxlength="120" value="<?= e($profile['letterhead_1']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Line 2</label><input type="text" name="letterhead_2" class="form-control lp" maxlength="120" value="<?= e($profile['letterhead_2']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Line 3</label><input type="text" name="letterhead_3" class="form-control lp" maxlength="120" value="<?= e($profile['letterhead_3']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Order heading</label><input type="text" name="order_prefix" class="form-control lp" maxlength="40" value="<?= e($profile['order_prefix']) ?>"><div class="form-text">e.g. ORDER or OFFICE ORDER</div></div>
                <div class="col-md-8 d-flex align-items-end"><div class="form-check form-switch mb-2">
                    <input class="form-check-input lp" type="checkbox" name="show_emblem" id="sEmblem" value="1" <?= (int)$profile['show_emblem'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="sEmblem">Print the Government of the Punjab emblem</label></div></div>
            </div></div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><span class="card-title"><i class="bi bi-pen me-1 text-primary"></i>Signature</span>
                <span class="small text-muted">Printed on every order you issue</span></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-12">
                    <?php if ($sigSrc): ?>
                    <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded" style="background:var(--hr-surface-2)">
                        <img src="<?= e($sigSrc) ?>" alt="Current signature" style="max-height:56px;max-width:220px;object-fit:contain">
                        <div class="small"><div class="fw-semibold">Signature on file</div><div class="text-muted">Upload a new one to replace it</div></div>
                        <div class="form-check ms-auto"><input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="rmSig"><label class="form-check-label small" for="rmSig">Remove</label></div>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="signature_file" id="sigFile" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp">
                    <div class="form-text">A scan on white or, better, a PNG with a transparent background. Max 2 MB.</div>
                </div>
                <div class="col-md-4"><label class="form-label">Printed width (px)</label><input type="number" name="signature_width" class="form-control lp" min="60" max="400" value="<?= (int)$profile['signature_width'] ?>"></div>
                <div class="col-md-8"><label class="form-label">Name under the signature</label><input type="text" name="authority_name" class="form-control lp" maxlength="120" value="<?= e($profile['authority_name']) ?>"><div class="form-text">Printed in brackets. Leave blank to print only the designation.</div></div>
                <div class="col-md-6"><label class="form-label">Designation <span class="req">*</span></label><input type="text" name="authority_designation" class="form-control lp" maxlength="120" value="<?= e($profile['authority_designation']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Office / station</label><input type="text" name="authority_office" class="form-control lp" maxlength="160" value="<?= e($profile['authority_office']) ?>"></div>
            </div></div>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title"><i class="bi bi-envelope-paper me-1 text-primary"></i>Distribution &amp; closing</span></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-7"><label class="form-label">Copy forwarded to (default CC list)</label>
                    <textarea name="copy_to" class="form-control" rows="6"><?= e($profile['copy_to']) ?></textarea>
                    <div class="form-text">One entry per line. It pre-fills every order you issue and can still be changed on a single order.</div></div>
                <div class="col-md-5"><label class="form-label">Closing sentence (optional)</label>
                    <textarea name="footer_note" class="form-control" rows="6" maxlength="300"><?= e($profile['footer_note']) ?></textarea>
                    <div class="form-text">Appended as the last paragraph of each order, e.g. &ldquo;This order is issued with the approval of the competent authority.&rdquo;</div></div>
            </div></div>
        </div>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save</button>
            <a href="<?= url(is_super_admin() && !$isSelf ? 'admin/signing_profile.php' : 'dashboard/index.php') ?>" class="btn btn-light">Cancel</a>
        </div>
    </div>

    <!-- ===== live preview of how the top and foot of an order will look ===== -->
    <div class="col-xl-5">
        <div class="card sig-preview-card" style="position:sticky;top:84px">
            <div class="card-header"><span class="card-title"><i class="bi bi-eye me-1 text-primary"></i>Preview</span>
                <span class="small text-muted"><?= $issued ?> service record<?= $issued === 1 ? '' : 's' ?> issued by <?= $isSelf ? 'you' : 'this officer' ?></span></div>
            <div class="card-body" style="background:#eef1f5">
                <div class="sig-paper">
                    <div class="sp-lh">
                        <img class="sp-emblem" id="pvEmblem" src="<?= e($emblem) ?>" alt="">
                        <div class="sp-lines"><div id="pv1"></div><div id="pv2"></div><div id="pv3"></div></div>
                    </div>
                    <div class="sp-meta"><span><b>No.</b> &hellip;</span><span>Dated: &hellip;</span></div>
                    <div class="sp-heading" id="pvHeading"></div>
                    <div class="sp-body"><div class="sp-bar"></div><div class="sp-bar"></div><div class="sp-bar short"></div></div>
                    <div class="sp-sign">
                        <img id="pvSig" src="<?= e($sigSrc) ?>" alt="" style="<?= $sigSrc ? '' : 'display:none' ?>">
                        <div class="nm" id="pvName"></div><div id="pvDesig"></div><div id="pvOffice"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<style>
.sig-paper { background:#fff; box-shadow:0 4px 18px rgba(15,32,22,.12); padding:18px 18px 22px 30px; font-family:'Goudy Old Style','Sorts Mill Goudy',Garamond,Georgia,serif; color:#000; min-height:360px; }
.sp-lh { position:relative; min-height:78px; margin-bottom:10px; }
.sp-emblem { position:absolute; left:-14px; top:0; width:74px; height:66px; object-fit:contain; }
.sp-lines { position:absolute; left:40%; right:-6px; top:8px; text-align:center; font-weight:bold; font-size:12.5px; line-height:1; white-space:nowrap; }
.sp-lines div { margin-bottom:2px; }
.sp-meta { display:flex; justify-content:space-between; font-size:11.5px; margin-bottom:8px; }
.sp-heading { font-weight:bold; letter-spacing:1.5px; text-decoration:underline; font-size:13.5px; margin:6px 0 10px; }
.sp-bar { height:7px; border-radius:4px; background:#e5e7eb; margin-bottom:7px; }
.sp-bar.short { width:62%; }
.sp-sign { width:52%; margin-left:auto; margin-top:22px; text-align:center; font-size:12px; line-height:1.35; }
.sp-sign img { display:block; margin:0 auto 2px; max-height:52px; max-width:100%; object-fit:contain; }
.sp-sign .nm { font-weight:bold; }
</style>
<script>
(function () {
    var f = document.getElementById('sigForm');
    function val(n) { var el = f.elements[n]; return el ? el.value : ''; }
    function render() {
        document.getElementById('pv1').textContent = val('letterhead_1');
        document.getElementById('pv2').textContent = val('letterhead_2');
        document.getElementById('pv3').textContent = val('letterhead_3');
        document.getElementById('pvHeading').textContent = val('order_prefix') || 'ORDER';
        var name = val('authority_name').trim();
        document.getElementById('pvName').textContent = name ? '(' + name + ')' : '';
        document.getElementById('pvDesig').textContent = val('authority_designation');
        document.getElementById('pvOffice').textContent = val('authority_office');
        document.getElementById('pvEmblem').style.visibility = f.elements['show_emblem'].checked ? 'visible' : 'hidden';
        var w = parseInt(val('signature_width'), 10) || 150;
        document.getElementById('pvSig').style.width = Math.round(Math.min(400, Math.max(60, w)) * 0.55) + 'px';
    }
    f.addEventListener('input', render);
    f.addEventListener('change', render);
    /* show a newly chosen signature straight away */
    document.getElementById('sigFile').addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        var r = new FileReader();
        r.onload = function (e) { var img = document.getElementById('pvSig'); img.src = e.target.result; img.style.display = ''; };
        r.readAsDataURL(file);
    });
    var rm = document.getElementById('rmSig');
    if (rm) rm.addEventListener('change', function () { document.getElementById('pvSig').style.display = this.checked ? 'none' : ''; });
    render();
})();
</script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
