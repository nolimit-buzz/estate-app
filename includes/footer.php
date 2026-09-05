<!-- includes/footer.php -->
    </div> <!-- End .content-wrapper -->
</main> <!-- End .main-content -->
</div> <!-- End .app-container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Simple sidebar toggle script
    document.querySelector('.toggle-sidebar').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('active');
        document.querySelector('.main-content').classList.toggle('expanded');
    });
</script>
</body>
</html>
