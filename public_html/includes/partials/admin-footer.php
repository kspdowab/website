<?php
/**
 * KSPDOWA — Admin Portal Shared Footer
 * ============================================================
 */
declare(strict_types=1);
?>
        </div><!-- /.app-content -->
    </main><!-- /.app-main -->
</div><!-- /.app-layout -->

<script>
// Mobile sidebar drawer toggle
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('menuToggleBtn');
    var sidebar   = document.getElementById('appSidebar');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('mobile-open');
        });

        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 991 && sidebar.classList.contains('mobile-open')) {
                if (!sidebar.contains(e.target) && e.target !== toggleBtn) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });
    }
});
</script>
</body>
</html>
