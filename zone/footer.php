<!-- zone/footer.php -->
    </div><!-- /.content-wrapper -->
</main>
</div><!-- /.app-container -->

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar Toggle Logic
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.querySelector('.toggle-sidebar');
    const sidebar = document.querySelector('.sidebar');
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });
    }
});
</script>
</body>
</html>
