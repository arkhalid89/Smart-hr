<?php
/**
 * Official order generation.
 *
 * Every posting / transfer, promotion, suspension, leave sanction, award,
 * deputation, reinstatement, exit, rejoining and inquiry produces an order
 * that can be printed or downloaded at any time from the record it belongs
 * to. Orders are rendered from the record itself — nothing is duplicated —
 * so an order downloaded a year later still matches the service record.
 *
 * reports/order.php?table=postings&id=12          -> printable order
 * reports/order.php?table=postings&id=12&download=doc -> Word download
 */
declare(strict_types=1);
require_once __DIR__ . '/signing.php';

/** Record types that produce an order. */
const ORDER_TABLES = [
    'postings'       => 'Posting / Transfer Order',
    'promotions'     => 'Promotion Order',
    'increments'     => 'Increment Order',
    'leaves'         => 'Leave Sanction Order',
    'awards'         => 'Commendation / Award Order',
    'punishments'    => 'Punishment Order',
    'reinstatements' => 'Reinstatement Order',
    'deputations'    => 'Deputation Order',
    'exits'          => 'Retirement / Separation Order',
    'rejoinings'     => 'Rejoining Order',
    'inquiries'      => 'Inquiry Order',
];

/** The column holding the officer's own order number — leaves use letter_no. */
function order_number_column(string $table): string
{
    return $table === 'leaves' ? 'letter_no' : 'order_no';
}

function order_supported(?string $table): bool
{
    return $table !== null && isset(ORDER_TABLES[$table]);
}

/** Mr. / Ms. by recorded gender. */
function order_salutation(array $emp): string
{
    return ($emp['gender'] ?? '') === 'Female' ? 'Ms.' : 'Mr.';
}

/** "he/she" and "his/her" for the order body. */
function order_pronoun(array $emp, string $which = 'subject'): string
{
    $female = ($emp['gender'] ?? '') === 'Female';
    return match ($which) {
        'object'     => $female ? 'her' : 'him',
        'possessive' => $female ? 'her' : 'his',
        default      => $female ? 'she' : 'he',
    };
}

/** Full designation line: "Mr. Ali Raza, Senior Clerk (BS-14), Revenue Branch". */
function order_person_line(array $emp): string
{
    return trim(order_salutation($emp) . ' ' . $emp['name'] . ', ' . $emp['designation']
        . ($emp['pay_scale'] ? ' (' . $emp['pay_scale'] . ')' : '') . ', ' . $emp['department']);
}

/**
 * Load a record plus its employee and build the order.
 * Returns null when the record does not exist or is voided.
 */
