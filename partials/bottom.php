    </main>
    
    <footer style="background: #2c3e50; color: white; text-align: center; padding: 20px; margin-top: 50px;">
        <p>&copy; <?= date('Y') ?> Block Plant ERP System. <?= e(t('all_rights_reserved')) ?></p>
    </footer>
    
    <script>
        // Mobile menu toggle for dropdowns
        document.addEventListener('DOMContentLoaded', function() {
            const dropdowns = document.querySelectorAll('.navbar .dropdown > a');
            dropdowns.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (window.innerWidth <= 768) {
                        e.preventDefault();
                        this.parentElement.classList.toggle('open');
                    }
                });
            });
        });
    </script>
</body>
</html>
