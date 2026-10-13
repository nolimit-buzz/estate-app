<!-- includes/footer.php -->
    </div> <!-- End .content-wrapper -->
</main> <!-- End .main-content -->
</div> <!-- End .app-container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Null-safe sidebar toggle script
    const sbToggle = document.querySelector('.toggle-sidebar');
    if (sbToggle) {
        sbToggle.addEventListener('click', function() {
            const sb = document.querySelector('.sidebar');
            if (sb) sb.classList.toggle('active');
        });
    }
</script>
</body>
</html>
