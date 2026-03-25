    <div class="modal fade" id="globalConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="globalConfirmTitle">تأكيد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body" id="globalConfirmBody">هل أنت متأكد؟</div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">لا</button>
                    <button type="button" class="btn btn-danger btn-sm" id="globalConfirmYes">نعم</button>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            let pendingConfirmForm = null;
            const modalEl = document.getElementById('globalConfirmModal');
            const titleEl = document.getElementById('globalConfirmTitle');
            const bodyEl = document.getElementById('globalConfirmBody');
            const yesBtn = document.getElementById('globalConfirmYes');

            window.confirmSubmit = function(form, opts) {
                opts = opts || {};
                pendingConfirmForm = form;
                if (titleEl) titleEl.textContent = opts.title || 'تأكيد';
                if (bodyEl) bodyEl.textContent = opts.message || 'هل أنت متأكد؟';
                if (yesBtn) {
                    yesBtn.className = 'btn btn-sm ' + (opts.btnClass || 'btn-danger');
                    yesBtn.textContent = opts.btnText || 'نعم';
                }
                if (modalEl && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
            };

            if (yesBtn) {
                yesBtn.addEventListener('click', function() {
                    if (pendingConfirmForm) {
                        pendingConfirmForm.submit();
                        pendingConfirmForm = null;
                    }
                    if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
                });
            }
        })();
    </script>
</body>
</html>
