<?php if (!isset($branding)) { require_once __DIR__.'/../functions/get_branding.php'; $branding = getBranding(); } ?>
</div><!-- /.u-content -->

<footer class="u-footer">
  <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($branding['CompanyName']) ?> &mdash; Powered by Foley IT</span>
  <span>
    <a href="/docs/privacy.php">Privacy</a>
    <a href="/docs/terms.php">Terms</a>
    <a href="/docs/report.php">Report Issue</a>
  </span>
</footer>

<?php foreach ((array)($additionalJS ?? []) as $js): ?>
<script src="<?= htmlspecialchars($js) ?>"></script>
<?php endforeach; ?>
</body>
</html>
