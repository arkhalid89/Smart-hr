<?php
/**
 * Employee form (shared by add.php / edit.php).
 * Expects: $v (values), $isEdit (bool), $opts (select options), $emp (row or null)
 */
declare(strict_types=1);
$val = fn(string $k, $default = '') => e($v[$k] ?? $default);
?>
<form method="post" enctype="multipart/form-data" novalidate id="employeeForm">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-9">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-section-title"><i class="bi bi-person me-1"></i>Personal Information</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">CNIC <span class="req">*</span></label>
                            <input type="text" name="cnic" class="form-control" data-cnic value="<?= $val('cnic') ?>" placeholder="35202-1234567-1" required maxlength="15" id="cnic">
                            <div class="form-text" id="cnicHint">13 digits, unique per employee.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Full Name <span class="req">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= $val('name') ?>" required maxlength="120">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Father / Husband Name <span class="req">*</span></label>
                            <input type="text" name="father_husband_name" class="form-control" value="<?= $val('father_husband_name') ?>" required maxlength="120">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Gender <span class="req">*</span></label>
                            <select name="gender" class="form-select"><?= options_html(['Male', 'Female', 'Other'], $v['gender'] ?? 'Male') ?></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date of Birth <span class="req">*</span></label>
                            <input type="date" name="dob" id="dob" class="form-control" data-dob="#age" value="<?= $val('dob') ?>" required max="<?= today() ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Age</label>
                            <input type="number" id="age" class="form-control" data-age="#dob" value="<?= !empty($v['dob']) ? calc_age($v['dob']) : '' ?>" min="15" max="80" title="Enter age to auto-fill an approximate DOB">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Marital Status</label>
                            <select name="marital_status_id" class="form-select select2" data-allow-clear="1" data-placeholder="Select"><option value=""></option><?= options_html($opts['marital_statuses'], $v['marital_status_id'] ?? '') ?></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Blood Group</label>
                            <select name="blood_group_id" class="form-select select2" data-allow-clear="1" data-placeholder="Select"><option value=""></option><?= options_html($opts['blood_groups'], $v['blood_group_id'] ?? '') ?></select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Mobile No. <span class="text-muted fw-normal">(SMS)</span></label>
                            <input type="text" name="contact" class="form-control" value="<?= $val('contact') ?>" maxlength="30" placeholder="03xx-xxxxxxx">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">WhatsApp No.</label>
                            <input type="text" name="whatsapp_no" class="form-control" value="<?= $val('whatsapp_no') ?>" maxlength="30" placeholder="same as mobile">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= $val('email') ?>" maxlength="120">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" value="<?= $val('address') ?>" maxlength="255">
                        </div>
                    </div>

                    <div class="form-section-title"><i class="bi bi-briefcase me-1"></i>Employment Information</div>
                    <div class="row g-3">
                        <?php if ($isEdit): ?>
                        <div class="col-12">
                            <label class="form-label">Department</label>
                            <div class="form-control-plaintext py-1">
                                <strong><?= e($emp['department']) ?></strong>
                                <span class="text-muted">&middot; <?= e($emp['tehsil'] ? 'Tehsil ' . $emp['tehsil'] . ', ' : 'District headquarters, ') ?>District <?= e($emp['district']) ?></span>
                            </div>
                            <div class="form-text">An employee moves to another department through a posting or transfer, so the history stays complete.</div>
                            <input type="hidden" name="department_id" value="<?= (int)$emp['department_id'] ?>">
                        </div>
                        <?php else: ?>
                        <div class="col-12">
                            <?= geo_picker(['name' => 'department_id', 'value' => $v['department_id'] ?? null, 'required' => true, 'id' => 'empgeo']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-4">
                            <label class="form-label">Designation <span class="req">*</span></label>
                            <select name="designation_id" id="designation_id" class="form-select select2" data-placeholder="Select designation" required><option value=""></option>
                                <?php foreach ($opts['designations'] as $o): ?><option value="<?= $o['id'] ?>" data-scale="<?= (int)$o['pay_scale_id'] ?>" <?= (string)($v['designation_id'] ?? '') === (string)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Pay Scale</label>
                            <select name="pay_scale_id" id="pay_scale_id" class="form-select select2" data-allow-clear="1" data-placeholder="Select"><option value=""></option>
                                <?php foreach ($opts['pay_scales'] as $o): ?><option value="<?= $o['id'] ?>" data-min="<?= e($o['min_salary']) ?>" <?= (string)($v['pay_scale_id'] ?? '') === (string)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Basic Salary</label>
                            <input type="number" name="basic_salary" id="basic_salary" class="form-control" step="1" min="0" value="<?= $val('basic_salary') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Joining Date <span class="req">*</span></label>
                            <input type="date" name="joining_date" id="joining_date" class="form-control" value="<?= $val('joining_date') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Employment Type</label>
                            <select name="employment_type_id" id="employment_type_id" class="form-select select2" data-placeholder="Select"><option value=""></option>
                                <?php foreach ($opts['employment_types'] as $o): ?><option value="<?= $o['id'] ?>" data-contract="<?= (int)$o['is_contract'] ?>" data-probation="<?= (int)$o['has_probation'] ?>" <?= (string)($v['employment_type_id'] ?? '') === (string)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 contract-field">
                            <label class="form-label">Contract From</label>
                            <input type="date" name="contract_from" class="form-control" value="<?= $val('contract_from') ?>">
                        </div>
                        <div class="col-md-3 contract-field">
                            <label class="form-label">Contract To</label>
                            <input type="date" name="contract_to" class="form-control" value="<?= $val('contract_to') ?>">
                        </div>
                        <?php if ($isEdit): ?>
                        <div class="col-md-3">
                            <label class="form-label">Current Status</label>
                            <div class="pt-1"><?= status_badge($emp['status']) ?> <span class="form-text d-inline">(changes via service records)</span></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-section-title"><i class="bi bi-hourglass-split me-1"></i>Probation &amp; Confirmation</div>
                    <div class="row g-3">
                        <div class="col-md-3 probation-field">
                            <label class="form-label">Probation Start</label>
                            <input type="date" name="probation_start" id="probation_start" class="form-control" value="<?= $val('probation_start') ?>">
                            <div class="form-text">Defaults to joining date.</div>
                        </div>
                        <div class="col-md-3 probation-field">
                            <label class="form-label">Probation End</label>
                            <input type="date" name="probation_end" id="probation_end" class="form-control" value="<?= $val('probation_end') ?>">
                            <div class="form-text">Default: +<?= (int)setting('probation_months', 12) ?> months.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Confirmation Date</label>
                            <input type="date" name="confirmation_date" class="form-control" value="<?= $val('confirmation_date') ?>">
                            <div class="form-text">Set when confirmed → status Active.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Confirmation Order No.</label>
                            <input type="text" name="confirmation_order_no" class="form-control" value="<?= $val('confirmation_order_no') ?>" maxlength="80">
                        </div>
                    </div>

                    <div class="form-section-title"><i class="bi bi-bank me-1"></i>Government Record Fields <span class="text-muted fw-normal text-lowercase">(optional)</span></div>
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Service Book No.</label><input type="text" name="service_book_no" class="form-control" value="<?= $val('service_book_no') ?>" maxlength="50"></div>
                        <div class="col-md-3"><label class="form-label">GP Fund No.</label><input type="text" name="gp_fund_no" class="form-control" value="<?= $val('gp_fund_no') ?>" maxlength="50"></div>
                        <div class="col-md-3"><label class="form-label">Pension No.</label><input type="text" name="pension_no" class="form-control" value="<?= $val('pension_no') ?>" maxlength="50"></div>
                        <div class="col-md-3"><label class="form-label">Seniority No.</label><input type="text" name="seniority_no" class="form-control" value="<?= $val('seniority_no') ?>" maxlength="50"></div>
                        <div class="col-12"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"><?= $val('remarks') ?></textarea></div>
                    </div>

                    <?php if (!$isEdit): ?>
                    <div class="form-section-title"><i class="bi bi-geo-alt me-1"></i>Initial Posting</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Posting Location</label>
                            <select name="posting_location_id" class="form-select" data-geo-locations><option value="">— Department's main office —</option><?= posting_location_options_html(isset($v['posting_location_id']) ? (int)$v['posting_location_id'] : null) ?></select>
                        </div>
                        <div class="col-md-3"><label class="form-label">Office</label><input type="text" name="posting_office" class="form-control" value="<?= $val('posting_office', setting('org_name', ORG_NAME)) ?>" maxlength="150"></div>
                        <div class="col-md-2"><label class="form-label">Section</label><input type="text" name="posting_section" class="form-control" value="<?= $val('posting_section') ?>" maxlength="120"></div>
                        <div class="col-md-2"><label class="form-label">Order No.</label><input type="text" name="posting_order_no" class="form-control" value="<?= $val('posting_order_no') ?>" maxlength="80"></div>
                        <div class="col-md-2"><label class="form-label">Reporting Officer</label><input type="text" name="posting_reporting_officer" class="form-control" value="<?= $val('posting_reporting_officer') ?>" maxlength="120"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="card mb-3">
                <div class="card-header"><span class="card-title">Photo</span></div>
                <div class="card-body text-center">
                    <img src="<?= $isEdit ? photo_url($emp['photo'], (int)$emp['id']) : asset('img/avatar.svg') ?>" class="profile-photo mb-3" id="photoPreview" alt="">
                    <input type="file" name="photo" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" id="photoInput">
                    <div class="form-text">JPG / PNG / WEBP, max 2 MB.</div>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-header"><span class="card-title">Employee Code</span></div>
                <div class="card-body">
                    <?php if ($isEdit): ?>
                        <div class="fs-5 fw-bold font-monospace"><?= e($emp['employee_code']) ?></div>
                        <div class="form-text">Auto-generated; cannot be changed.</div>
                    <?php else: ?>
                        <div class="fs-5 fw-bold font-monospace" id="codePreview">—</div>
                        <div class="form-text">Auto-generated on save using pattern <code><?= e(setting('employee_code_pattern')) ?></code></div>
                    <?php endif; ?>
                    <hr>
                    <div class="small text-muted">Retirement date (auto): <strong id="retirementPreview"><?= !empty($v['dob']) ? fmt_date(calc_retirement_date($v['dob'], (int)($emp['eol_break_days'] ?? 0))) : '—' ?></strong></div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Employee' ?></button>
                <a href="<?= $isEdit ? url('employees/view.php?id=' . $emp['id']) : url('employees/index.php') ?>" class="btn btn-light">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php
$retAge = (int)setting('retirement_age', DEFAULT_RETIREMENT_AGE);
$probMonths = (int)setting('probation_months', 12);
$isEditJs = $isEdit ? 'true' : 'false';
$empId = (int)($emp['id'] ?? 0);
$extra_js = <<<JS
<script>
(function(){
  const isEdit = $isEditJs;
  // photo preview
  $('#photoInput').on('change', function(){ if (this.files[0]) $('#photoPreview').attr('src', URL.createObjectURL(this.files[0])); });
  // designation -> default scale, scale -> default salary
  $('#designation_id').on('change', function(){
    const s = $(this).find(':selected').data('scale');
    if (s && !$('#pay_scale_id').val()) $('#pay_scale_id').val(String(s)).trigger('change');
  });
  $('#pay_scale_id').on('change', function(){
    const m = $(this).find(':selected').data('min');
    if (m && !$('#basic_salary').val()) $('#basic_salary').val(Math.round(m));
  });
  // employment type -> show/hide contract & probation fields
  function empType(){
    const o = $('#employment_type_id').find(':selected');
    const contract = String(o.data('contract')) === '1', probation = o.data('probation') === undefined || String(o.data('probation')) === '1';
    $('.contract-field').toggle(contract);
    $('.probation-field').toggle(probation);
    if (!isEdit && probation && $('#joining_date').val() && !$('#probation_start').val()) $('#joining_date').trigger('change');
  }
  $('#employment_type_id').on('change', empType); empType();
  // joining -> probation defaults (new only)
  $('#joining_date').on('change', function(){
    if (isEdit || !this.value) return;
    $('#probation_start').val(this.value);
    const d = new Date(this.value); d.setMonth(d.getMonth() + $probMonths);
    $('#probation_end').val(d.toISOString().slice(0,10));
    codePreview();
  });
  $('#probation_start').on('change', function(){
    if (!this.value) return;
    const d = new Date(this.value); d.setMonth(d.getMonth() + $probMonths);
    $('#probation_end').val(d.toISOString().slice(0,10));
  });
  // dob -> retirement preview
  $('#dob').on('change', function(){
    if (!this.value) return;
    const d = new Date(this.value); d.setFullYear(d.getFullYear() + $retAge);
    $('#retirementPreview').text(d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}));
  });
  // live CNIC uniqueness
  let cnicTimer;
  $('#cnic').on('input', function(){
    clearTimeout(cnicTimer);
    const v = this.value; if (v.length < 15) { $('#cnicHint').removeClass('text-danger text-success').text('13 digits, unique per employee.'); return; }
    cnicTimer = setTimeout(() => $.getJSON(HR.url('employees/ajax/check_cnic.php'), { cnic: v, id: $empId }, r => {
      $('#cnicHint').toggleClass('text-danger', !r.ok).toggleClass('text-success', r.ok).text(r.message);
    }), 300);
  });
  // employee code preview (new only)
  function codePreview(){
    if (isEdit) return;
    const dept = $('#department_id').val(); if (!dept) return;
    $.getJSON(HR.url('employees/ajax/code_preview.php'), { department_id: dept, joining_date: $('#joining_date').val() }, r => $('#codePreview').text(r.code || '—'));
  }
  $('#department_id').on('change', codePreview); codePreview();
})();
</script>
JS;