function order_build(string $table, int $id): ?array
{
    if (!order_supported($table)) return null;
    $row = db_row('SELECT * FROM `' . db_ident($table) . '` WHERE id = ?', [$id]);
    if (!$row) return null;
    $emp = employee_get((int)$row['employee_id']);
    if (!$emp) return null;

    $ref     = (string)($row['ref_no'] ?? '');
    $orderNo = trim((string)($row['order_no'] ?? '')) ?: $ref;
    $person  = order_person_line($emp);
    $he      = order_pronoun($emp);
    $his     = order_pronoun($emp, 'possessive');

    $o = [
        'table' => $table, 'id' => $id, 'row' => $row, 'employee' => $emp,
        'kind' => ORDER_TABLES[$table],
        'ref_no' => $ref,
        'order_no' => $orderNo,
        'order_date' => $row['order_date'] ?? $row['created_at'] ?? today(),
        'is_voided' => !empty($row['is_voided']),
        'void_reason' => $row['void_reason'] ?? null,
        'subject' => '',
        'paras' => [],
        'facts' => [],           // label => value pairs printed as a small table
    ];

    switch ($table) {
        case 'postings':
            $loc  = $row['location_id'] ? master_name('posting_locations', (int)$row['location_id']) : ($row['office'] ?: '');
            $dept = master_name('departments', (int)$row['department_id']);
            $type = (string)$row['posting_type'];
            $isTransfer = str_contains($type, 'Transfer');
            $o['kind']    = $isTransfer ? 'Transfer / Posting Order' : (str_contains($type, 'Deputation') ? 'Deputation Posting Order' : 'Posting Order');
            $o['subject'] = strtoupper($isTransfer ? 'Transfer and Posting' : 'Posting') . ' — ' . strtoupper($emp['name']) . ', ' . strtoupper($emp['designation']);
            $o['order_date'] = $row['order_date'] ?: $row['effective_date'];
            $o['paras'][] = $person . ', is hereby ' . ($isTransfer ? 'transferred and posted' : 'posted')
                . ' as ' . $emp['designation'] . ($loc ? ' at ' . $loc : '')
                . ($dept ? ', ' . $dept : '') . ($row['section'] ? ' (' . $row['section'] . ')' : '')
                . ' with immediate effect from ' . fmt_date($row['effective_date']) . '.';
            if (!empty($row['reporting_officer'])) {
                $o['paras'][] = ucfirst($he) . ' shall report to ' . $row['reporting_officer'] . ' and shall perform such duties as may be assigned to ' . order_pronoun($emp, 'object') . ' from time to time.';
            }
            $o['paras'][] = ucfirst($he) . ' shall relinquish and assume charge accordingly and submit the charge report to this office.';
            $o['facts'] = array_filter([
                'Posting Type'      => $type,
                'Office / Location' => $loc,
                'Department'        => $dept,
                'Section'           => $row['section'] ?? '',
                'Effective From'    => fmt_date($row['effective_date']),
                'Reporting Officer' => $row['reporting_officer'] ?? '',
            ]);
            break;

        case 'promotions':
            $newDesig = $row['new_designation_id'] ? master_name('designations', (int)$row['new_designation_id']) : $emp['designation'];
            $newScale = $row['new_scale_id'] ? master_name('pay_scales', (int)$row['new_scale_id']) : '';
            $oldScale = $row['previous_scale_id'] ? master_name('pay_scales', (int)$row['previous_scale_id']) : '';
            $o['subject'] = strtoupper((string)$row['promotion_type']) . ' — ' . strtoupper($emp['name']);
            $o['order_date'] = $row['order_date'] ?: $row['effective_date'];
            $o['paras'][] = $person . ', is hereby promoted to the post of ' . $newDesig
                . ($newScale ? ' in ' . $newScale : '') . ($oldScale && $newScale ? ' from ' . $oldScale : '')
                . ' with effect from ' . fmt_date($row['effective_date']) . ' on '
                . (($row['promotion_type'] ?? '') === 'Acting Charge' ? 'acting charge basis' : 'regular basis') . '.';
            $o['paras'][] = 'The pay of the official shall be fixed under the relevant rules by the District Accounts Officer.';
            $o['facts'] = array_filter([
                'Nature'          => $row['promotion_type'] ?? '',
                'Promoted To'     => $newDesig,
                'Pay Scale'       => trim(($oldScale ? $oldScale . ' → ' : '') . $newScale),
                'Effective From'  => fmt_date($row['effective_date']),
                'Ordering Authority' => $row['order_by_authority'] ?? '',
            ]);
            break;

        case 'increments':
            $o['kind'] = 'Annual Increment Order';
            $o['subject'] = 'GRANT OF ANNUAL INCREMENT — ' . strtoupper($emp['name']);
            $o['order_date'] = $row['order_date'] ?: $row['effective_date'];
            $o['paras'][] = 'The annual increment of ' . $person . ', is hereby granted with effect from '
                . fmt_date($row['effective_date']) . '. The basic pay is accordingly raised from Rs. '
                . fmt_money($row['previous_salary']) . ' to Rs. ' . fmt_money($row['new_salary']) . ' per month.';
            if (!empty($row['is_back_benefit'])) {
                $o['paras'][] = 'This increment is allowed as a back benefit consequent upon reinstatement in service.';
            }
            $o['facts'] = array_filter([
                'Effective From' => fmt_date($row['effective_date']),
                'Previous Basic' => 'Rs. ' . fmt_money($row['previous_salary']),
                'Revised Basic'  => 'Rs. ' . fmt_money($row['new_salary']),
                'Nature'         => !empty($row['is_back_benefit']) ? 'Back benefit' : 'Annual increment',
            ]);
            break;

        case 'leaves':
            $type = master_name('leave_types', (int)$row['leave_type_id']);
            $o['subject'] = 'SANCTION OF ' . strtoupper($type) . ' — ' . strtoupper($emp['name']);
            $o['order_date'] = $row['order_date'] ?? $row['created_at'];
            $o['order_no'] = trim((string)($row['letter_no'] ?? '')) ?: $ref;
            $o['paras'][] = $type . ' for a period of ' . (int)$row['duration_days'] . ' day(s) from '
                . fmt_date($row['from_date']) . ' to ' . fmt_date($row['to_date']) . ' is hereby sanctioned in favour of '
                . $person . '.';
            if (!empty($row['is_break_in_service'])) {
                $o['paras'][] = 'The period of extraordinary leave exceeding the prescribed limit shall be treated as a break in service and the date of superannuation shall stand revised accordingly.';
            }
            $o['paras'][] = ucfirst($he) . ' shall resume duty on the expiry of the sanctioned leave.';
            $o['facts'] = array_filter([
                'Leave Type' => $type,
                'From'       => fmt_date($row['from_date']),
                'To'         => fmt_date($row['to_date']),
                'Days'       => (string)(int)$row['duration_days'],
                'Status'     => $row['status'] ?? '',
                'Station Leave' => !empty($row['station_leave']) ? 'Permitted' : '',
            ]);
            break;

        case 'awards':
            $type = master_name('award_types', (int)$row['award_type_id']);
            $o['subject'] = strtoupper($type) . ' — ' . strtoupper($emp['name']);
            $o['paras'][] = 'The competent authority has been pleased to award ' . $type . ' to ' . $person
                . ' in recognition of ' . $his . ' meritorious services'
                . (!empty($row['description']) ? ' — ' . $row['description'] : '') . '.';
            $o['paras'][] = 'This may be placed in ' . $his . ' personal file / service book.';
            $o['facts'] = array_filter(['Award' => $type, 'Order Date' => fmt_date($row['order_date'])]);
            break;

        case 'punishments':
            $type  = db_find('punishment_types', (int)$row['punishment_type_id']);
            $tName = $type['name'] ?? 'Punishment';
            $isSus = !empty($type['is_suspension']);
            $o['kind'] = $isSus ? 'Suspension Order' : 'Punishment Order';
            $o['subject'] = strtoupper($isSus ? 'Suspension from Service' : 'Imposition of Penalty — ' . $tName) . ' — ' . strtoupper($emp['name']);
            if ($isSus) {
                $o['paras'][] = $person . ', is hereby placed under suspension with effect from '
                    . fmt_date($row['suspension_start'])
                    . ($row['suspension_end'] ? ' up to ' . fmt_date($row['suspension_end']) : ' until further orders') . '.';
                $o['paras'][] = 'During the period of suspension ' . $he . ' shall be entitled to '
                    . strtolower((string)($row['pay_status'] ?: 'subsistence allowance')) . ' as admissible under the rules, and shall not leave the station without prior permission of the competent authority.';
            } else {
                $o['paras'][] = 'The penalty of ' . $tName . ' is hereby imposed upon ' . $person
                    . (!empty($row['description']) ? ' on the charge of ' . $row['description'] : '') . '.';
            }
            if (!empty($row['order_by_authority'])) {
                $o['paras'][] = 'This order is issued by ' . $row['order_by_authority'] . ' in exercise of the powers vested under the Punjab Employees Efficiency, Discipline and Accountability Act, 2006.';
            }
            $o['facts'] = array_filter([
                'Penalty'          => $tName,
                'Suspension From'  => $row['suspension_start'] ? fmt_date($row['suspension_start']) : '',
                'Suspension Up To' => $row['suspension_end'] ? fmt_date($row['suspension_end']) : ($isSus ? 'Until further orders' : ''),
                'Pay During Suspension' => $row['pay_status'] ?? '',
                'Ordering Authority'    => $row['order_by_authority'] ?? '',
            ]);
            break;

        case 'reinstatements':
            $type = master_name('reinstatement_types', (int)$row['reinstatement_type_id']);
            $o['subject'] = 'REINSTATEMENT IN SERVICE — ' . strtoupper($emp['name']);
            $o['order_date'] = $row['order_date'] ?: $row['reinstatement_date'];
            $o['paras'][] = 'Consequent upon ' . strtolower($type) . ', ' . $person
                . ', is hereby reinstated in service with effect from ' . fmt_date($row['reinstatement_date']) . '.';
            $o['paras'][] = 'The period of suspension shall be treated as '
                . (!empty($row['period_treated_as']) ? $row['period_treated_as'] : 'period spent on duty')
                . (!empty($row['back_benefits']) ? ' and back benefits are allowed as admissible under the rules' : '') . '.';
            $o['facts'] = array_filter([
                'Nature'            => $type,
                'Reinstated From'   => fmt_date($row['reinstatement_date']),
                'Period Treated As' => $row['period_treated_as'] ?? '',
                'Back Benefits'     => !empty($row['back_benefits']) ? 'Allowed' : 'Not allowed',
            ]);
            break;

        case 'deputations':
            $org = master_name('deputation_orgs', (int)$row['deputation_org_id']);
            $o['subject'] = 'DEPUTATION TO ' . strtoupper($org) . ' — ' . strtoupper($emp['name']);
            $o['order_date'] = $row['order_date'] ?: $row['start_date'];
            $o['paras'][] = 'The services of ' . $person . ', are hereby placed at the disposal of ' . $org
                . ' on deputation basis with effect from ' . fmt_date($row['start_date'])
                . ($row['expected_reversion_date'] ? ' up to ' . fmt_date($row['expected_reversion_date']) : '') . '.';
            $o['paras'][] = 'The terms and conditions of deputation shall be governed by the relevant rules. '
                . ucfirst($he) . ' shall stand reverted to the parent department on the expiry of the deputation period.';
            $o['facts'] = array_filter([
                'Organisation'       => $org,
                'From'               => fmt_date($row['start_date']),
                'Expected Reversion' => $row['expected_reversion_date'] ? fmt_date($row['expected_reversion_date']) : '',
                'Status'             => $row['status'] ?? '',
                'Actual Reversion'   => $row['actual_reversion_date'] ? fmt_date($row['actual_reversion_date']) : '',
            ]);
            break;

        case 'exits':
            $type = (string)$row['exit_type'];
            $o['kind'] = $type === 'Normal Retirement' ? 'Retirement Order' : $type . ' Order';
            $o['subject'] = strtoupper($type) . ' — ' . strtoupper($emp['name']);
            $o['order_date'] = $row['order_date'] ?: $row['exit_date'];
            $o['paras'][] = match ($type) {
                'Normal Retirement' => $person . ', stands retired from government service on attaining the age of superannuation with effect from ' . fmt_date($row['exit_date']) . '.',
                'Resignation'       => 'The resignation tendered by ' . $person . ' is hereby accepted with effect from ' . fmt_date($row['exit_date']) . '.',
                'Death in Service'  => 'It is notified with profound grief that ' . $person . ', expired on ' . fmt_date($row['date_of_death'] ?: $row['exit_date']) . '. ' . ucfirst($his) . ' service stands closed with effect from that date.',
                'Medical Invalidation' => $person . ', is hereby invalidated from service on medical grounds with effect from ' . fmt_date($row['exit_date']) . ' on the recommendation of the Medical Board' . ($row['medical_board_ref'] ? ' (' . $row['medical_board_ref'] . ')' : '') . '.',
                'Dismissal'         => $person . ', is hereby dismissed from service with effect from ' . fmt_date($row['exit_date']) . '.',
                'Termination'       => 'The services of ' . $person . ', are hereby terminated with effect from ' . fmt_date($row['exit_date']) . '.',
                default             => 'The service of ' . $person . ' stands closed with effect from ' . fmt_date($row['exit_date']) . ' on account of ' . strtolower($type) . '.',
            };
            if (in_array($type, ['Normal Retirement', 'Death in Service', 'Medical Invalidation'], true)) {
                $o['paras'][] = 'The pension / gratuity and other retirement benefits admissible under the rules may be finalised by the office concerned without delay.';
            }
            $o['facts'] = array_filter([
                'Nature'        => $type,
                'Effective Date' => fmt_date($row['exit_date']),
                'Date of Death' => $row['date_of_death'] ? fmt_date($row['date_of_death']) : '',
                'Medical Board' => $row['medical_board_ref'] ?? '',
            ]);
            break;

        case 'rejoinings':
            $o['subject'] = 'REJOINING OF SERVICE — ' . strtoupper($emp['name']);
            $o['order_date'] = $row['order_date'] ?: $row['rejoining_date'];
            $o['paras'][] = $person . ', is hereby allowed to rejoin government service with effect from '
                . fmt_date($row['rejoining_date']) . ' (' . ($row['rejoin_type'] ?? 'Rejoining') . ').';
            if (!empty($row['new_contract_from'])) {
                $o['paras'][] = 'The contract is accordingly renewed from ' . fmt_date($row['new_contract_from'])
                    . ' to ' . fmt_date($row['new_contract_to']) . '.';
            }
            $o['facts'] = array_filter([
                'Nature'         => $row['rejoin_type'] ?? '',
                'Rejoining Date' => fmt_date($row['rejoining_date']),
                'Contract From'  => $row['new_contract_from'] ? fmt_date($row['new_contract_from']) : '',
                'Contract To'    => $row['new_contract_to'] ? fmt_date($row['new_contract_to']) : '',
            ]);
            break;

        case 'inquiries':
            $type = master_name('inquiry_types', (int)$row['inquiry_type_id']);
            $done = ($row['status'] ?? '') === 'Concluded';
            $o['kind'] = $done ? 'Inquiry Conclusion Order' : 'Inquiry Order';
            $o['subject'] = ($done ? 'CONCLUSION OF INQUIRY' : 'INITIATION OF INQUIRY') . ' — ' . strtoupper($emp['name']);
            $o['order_date'] = $done ? ($row['concluded_date'] ?: $row['order_date']) : ($row['order_date'] ?: $row['initiated_date']);
            if ($done) $o['order_no'] = trim((string)($row['closing_order_no'] ?? '')) ?: $orderNo;
            if ($done) {
                $o['paras'][] = 'The ' . $type . ' initiated against ' . $person . ' on ' . fmt_date($row['initiated_date'])
                    . ' in the matter of "' . $row['subject'] . '" has been concluded on ' . fmt_date($row['concluded_date'])
                    . '. The finding of the competent authority is: ' . $row['outcome'] . '.';
                if (!empty($row['findings'])) $o['paras'][] = 'Findings: ' . $row['findings'];
            } else {
                $o['paras'][] = 'A ' . $type . ' is hereby ordered against ' . $person
                    . ' in the matter of "' . $row['subject'] . '" with effect from ' . fmt_date($row['initiated_date']) . '.';
                if (!empty($row['allegation'])) $o['paras'][] = 'Allegation: ' . $row['allegation'];
                if (!empty($row['inquiry_officer'])) {
                    $o['paras'][] = $row['inquiry_officer'] . ' is appointed as Inquiry Officer and is requested to conclude the proceedings and submit the report to this office within the period prescribed under the Punjab Employees Efficiency, Discipline and Accountability Act, 2006.';
                }
            }
            $o['facts'] = array_filter([
                'Inquiry Type'       => $type,
                'Subject'            => $row['subject'],
                'Initiated On'       => fmt_date($row['initiated_date']),
                'Inquiry Officer'    => $row['inquiry_officer'] ?? '',
                'Ordering Authority' => $row['ordering_authority'] ?? '',
                'Status'             => $row['status'] ?? '',
                'Outcome'            => $row['outcome'] ?? '',
                'Concluded On'       => $row['concluded_date'] ? fmt_date($row['concluded_date']) : '',
            ]);
            break;
    }

    if (!empty($row['remarks'])) $o['paras'][] = 'Remarks: ' . $row['remarks'];
    $o['signer'] = order_signer($row, $emp);
    $note = $o['signer']['footer_note'];
    if ($note !== '') $o['paras'][] = $note;

    /* What the order would say with nothing edited — the starting point for
       the edit panel, and what "reset to default" restores. */
    $o['default_subject']  = $o['subject'];
    $o['default_body']     = implode("\n", $o['paras']);
    $signerCc = order_lines((string)$o['signer']['copy_to']);
    $o['default_copy_to']  = implode("\n", $signerCc);
    $o['default_order_no'] = $o['order_no'];

    /* The officer's own wording, filing number and distribution list win. */
    $ov = order_override($table, $id);
    $o['override'] = $ov;
    if ($ov) {
        if (trim((string)$ov['filing_no']) !== '') $o['order_no'] = trim((string)$ov['filing_no']);
        if (trim((string)$ov['subject'])   !== '') $o['subject']  = trim((string)$ov['subject']);
        if (trim((string)$ov['body'])      !== '') $o['paras']    = order_lines((string)$ov['body']);
    }
    /* copy_to stored as '' means the officer deliberately cleared the list, so
       the endorsement is omitted; NULL means it was never edited. */
    $o['copy_to'] = ($ov && $ov['copy_to'] !== null)
        ? order_lines((string)$ov['copy_to'])
        : $signerCc;

    return $o;
}

