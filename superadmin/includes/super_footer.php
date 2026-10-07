    </div> <!-- /super-content-body -->
</main> <!-- /super-main -->
</div> <!-- /super-layout -->

<!-- Toast Notification Container -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11000">
    <div id="saasToast" class="toast align-items-center text-white bg-dark border-0 rounded-4 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2 py-3" id="saasToastMessage">
                Action executed successfully.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- Bootstrap 5 Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSuperSidebar() {
    const sb = document.getElementById('super-sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (sb) sb.classList.toggle('show');
    if (bd) bd.classList.toggle('show');
}

const toastEl = document.getElementById('saasToast');
let saasToastInstance = null;
if (toastEl) {
    saasToastInstance = new bootstrap.Toast(toastEl, { delay: 3500 });
}

function showSuperToast(msg, isSuccess = true) {
    const body = document.getElementById('saasToastMessage');
    if (body) {
        body.innerHTML = (isSuccess ? '<i class="fa-solid fa-circle-check text-success fs-5"></i> ' : '<i class="fa-solid fa-circle-xmark text-danger fs-5"></i> ') + msg;
    }
    if (saasToastInstance) saasToastInstance.show();
}
</script>
</body>
</html>
