        </main>
        <footer class="py-3 px-4 text-muted small">
            <div class="d-flex justify-content-between align-items-center">
                <span>Aplicativo de Natillera creado por Dorian Gómez</span>
                <span><?php echo date('Y'); ?></span>
            </div>
        </footer>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const body = document.body;
        const toggleButton = document.getElementById('sidebarToggle');
        const closeButton = document.getElementById('sidebarClose');
        const backdrop = document.querySelector('.sidebar-backdrop');
        const navLinks = document.querySelectorAll('.nav-link-sidebar');

        const closeSidebar = () => body.classList.remove('sidebar-open');

        if (toggleButton) {
            toggleButton.addEventListener('click', () => {
                body.classList.toggle('sidebar-open');
            });
        }

        if (closeButton) {
            closeButton.addEventListener('click', closeSidebar);
        }

        if (backdrop) {
            backdrop.addEventListener('click', closeSidebar);
        }

        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992) {
                closeSidebar();
            }
        });
    });
</script>
</body>
</html>