/** Split a textarea into trimmed, non-empty lines. */
function order_lines(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: [])));
}

/** The stored edits for one record, or null when it has never been edited. */
function order_override(string $table, int $id): ?array
{
    return db_row('SELECT * FROM order_documents WHERE record_table = ? AND record_id = ?', [$table, $id]);
}

/**
 * Store the officer's edits. Fields identical to the generated default are
 * saved as NULL, so the order keeps following the service record wherever it
 * was not deliberately changed.
 */
function order_save_override(string $table, int $id, array $in, array $default): void
{
    $norm = fn(?string $v) => trim((string)$v) === '' ? null : trim((string)$v);
    $same = fn(?string $a, ?string $b) => preg_replace('/\s+/', ' ', (string)$a) === preg_replace('/\s+/', ' ', (string)$b);

    /* The order prints the label "No." itself, so drop it if the officer typed it. */
    $filing = preg_replace('/^\s*(no\.?|number)\s*[:.]?\s*/i', '', (string)($in['filing_no'] ?? ''));

    $data = [
        'filing_no' => $norm($filing),
        'subject'   => $norm($in['subject'] ?? null),
        'body'      => $norm($in['body'] ?? null),
        'copy_to'   => $norm($in['copy_to'] ?? null),
    ];
    if ($same($data['filing_no'], $default['order_no'])) $data['filing_no'] = null;
    if ($same($data['subject'],   $default['subject']))  $data['subject']   = null;
    if ($same($data['body'],      $default['body']))     $data['body']      = null;
    if ($same($data['copy_to'],   $default['copy_to']))  $data['copy_to']   = null;
    /* blank box + a non-empty default = "no endorsement on this order" */
    if ($data['copy_to'] === null && array_key_exists('copy_to', $in)
        && trim((string)$in['copy_to']) === '' && trim((string)$default['copy_to']) !== '') {
        $data['copy_to'] = '';
    }

    $existing = order_override($table, $id);
    if (!array_filter($data, fn($v) => $v !== null)) {
        if ($existing) db_exec('DELETE FROM order_documents WHERE id = ?', [(int)$existing['id']]);
        return;
    }
    $data['updated_by'] = user_id();
    if ($existing) {
        db_update('order_documents', $data, 'id = ?', [(int)$existing['id']]);
    } else {
        db_insert('order_documents', $data + ['record_table' => $table, 'record_id' => $id]);
    }
}

