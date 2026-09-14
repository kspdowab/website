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

    // Sidebar Accordion: only expand and hold until click on new main menu
    var dropdownToggles = document.querySelectorAll('.nav-dropdown-toggle');
    dropdownToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            var parentDropdown = this.closest('.nav-dropdown');
            if (!parentDropdown) return;

            // If already open, keep it open (hold until click on new main menu)
            if (parentDropdown.classList.contains('open')) {
                return;
            }

            // Close all other open dropdowns
            document.querySelectorAll('.nav-dropdown.open').forEach(function(other) {
                if (other !== parentDropdown) {
                    other.classList.remove('open');
                    var otherToggle = other.querySelector('.nav-dropdown-toggle');
                    if (otherToggle) {
                        otherToggle.setAttribute('aria-expanded', 'false');
                    }
                }
            });

            // Expand clicked dropdown
            parentDropdown.classList.add('open');
            this.setAttribute('aria-expanded', 'true');
        });
    });
});
</script>
</body>
</html>
