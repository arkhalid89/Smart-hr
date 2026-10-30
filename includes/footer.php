<?php
/**
 * Closes the content area, renders the fixed footer and loads all
 * 3rd-party JS (CDN) followed by the app script.
 *
 * Optional: $extra_js string of extra <script> tags to append.
 */
declare(strict_types=1);
?>
    </div><!-- /.content-inner -->
</main>

<!-- ===================== FOOTER ===================== -->
<footer class="app-footer">
    <div class="d-flex flex-wrap justify-content-between align-items-center px-3 h-100 small">
        <span>&copy; <?= date('Y') ?> <?= e(setting('org_name', ORG_NAME)) ?>. All rights reserved.</span>
        <span><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></span>
    </div>
</footer>

<!-- Generic confirm modal used by data-confirm links/buttons -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="bi bi-exclamation-triangle text-warning display-5"></i>
        <p class="mt-3 mb-0" id="confirmModalText">Are you sure?</p>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmModalOk">Yes, continue</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== 3rd-party JS (CDN) ===== -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/3.0.3/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/3.0.3/js/responsive.bootstrap5.min.js"></script>
<!-- ===== App JS ===== -->
<script src="<?= asset('js/app.js') ?>"></script>
<?= $extra_js ?? '' ?>
</body>
</html>
