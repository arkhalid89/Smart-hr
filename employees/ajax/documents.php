<?php
/**
 * Employee documents AJAX: list (HTML), upload, delete.
 * Files are stored outside public reach: uploads/documents/{employee_id}/
 * and served via employees/download.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$action = (string)($_REQUEST['action'] ?? '');
if ($action !== 'list') require_editor();

if ($action === 'list') {
    $empId = get_int('employee_id', 0);
    employee_load_or_fail($empId, true);
    $rows = db_all('SELECT d.*, u.full_name AS uploader FROM employee_documents d LEFT JOIN users u ON u.id = d.uploaded_by
                    WHERE d.employee_id = ? AND d.is_deleted = 0 AND ' . employee_self_document_filter('d') . ' ORDER BY d.id DESC', [$empId]);
    header('Content-Type: text/html; charset=utf-8');
    if (!$rows) { echo '<div class="empty-state py-4"><i class="bi bi-folder2-open"></i>No documents uploaded yet.</div>'; exit; }
    if (get('view') === 'cards') {
        $ico = function (string $mime, string $name): array {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (str_starts_with($mime, 'image/')) return ['bi-file-earmark-image', 'bg-soft-info'];
            if ($ext === 'pdf') return ['bi-file-earmark-pdf', 'bg-soft-danger'];
            if (in_array($ext, ['doc', 'docx'])) return ['bi-file-earmark-word', 'bg-soft-primary'];
            if (in_array($ext, ['xls', 'xlsx'])) return ['bi-file-earmark-excel', 'bg-soft-success'];
            return ['bi-file-earmark-text', 'bg-soft-secondary'];
        };
        echo '<div class="doc-grid">';
        foreach ($rows as $r) {
            [$i, $c] = $ico((string)$r['mime_type'], (string)$r['original_name']);
            $inline = in_array($r['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
            echo '<div class="doc-card"><div class="doc-ico ' . $c . '"><i class="bi ' . $i . '"></i></div><div class="min-w-0 flex-grow-1">'
               . '<div class="doc-title text-truncate" title="' . e($r['title']) . '">' . e($r['title']) . '</div>'
               . '<div class="doc-meta"><span class="badge text-bg-light border">' . e($r['doc_type']) . '</span>' . ($r['related_table'] ? ' · <span class="ref-no">' . e(svc_ref_of($r['related_table'], (int)$r['related_id'])) . '</span>' : '') . '</div>'
               . '<div class="doc-meta">' . ($r['file_size'] ? number_format($r['file_size'] / 1024) . ' KB · ' : '') . fmt_date($r['created_at']) . ' · ' . e($r['uploader']) . '</div>'
               . '<div class="doc-actions">'
               . ($inline ? '<a class="btn btn-ghost btn-sm" href="' . url('employees/download.php?id=' . $r['id'] . '&inline=1') . '" target="_blank" title="View"><i class="bi bi-eye"></i></a>' : '')
               . '<a class="btn btn-ghost btn-sm" href="' . url('employees/download.php?id=' . $r['id']) . '" title="Download"><i class="bi bi-download"></i></a>'
               . (can_edit() ? '<button class="btn btn-ghost btn-sm text-danger" data-doc-act="delete" data-id="' . $r['id'] . '" title="Delete"><i class="bi bi-trash"></i></button>' : '')
               . '</div></div></div>';
        }
        echo '</div>';
        exit;
    }
    ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Type</th><th>Title</th><th>File</th><th>Size</th><th>Linked To</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="badge text-bg-light border"><?= e($r['doc_type']) ?></span></td>
                <td class="fw-semibold"><?= e($r['title']) ?></td>
                <td class="small text-muted"><?= e($r['original_name']) ?></td>
                <td class="small"><?= $r['file_size'] ? number_format($r['file_size'] / 1024, 0) . ' KB' : '-' ?></td>
                <td class="small"><?= $r['related_table'] ? '<span class="ref-no">' . e(svc_ref_of($r['related_table'], (int)$r['related_id'])) . '</span>' : '-' ?></td>
                <td class="small"><?= fmt_date($r['created_at']) ?><br><span class="text-muted"><?= e($r['uploader']) ?></span></td>
                <td class="text-end table-actions">
                    <a class="btn btn-ghost btn-sm" href="<?= url("employees/download.php?id=" . $r["id"] . "&inline=1") ?>" target="_blank" title="View"><i class="bi bi-eye"></i></a>
                    <a class="btn btn-ghost btn-sm" href="<?= url("employees/download.php?id=" . $r["id"]) ?>" title="Download"><i class="bi bi-download"></i></a>
                    <button class="btn btn-ghost btn-sm text-danger" data-doc-act="delete" data-id="<?= $r['id'] ?>" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Invalid request.', 405);
csrf_verify();

if ($action === 'upload') {
    $empId = post_int('employee_id', 0);
    $emp   = employee_load_or_fail($empId, true, true);
    $docId = document_store($empId, 'file', (string)post('doc_type', 'Other'), (string)post('title', ''), nullable(post('related_table')), post_int('related_id'));
    json_ok('Document uploaded.', ['id' => $docId]);
}

if ($action === 'delete') {
    $id  = post_int('id', 0);
    $row = $id ? db_find('employee_documents', $id) : null;
    if (!$row) json_error('Document not found.', 404);
    $emp = employee_load_or_fail((int)$row['employee_id'], true, true);
    db_update('employee_documents', ['is_deleted' => 1], 'id = ?', [$id]);
    log_activity('DELETE', 'employee_documents', $id, $row, null, 'Document removed: ' . $row['title'] . ' (' . $emp['name'] . ')', (int)$row['employee_id']);
    json_ok('Document removed.');
}
json_error('Unknown action.');
