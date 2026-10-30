<?php
/** Employees with inquiry proceedings — pending by default. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$mode = get('mode', 'open');
report_page([
    'key' => 'inquiries', 'title' => 'Inquiry Report', 'icon' => 'bi-search',
    'description' => 'Employees against whom an inquiry is pending or has been concluded.',
    'filters' => function () use ($mode) {
        echo '<div class="col-md-2"><label class="form-label mb-1">Show</label><select name="mode" class="form-select form-select-sm">'
           . '<option value="open" ' . ($mode === 'open' ? 'selected' : '') . '>Pending inquiries</option>'
           . '<option value="concluded" ' . ($mode === 'concluded' ? 'selected' : '') . '>Concluded inquiries</option>'
           . '<option value="all" ' . ($mode === 'all' ? 'selected' : '') . '>All inquiries</option></select></div>';
    },
    'query' => function () use ($mode) {
        [$w, $p] = report_scope();
        $st = match ($mode) {
            'open'      => ' AND iq.status <> "Concluded"',
            'concluded' => ' AND iq.status = "Concluded"',
            default     => '',
        };
        return [report_base_select() . ', iq.id AS inq_id, iq.ref_no AS inq_ref, iq.subject, iq.allegation, iq.initiated_date, iq.order_no AS inq_order,
                iq.inquiry_officer, iq.ordering_authority, iq.status AS inq_status, iq.outcome, iq.concluded_date, iq.closing_order_no, iq.findings, it.name AS inquiry_type, it.is_formal '
            . report_base_from()
            . " JOIN inquiries iq ON iq.employee_id = e.id AND iq.is_voided = 0
                JOIN inquiry_types it ON it.id = iq.inquiry_type_id
                WHERE $w $st ORDER BY (iq.status <> 'Concluded') DESC, iq.initiated_date DESC", $p];
    },
    'summary' => function (array $rows) {
        $open = array_filter($rows, fn($r) => $r['inq_status'] !== 'Concluded');
        if (!$open) return '';
        $emps = count(array_unique(array_column($open, 'id')));
        return '<div class="alert alert-warning py-2 mb-3"><i class="bi bi-exclamation-triangle me-1"></i><strong>'
            . count($open) . ' inquiry proceeding' . (count($open) === 1 ? '' : 's') . '</strong> pending against '
            . $emps . ' employee' . ($emps === 1 ? '' : 's') . '.</div>';
    },
    'columns' => [
        'Employee' => fn($r) => report_emp_cell($r),
        'Designation' => fn($r) => e($r['designation']),
        'Department' => fn($r) => e($r['department']),
        'Inquiry' => fn($r) => '<a href="' . url('employees/view.php?id=' . (int)$r['id'] . '#tab-inquiries') . '" class="ref-no">' . e($r['inq_ref']) . '</a>'
            . '<br><span class="fw-semibold">' . e($r['subject']) . '</span>'
            . '<br><span class="small text-muted">' . e($r['inquiry_type']) . (!empty($r['is_formal']) ? ' · formal' : '') . '</span>',
        'Initiated' => fn($r) => fmt_date($r['initiated_date']) . ($r['inq_order'] ? '<br><span class="small text-muted">Order ' . e($r['inq_order']) . '</span>' : ''),
        'Inquiry Officer' => fn($r) => e($r['inquiry_officer'] ?: '-'),
        'Status' => fn($r) => $r['inq_status'] === 'Concluded'
            ? '<span class="badge bg-secondary-subtle text-body-secondary border">Concluded</span><br><span class="small">' . e($r['outcome'] ?: '-') . '</span>'
            : '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">' . e($r['inq_status']) . '</span>',
        'Days' => fn($r) => number_format(days_between($r['initiated_date'], $r['concluded_date'] ?: today())),
    ],
    'export' => [
        'headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Department', 'Ref No', 'Inquiry Type', 'Subject', 'Allegation', 'Initiated', 'Order No', 'Inquiry Officer', 'Ordering Authority', 'Status', 'Outcome', 'Concluded', 'Closing Order No', 'Findings', 'Days'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['department'], $r['inq_ref'], $r['inquiry_type'],
            $r['subject'], $r['allegation'], $r['initiated_date'], $r['inq_order'], $r['inquiry_officer'], $r['ordering_authority'],
            $r['inq_status'], $r['outcome'], $r['concluded_date'], $r['closing_order_no'], $r['findings'],
            days_between($r['initiated_date'], $r['concluded_date'] ?: today())],
    ],
]);
