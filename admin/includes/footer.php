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

<?php foreach ((array)($additionalJS ?? []) as $js):
    // versionedAsset() is defined in header.php, which every page including
    // this file has already run — guarded anyway so a page that somehow
    // includes footer.php on its own degrades to the plain URL instead of a
    // fatal "undefined function".
    $src = function_exists('versionedAsset') ? versionedAsset($js) : $js;
?>
<script src="<?= htmlspecialchars($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
