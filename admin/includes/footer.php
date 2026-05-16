<?php
if (!isset($branding)) {
    if (!function_exists('getBranding')) { require_once __DIR__ . '/../../functions/get_branding.php'; }
    $branding = getBranding();
}
?>
    </div><!-- /.a-content -->
  </main><!-- /.a-main -->
</div><!-- /.a-wrap -->

<footer class="a-footer">
  &copy; <?= date('Y') ?> <?= htmlspecialchars($branding['CompanyName']) ?> &mdash; Powered by Foley IT
</footer>

<?php foreach ((array)($additionalJS ?? []) as $js): ?>
<script src="<?= htmlspecialchars($js) ?>"></script>
<?php endforeach; ?>
</body>
</html>