/** Remove every edit so the order reverts to the generated wording. */
function order_reset_override(string $table, int $id): void
{
    db_exec('DELETE FROM order_documents WHERE record_table = ? AND record_id = ?', [$table, $id]);
}

/** Distribution list ("A copy is forwarded to…"), one entry per line in settings. */
function order_copy_to(): array
{
    $raw = (string)setting('order_copy_to', '');
    $list = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    return $list;
}

/** Directory holding the uploaded signature image. */
function order_signature_dir(): string
{
    return ROOT_PATH . '/uploads/signatures';
}

/**
 * The signature image as a data URI, or '' when none is uploaded.
 * Inlined rather than linked so that a downloaded Word order carries the
 * signature with it and /uploads stays closed to direct requests.
 */
function order_signature_src(): string
{
    $file = trim((string)setting('order_signature_file', ''));
    if ($file === '') return '';
    $path = order_signature_dir() . '/' . basename($file);
    if (!is_file($path)) return '';
    $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        default       => 'image/png',
    };
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
}

/** Link to the printable order for a record. */
function order_url(string $table, int $id, string $extra = ''): string
{
    return url('reports/order.php?table=' . rawurlencode($table) . '&id=' . $id . $extra);
}

/** Small icon button used in registers and on the employee profile. */
function order_button(string $table, int $id, string $class = 'btn btn-ghost btn-sm'): string
{
    if (!order_supported($table)) return '';
    return '<a href="' . e(order_url($table, $id)) . '" target="_blank" class="' . e($class) . '" title="Official order (print / download)">'
        . '<i class="bi bi-file-earmark-text"></i></a>';
}

/** "Download the order" link appended to the success flash after saving a record. */
function order_flash_link(string $table, int $id): string
{
    if (!order_supported($table)) return '';
    return ' <a href="' . e(order_url($table, $id)) . '" target="_blank" class="alert-link">'
        . '<i class="bi bi-file-earmark-text me-1"></i>Open the official order</a>.';
}
