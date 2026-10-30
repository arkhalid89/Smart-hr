<?php
/**
 * Master Data - one page, one tab per master type. Everything (list, add,
 * edit, activate/deactivate, delete, restore) happens over AJAX.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/masters_config.php';
require_role('super_admin', 'officer');

$types = array_filter(masters_config(), fn($c) => in_array(master_role(), $c['roles'], true));
if (!$types) { require_role('super_admin'); }
$current = (string)get('type', '');
if (!isset($types[$current])) $current = array_key_first($types);

$page_title  = 'Master Data';
$active_menu = 'masters';
$breadcrumbs = ['Master Data' => null];
require ROOT_PATH . '/includes/header.php';
?>
<div class="row g-3">
    <!-- Left: type list -->
    <div class="col-lg-3 col-xl-2">
        <div class="card">
            <div class="list-group list-group-flush master-types" id="masterTypes">
                <?php foreach ($types as $key => $c): ?>
                <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2<?= $key === $current ? ' active' : '' ?>" data-type="<?= e($key) ?>">
                    <i class="bi <?= e($c['icon']) ?>"></i><span class="small"><?= e($c['label']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right: table -->
    <div class="col-lg-9 col-xl-10">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span class="card-title" id="masterTitle"><?= e($types[$current]['label']) ?></span>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?php if (is_super_admin()): ?>
                    <div class="btn-group btn-group-sm" role="group" id="masterView">
                        <input type="radio" class="btn-check" name="mview" id="mv_active" value="active" checked><label class="btn btn-outline-secondary" for="mv_active">Records</label>
                        <input type="radio" class="btn-check" name="mview" id="mv_deleted" value="deleted"><label class="btn btn-outline-secondary" for="mv_deleted"><i class="bi bi-recycle me-1"></i>Recycle Bin</label>
                    </div>
                    <?php endif; ?>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-success" id="btnExportXlsx" href="#"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
                        <a class="btn btn-outline-success" id="btnExportCsv" href="#">CSV</a>
                    </div>
                    <button class="btn btn-primary btn-sm" id="btnAdd"><i class="bi bi-plus-lg me-1"></i>Add <span id="masterSingular"><?= e($types[$current]['singular']) ?></span></button>
                </div>
            </div>
            <div class="px-3 pt-3 pb-1 border-bottom" id="masterGeoBar" style="display:none">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <label class="small text-muted mb-0" for="masterDistrict"><i class="bi bi-funnel me-1"></i>District</label>
                    <select id="masterDistrict" class="form-select form-select-sm" style="max-width:280px">
                        <?php $dv = null; foreach (db_all('SELECT id, name, division FROM districts WHERE is_deleted = 0 ORDER BY division, name') as $dd):
                            if ($dd['division'] !== $dv) { if ($dv !== null) echo '</optgroup>'; $dv = $dd['division']; echo '<optgroup label="' . e($dv . ' Division') . '">'; } ?>
                            <option value="<?= (int)$dd['id'] ?>"><?= e($dd['name']) ?></option>
                        <?php endforeach; if ($dv !== null) echo '</optgroup>'; ?>
                    </select>
                    <span class="small text-muted">Records are listed one district at a time.</span>
                </div>
            </div>
            <div class="card-body p-0" id="masterTable">
                <div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit modal -->
<div class="modal fade" id="masterModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" id="masterForm" novalidate>
      <div class="modal-header">
        <h5 class="modal-title" id="masterModalTitle">Add</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="masterId" value="0">
        <div class="row g-3" id="masterFields"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="masterSave"><i class="bi bi-check2 me-1"></i>Save</button>
      </div>
    </form>
  </div>
</div>

<?php
$typesJson = json_encode(array_map(fn($c) => ['label' => $c['label'], 'singular' => $c['singular'], 'geo' => !empty($c['geo_filter'])], $types));
$extra_js = geo_data_script();
$extra_js .= <<<JS
<script>
(function(){
  const TYPES = $typesJson;
  let type = '{$current}';
  const \$table = $('#masterTable'), \$modal = $('#masterModal'), modal = new bootstrap.Modal('#masterModal');
  const view = () => $('input[name="mview"]:checked').val() || 'active';

  function loadTable() {
    \$table.html('<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>');
    $('#masterGeoBar').toggle(!!TYPES[type].geo);
    const q = { action: 'table', type: type, show: view() };
    if (TYPES[type].geo) q.district_id = $('#masterDistrict').val();
    $.get(HR.url('masters/ajax/master_crud.php'), q)
      .done(html => { \$table.html(html); HR.initWidgets(\$table); })
      .fail(() => \$table.html('<div class="alert alert-danger m-3">Failed to load records.</div>'));
    $('#btnExportXlsx').attr('href', HR.url('masters/export.php?type=' + type + '&format=xlsx'));
    $('#btnExportCsv').attr('href', HR.url('masters/export.php?type=' + type + '&format=csv'));
  }
  function switchType(t) {
    type = t;
    $('#masterTypes a').removeClass('active').filter('[data-type="' + t + '"]').addClass('active');
    $('#masterTitle').text(TYPES[t].label);
    $('#masterSingular').text(TYPES[t].singular);
    history.replaceState(null, '', HR.url('masters/index.php?type=' + t));
    loadTable();
  }
  function openForm(id) {
    $('#masterFields').html('<div class="text-muted">Loading…</div>');
    $('#masterId').val(id || 0);
    $('#masterModalTitle').text((id ? 'Edit ' : 'Add ') + TYPES[type].singular);
    modal.show();
    $.get(HR.url('masters/ajax/master_crud.php'), { action: 'form', type: type, id: id || 0 })
      .done(html => { $('#masterFields').html(html); HR.initGeo('#masterFields'); HR.initPlace('#masterFields'); HR.initWidgets('#masterFields'); $('#masterFields').find('input,select,textarea').first().trigger('focus'); });
  }
  function act(action, id, confirmText) {
    const run = () => HR.post('masters/ajax/master_crud.php', { action: action, type: type, id: id })
      .done(res => { HR.toast(res.message, res.success ? 'success' : 'danger'); if (res.success) loadTable(); });
    if (confirmText) HR.confirm(confirmText).then(ok => ok && run()); else run();
  }

  $('#masterTypes').on('click', 'a', function(e){ e.preventDefault(); switchType(this.dataset.type); });
  $('#masterView').on('change', 'input', loadTable);
  $('#masterDistrict').on('change', loadTable);
  $('#btnAdd').on('click', () => openForm(0));
  \$table.on('click', 'button[data-act]', function(){
    const id = this.dataset.id, a = this.dataset.act;
    if (a === 'edit') return openForm(id);
    if (a === 'toggle') return act('toggle', id);
    if (a === 'delete') return act('delete', id, 'Move this record to the recycle bin? It will disappear from all dropdowns.');
    if (a === 'restore') return act('restore', id);
    if (a === 'hard_delete') return act('hard_delete', id, 'Permanently delete this record? This cannot be undone.');
  });
  $('#masterForm').on('submit', function(e){
    e.preventDefault();
    const \$btn = $('#masterSave').prop('disabled', true);
    HR.post('masters/ajax/master_crud.php', $(this).serialize() + '&action=save&type=' + type)
      .done(res => { HR.toast(res.message, res.success ? 'success' : 'danger'); if (res.success) { modal.hide(); loadTable(); } })
      .always(() => \$btn.prop('disabled', false));
  });
  loadTable();
})();
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
