<?php
$authPage   = $authPage ?? false;
$needCharts = $needCharts ?? false;
?>
<?php if (!$authPage): ?>
        </main>
        <footer class="app-footer">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; Personal Financial Expense Tracker</footer>
    </div>
<?php endif; ?>
<?php if ($needCharts): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
