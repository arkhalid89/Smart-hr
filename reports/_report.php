<?php
/**
 * Shared renderer for tabular reports.
 *  $cfg = ['key','title','icon','description',
 *          'filters' => function(): void  (echo extra filter inputs),
 *          'query'   => function(): array [sql, params]   (must alias e.* fields used),
 *          'columns' => ['Label' => fn($r) => html],
 *          'export'  => ['headers' => [...], 'row' => fn($r) => [...]],
 *          'summary' => fn(array $rows) => html (optional)]
 */
declare(strict_types=1);

function report_page(array $cfg): void
{
    require_staff();
    [$sql, $params] = ($cfg['query'])();
    $rows = db_all($sql, $params);

    if (get('export')) {
        require_once ROOT_PATH . '/includes/export.php';
        $out = [];
        foreach ($rows as $i => $r) $out[] = array_merge([$i + 1], ($cfg['export']['row'])($r));
        log_activity('EXPORT', 'reports', null, null, ['report' => $cfg['key'], 'rows' => count($out)], $cfg['title'] . ' exported');
        export_table('report_' . $cfg['key'], array_merge(['#'], $cfg['export']['headers']), $out, setting('org_name', ORG_NAME) . ' — ' . $cfg['title'] . ' (' . fmt_date(today()) . ')');
    }

    $g = $_GET;
    $qs = fn(array $o = []) => 'reports/' . $cfg['key'] . '.php?' . http_build_query(array_filter(array_merge($g, $o), fn($v) => $v !== '' && $v !== null));

    $page_title  = $cfg['title'];
    $active_menu = 'reports';
    $breadcrumbs = ['Reports' => 'reports/index.php', $cfg['title'] => null];
    $page_actions = '<div class="btn-group btn-group-sm"><a href="' . e(url($qs(['export' => 1, 'format' => 'xlsx']))) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
        . '<a href="' . e(url($qs(['export' => 1, 'format' => 'csv']))) . '" class="btn btn-outline-success">CSV</a></div>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / PDF</button>';
    require ROOT_PATH . '/includes/header.php';
    ?>
    <form method="get" class="filter-bar mb-3 no-print"><div class="row g-2 align-items-end">
        <div class="col-12"><?= geo_picker(['mode' => 'filter', 'values' => $g, 'size' => 'sm', 'id' => 'rptgeo']) ?></div>
        <?php if (!empty($cfg['filters'])) ($cfg['filters'])(); ?>
        <div class="col-md-2 d-flex gap-1"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button><a href="<?= url('reports/' . $cfg['key'] . '.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a></div>
    </div></form>

    <div class="print-header d-none d-print-block mb-3">
        <h3 class="mb-0"><?= e(setting('org_name', ORG_NAME)) ?></h3>
        <div class="text-muted"><?= e(setting('org_address', '')) ?></div>
        <h4 class="mt-2"><?= e($cfg['title']) ?></h4>
        <div class="small text-muted">Generated <?= fmt_datetime(date('Y-m-d H:i:s')) ?> by <?= e(current_user()['full_name']) ?></div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="card-title"><i class="bi <?= e($cfg['icon']) ?> me-1 text-primary"></i><?= e($cfg['title']) ?> <span class="badge rounded-pill text-bg-light border"><?= count($rows) ?></span></span>
            <span class="small text-muted"><?= e($cfg['description'] ?? '') ?></span>
        </div>
        <?php if (!empty($cfg['summary'])): ?><div class="card-body border-bottom py-2"><?= ($cfg['summary'])($rows) ?></div><?php endif; ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0 report-table">
            <thead><tr><th style="width:40px">#</th><?php foreach ($cfg['columns'] as $l => $fn): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="<?= count($cfg['columns']) + 1 ?>" class="text-center text-muted py-5"><i class="bi bi-clipboard-x display-6 d-block mb-2"></i>No records for the selected criteria.</td></tr><?php endif; ?>
            <?php foreach ($rows as $i => $r): ?><tr><td class="text-muted"><?= $i + 1 ?></td><?php foreach ($cfg['columns'] as $fn): ?><td><?= $fn($r) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            </tbody></table></div>
    </div>
    <?php
    require ROOT_PATH . '/includes/footer.php';
}

/** Common employee-row cell: name + code link. */
function report_emp_cell(array $r): string
{
    return '<a href="' . url('employees/view.php?id=' . $r['id']) . '" class="text-reset text-decoration-none fw-semibold">' . e($r['name']) . '</a><br><span class="small text-muted">' . e($r['employee_code']) . ' · ' . e($r['cnic']) . '</span>';
}
/** Base SELECT/FROM used by most reports (alias e). */
function report_base_from(): string
{
    return 'FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN designations g ON g.id = e.designation_id LEFT JOIN pay_scales ps ON ps.id = e.pay_scale_id LEFT JOIN employment_types et ON et.id = e.employment_type_id';
}
function report_base_select(): string
{
    return 'SELECT e.*, d.name AS department, g.name AS designation, ps.name AS pay_scale, et.name AS employment_type ';
}
/** Department + scope where fragment. */
function report_scope(): array
{
    [$scope, $params] = emp_scope('e');
    $where = ['e.is_deleted = 0', $scope];
    [$gw, $gp] = geo_filter_sql($_GET, 'e');
    if ($gw !== '1=1') { $where[] = $gw; $params = array_merge($params, $gp); }
    return [implode(' AND ', $where), $params];
}
