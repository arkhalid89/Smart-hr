<?php
/**
 * Master data definitions. Every master (dropdown table) is described here
 * once; masters/index.php and masters/ajax/master_crud.php are fully generic.
 *
 * Field types: text | textarea | number | decimal | checkbox | select | static
 *   select : 'source' => table name (uses id/name)      -> stored as int id
 *   static : 'options' => ['A','B']                     -> stored as string
 * usage : [table => column] references used to block hard delete + show count
 */
declare(strict_types=1);

function masters_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $common = [
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'list' => false],
        ['name' => 'sort_order',  'label' => 'Sort Order',  'type' => 'number',   'list' => true, 'default' => 0],
    ];

    $cfg = [
        'districts' => [
            'label' => 'Districts', 'singular' => 'District', 'icon' => 'bi-bank', 'roles' => ['super_admin'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'District', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true, 'maxlength' => 20, 'help' => 'Short code, used in department codes (e.g. NNS).'],
                ['name' => 'division', 'label' => 'Division', 'type' => 'text', 'list' => true, 'maxlength' => 80, 'help' => 'Groups the district in lists (e.g. Lahore).'],
            ], $common),
            'usage' => ['tehsils' => 'district_id', 'departments' => 'district_id'],
        ],
        'tehsils' => [
            'label' => 'Tehsils', 'singular' => 'Tehsil', 'icon' => 'bi-signpost-split', 'roles' => ['super_admin'],
            'geo_filter' => 'district_id = ?',
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Tehsil', 'type' => 'text', 'required' => true, 'list' => true],
                ['name' => 'district_id', 'label' => 'District', 'type' => 'select', 'source' => 'districts', 'required' => true, 'list' => true],
                ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'list' => true, 'maxlength' => 20, 'help' => 'Unique within the district (e.g. SHK).'],
            ], $common),
            'unique_together' => ['district_id', 'name'],
            'usage' => ['departments' => 'tehsil_id'],
        ],
        'departments' => [
            'label' => 'Departments', 'singular' => 'Department', 'icon' => 'bi-building', 'roles' => ['super_admin'],
            'geo_filter' => 'district_id = ?',
            'order' => 'district_id, (tehsil_id IS NOT NULL), tehsil_id, FIELD(level, "district", "tehsil", "department"), sort_order, name',
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Department Name', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true, 'maxlength' => 160],
                ['name' => 'place', 'label' => 'Level & Place', 'type' => 'place', 'list' => true],
                ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true, 'maxlength' => 20, 'help' => 'Short code used in the employee code pattern {DEPT} (e.g. NNS-SHK-AC).'],
                ['name' => 'head_designation', 'label' => 'Head of Office', 'type' => 'text', 'maxlength' => 120, 'help' => 'Printed on the letterhead of this office, e.g. Assistant Commissioner.'],
                ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'maxlength' => 255],
                ['name' => 'latitude', 'label' => 'Latitude', 'type' => 'coord', 'help' => 'Places the office on the Posting Map.'],
                ['name' => 'longitude', 'label' => 'Longitude', 'type' => 'coord', 'help' => 'In Google Maps, right-click the office and click the first line to copy both numbers.'],
            ], $common),
            'usage' => ['employees' => 'department_id', 'postings' => 'department_id', 'posting_locations' => 'department_id', 'sanctioned_posts' => 'department_id', 'users' => 'department_id'],
        ],
        'designations' => [
            'label' => 'Designations', 'singular' => 'Designation', 'icon' => 'bi-person-badge', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Designation', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'pay_scale_id', 'label' => 'Default Pay Scale', 'type' => 'select', 'source' => 'pay_scales', 'list' => true],
            ], $common),
            'usage' => ['employees' => 'designation_id', 'sanctioned_posts' => 'designation_id'],
        ],
        'pay_scales' => [
            'label' => 'Pay Scales', 'singular' => 'Pay Scale', 'icon' => 'bi-cash-stack', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Scale (e.g. BS-17)', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true, 'maxlength' => 30],
                ['name' => 'scale_number', 'label' => 'Scale Number', 'type' => 'number', 'list' => true, 'help' => 'Numeric rank used for comparisons (1-22)'],
                ['name' => 'min_salary', 'label' => 'Minimum Salary', 'type' => 'decimal', 'list' => true],
                ['name' => 'increment_amount', 'label' => 'Annual Increment', 'type' => 'decimal', 'list' => true],
                ['name' => 'max_salary', 'label' => 'Maximum Salary', 'type' => 'decimal', 'list' => true],
            ], $common),
            'usage' => ['employees' => 'pay_scale_id', 'designations' => 'pay_scale_id', 'promotions' => 'new_scale_id'],
        ],
        'leave_types' => [
            'label' => 'Leave Types', 'singular' => 'Leave Type', 'icon' => 'bi-calendar-x', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Leave Type', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'is_paid', 'label' => 'Paid Leave', 'type' => 'checkbox', 'list' => true, 'default' => 1],
                ['name' => 'is_eol', 'label' => 'Extra Ordinary Leave (without pay)', 'type' => 'checkbox', 'list' => true, 'help' => 'EOL longer than the configured months = break in service'],
                ['name' => 'is_lpr', 'label' => 'Leave Preparatory to Retirement', 'type' => 'checkbox', 'list' => true, 'help' => 'Approving this leave sets status to On LPR'],
                ['name' => 'max_days', 'label' => 'Max Days (per instance)', 'type' => 'number', 'list' => true],
            ], $common),
            'usage' => ['leaves' => 'leave_type_id'],
        ],
        'award_types' => [
            'label' => 'Award Types', 'singular' => 'Award Type', 'icon' => 'bi-trophy', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Award Type', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
            ], $common),
            'usage' => ['awards' => 'award_type_id'],
        ],
        'punishment_types' => [
            'label' => 'Punishment Types', 'singular' => 'Punishment Type', 'icon' => 'bi-exclamation-octagon', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Punishment Type', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'severity', 'label' => 'Severity', 'type' => 'static', 'options' => ['Minor', 'Major'], 'list' => true, 'default' => 'Minor'],
                ['name' => 'is_suspension', 'label' => 'Is Suspension', 'type' => 'checkbox', 'list' => true, 'help' => 'Sets employee status to Suspended'],
                ['name' => 'is_removal', 'label' => 'Is Removal from Service', 'type' => 'checkbox', 'list' => true, 'help' => 'Sets status to Terminated'],
                ['name' => 'is_dismissal', 'label' => 'Is Dismissal', 'type' => 'checkbox', 'list' => true, 'help' => 'Sets status to Dismissed (no rejoining allowed)'],
            ], $common),
            'usage' => ['punishments' => 'punishment_type_id'],
        ],
        'posting_locations' => [
            'label' => 'Offices & Sites', 'singular' => 'Office / Site', 'icon' => 'bi-geo-alt', 'roles' => ['super_admin'],
            'geo_filter' => 'department_id IN (SELECT id FROM departments WHERE district_id = ?)',
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Office / Site', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'department_id', 'label' => 'Department', 'type' => 'department', 'required' => true, 'list' => true, 'help' => 'The office belongs to this department; its district and tehsil are the department\'s.'],
                ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'list' => true],
                ['name' => 'latitude', 'label' => 'Latitude', 'type' => 'coord', 'list' => true, 'help' => 'Decimal degrees, e.g. 31.4492. Leave blank to use the department\'s position.'],
                ['name' => 'longitude', 'label' => 'Longitude', 'type' => 'coord', 'list' => true],
            ], $common),
            'usage' => ['postings' => 'location_id'],
        ],
        'inquiry_types' => [
            'label' => 'Inquiry Types', 'singular' => 'Inquiry Type', 'icon' => 'bi-search', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Inquiry Type', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'is_formal', 'label' => 'Formal Inquiry (PEEDA / E&D Rules)', 'type' => 'checkbox', 'list' => true],
            ], $common),
            'usage' => ['inquiries' => 'inquiry_type_id'],
        ],
        'employment_types' => [
            'label' => 'Employment Types', 'singular' => 'Employment Type', 'icon' => 'bi-briefcase', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Employment Type', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'is_contract', 'label' => 'Contract Based (requires contract dates)', 'type' => 'checkbox', 'list' => true],
                ['name' => 'has_probation', 'label' => 'Has Probation Period', 'type' => 'checkbox', 'list' => true, 'default' => 1],
            ], $common),
            'usage' => ['employees' => 'employment_type_id'],
        ],
        'blood_groups' => [
            'label' => 'Blood Groups', 'singular' => 'Blood Group', 'icon' => 'bi-droplet', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Blood Group', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true, 'maxlength' => 10],
            ], $common),
            'usage' => ['employees' => 'blood_group_id'],
        ],
        'marital_statuses' => [
            'label' => 'Marital Status', 'singular' => 'Marital Status', 'icon' => 'bi-people', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Marital Status', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true, 'maxlength' => 40],
            ], $common),
            'usage' => ['employees' => 'marital_status_id'],
        ],
        'deputation_orgs' => [
            'label' => 'Deputation Organizations', 'singular' => 'Organization', 'icon' => 'bi-diagram-3', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Organization', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true, 'maxlength' => 150],
            ], $common),
            'usage' => ['deputations' => 'deputation_org_id', 'postings' => 'deputation_org_id'],
        ],
        'reinstatement_types' => [
            'label' => 'Reinstatement Types', 'singular' => 'Reinstatement Type', 'icon' => 'bi-arrow-counterclockwise', 'roles' => ['super_admin', 'district_officer'],
            'fields' => array_merge([
                ['name' => 'name', 'label' => 'Reinstatement Type', 'type' => 'text', 'required' => true, 'list' => true, 'unique' => true],
                ['name' => 'with_back_benefits', 'label' => 'With Back Benefits', 'type' => 'checkbox', 'list' => true, 'help' => 'Missed increments are auto-calculated on reinstatement'],
            ], $common),
            'usage' => ['reinstatements' => 'reinstatement_type_id'],
        ],
        'sanctioned_posts' => [
            'label' => 'Sanctioned Posts', 'singular' => 'Sanctioned Post', 'icon' => 'bi-clipboard-data', 'roles' => ['super_admin'],
            'name_field' => null,
            'fields' => [
                ['name' => 'department_id', 'label' => 'Department', 'type' => 'department', 'required' => true, 'list' => true],
                ['name' => 'designation_id', 'label' => 'Designation', 'type' => 'select', 'source' => 'designations', 'required' => true, 'list' => true],
                ['name' => 'pay_scale_id', 'label' => 'Pay Scale', 'type' => 'select', 'source' => 'pay_scales', 'list' => true],
                ['name' => 'sanctioned_count', 'label' => 'Sanctioned Posts', 'type' => 'number', 'required' => true, 'list' => true, 'default' => 1],
                ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'list' => false],
            ],
            'unique_together' => ['department_id', 'designation_id'],
            'usage' => [],
            'computed' => ['filled' => 'Filled', 'vacant' => 'Vacant'],
        ],
    ];
    return $cfg;
}

/** Config for a single master type, or null if unknown / not permitted. */
/** The role name master data permissions are written against. */
function master_role(): string
{
    if (is_super_admin()) return 'super_admin';
    if (is_officer() && user_level() === 'district') return 'district_officer';
    return user_role();
}

function master_get(string $type): ?array
{
    $cfg = masters_config();
    if (!isset($cfg[$type])) return null;
    if (!in_array(master_role(), $cfg[$type]['roles'], true)) return null;
    $cfg[$type]['table'] = $type;
    return $cfg[$type];
}
