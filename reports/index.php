<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_staff();

[$scope, $params] = emp_scope('e');
$servingIn = "'" . implode("','", SERVING_STATUSES) . "'";
$cnt = fn(string $extra, array $p = []) => (int)db_value("SELECT COUNT(*) FROM employees e WHERE e.is_deleted = 0 AND $scope $extra", array_merge($params, $p), 0);
$reports = [
    ['roster', 'Departmental Roster', 'bi-people', 'primary', 'Serving employees by department with sanctioned, filled and vacant posts.', $cnt("AND e.status IN ($servingIn)")],
    ['contract_expiry', 'Contract Expiry', 'bi-file-earmark-x', 'danger', 'Contracts expiring within X days.', $cnt("AND e.status IN ($servingIn) AND e.contract_to <= DATE_ADD(CURDATE(), INTERVAL ? DAY)", [(int)setting('contract_expiry_alert_days', 60)])],
    ['retirement', 'Retirement List', 'bi-award', 'warning', 'Employees retiring within X years (EOL-adjusted dates).', $cnt("AND e.status IN ($servingIn) AND e.retirement_date <= DATE_ADD(CURDATE(), INTERVAL 1 YEAR)")],
    ['lpr', 'LPR Eligibility', 'bi-calendar-check', 'info', 'Employees eligible for Leave Preparatory to Retirement.', $cnt("AND e.status IN ('Active','Probation','Suspended','On Deputation','On LPR') AND e.retirement_date <= DATE_ADD(CURDATE(), INTERVAL ? MONTH)", [(int)setting('lpr_months', 6)])],
    ['probation', 'Probation Completion', 'bi-hourglass-split', 'info', 'Unconfirmed employees completing probation.', $cnt("AND e.confirmation_date IS NULL AND e.status IN ('Probation','Active') AND e.probation_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")],
    ['deputation', 'Deputation Report', 'bi-box-arrow-up-right', 'purple', 'Employees on deputation with expected reversion dates.', $cnt("AND e.status = 'On Deputation'")],
    ['suspension', 'Suspension Report', 'bi-slash-circle', 'danger', 'Currently suspended employees and reinstatement status.', $cnt("AND e.status = 'Suspended'")],
    ['break_in_service', 'Break in Service', 'bi-exclamation-triangle', 'warning', 'EOL > threshold affecting pension and retirement date.', $cnt('AND e.has_break_in_service = 1')],
    ['death_in_service', 'Death in Service', 'bi-heart', 'dark', 'Deceased employees and nominee / pension claim status.', $cnt("AND e.status = 'Deceased (In Service)'")],
    ['area_wise', 'Area-wise Employees', 'bi-geo-alt', 'primary', 'Who is posted at each office / area, with tenure at the post.',
        (int)db_value("SELECT COUNT(DISTINCT l.id) FROM posting_locations l WHERE l.is_deleted = 0 AND l.is_active = 1", [], 0)],
    ['inquiries', 'Inquiry Report', 'bi-search', 'warning', 'Employees with pending or concluded disciplinary inquiries.',
        (int)db_value("SELECT COUNT(*) FROM inquiries iq JOIN employees e ON e.id = iq.employee_id WHERE e.is_deleted = 0 AND iq.is_voided = 0 AND iq.status <> 'Concluded' AND $scope", $params, 0)],
];
$page_title  = 'Reports';
$active_menu = 'reports';
$breadcrumbs = ['Reports' => null];
require ROOT_PATH . '/includes/header.php';
?>
<div class="row g-3">
    <?php foreach ($reports as [$key, $title, $icon, $color, $desc, $n]): ?>
    <div class="col-md-6 col-xl-4">
        <a href="<?= url("reports/$key.php") ?>" class="card report-card h-100 text-reset text-decoration-none">
            <div class="card-body d-flex gap-3">
                <div class="stat-icon bg-soft-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start"><div class="fw-semibold"><?= e($title) ?></div><span class="badge rounded-pill text-bg-light border"><?= $n ?></span></div>
                    <div class="small text-muted mt-1"><?= e($desc) ?></div>
                </div>
            </div>
            <div class="card-footer bg-transparent small text-primary py-2"><i class="bi bi-file-earmark-excel me-1"></i>Excel · CSV · Print/PDF</div>
        </a>
    </div>
    <?php endforeach; ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body d-flex gap-3">
                <div class="stat-icon bg-soft-success"><i class="bi bi-person-vcard"></i></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold">Employee Profile &amp; Service Book</div>
                    <div class="small text-muted mt-1 mb-2">Printable comprehensive profile and complete chronological service book for any employee.</div>
                    <select id="rptEmployee" class="form-select form-select-sm" data-placeholder="Search employee…"></select>
                    <div class="d-flex gap-2 mt-2">
                        <a href="#" id="btnProfile" class="btn btn-sm btn-outline-primary disabled"><i class="bi bi-printer me-1"></i>Profile</a>
                        <a href="#" id="btnBook" class="btn btn-sm btn-outline-primary disabled"><i class="bi bi-journal-text me-1"></i>Service Book</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$extra_js = <<<JS
<script>
$(function(){
  const \$s = $('#rptEmployee');
  \$s.select2({ theme: 'bootstrap-5', width: '100%', placeholder: \$s.data('placeholder'), minimumInputLength: 2,
    ajax: { url: HR.url('employees/ajax/select.php'), dataType: 'json', delay: 250, data: p => ({ q: p.term }), processResults: d => d } });
  \$s.on('select2:select', function(e){ const id = e.params.data.id;
    $('#btnProfile').removeClass('disabled').attr('href', HR.url('reports/profile_print.php?id=' + id)).attr('target', '_blank');
    $('#btnBook').removeClass('disabled').attr('href', HR.url('reports/service_book.php?id=' + id)).attr('target', '_blank'); });
});
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
