<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: login.php'); exit; }

require_once '../auth/db.php';
require_once '../functions/get_branding.php';
$branding = getBranding($conn);

$msg = '';
$msgType = 'success';

require_once __DIR__ . '/includes/csrf.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    $csrfOk = hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf'] ?? '');
    if (!$csrfOk) { $msg = 'Invalid request.'; $msgType = 'error'; }
    else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $lat     = (float)($_POST['lat'] ?? 0);
            $lng     = (float)($_POST['lng'] ?? 0);
            $radius  = max(10, (int)($_POST['radius'] ?? 200));
            $office  = ($_POST['office_id'] ?? '') !== '' ? (int)$_POST['office_id'] : null;
            $active  = isset($_POST['is_active']) ? 1 : 0;
            $req     = isset($_POST['is_required']) ? 1 : 0;

            if (empty($name) || $lat == 0 || $lng == 0) {
                $msg = 'Name, latitude, and longitude are required.'; $msgType = 'error';
            } elseif ($id) {
                $stmt = $conn->prepare("UPDATE geofences SET Name=?,Latitude=?,Longitude=?,RadiusMeters=?,OfficeID=?,IsActive=?,IsRequired=? WHERE ID=?");
                $stmt->bind_param("sddiiiii", $name,$lat,$lng,$radius,$office,$active,$req,$id);
                $stmt->execute();
                $msg = 'Geofence updated.';
            } else {
                $stmt = $conn->prepare("INSERT INTO geofences (Name,Latitude,Longitude,RadiusMeters,OfficeID,IsActive,IsRequired) VALUES(?,?,?,?,?,?,?)");
                $stmt->bind_param("sddiiiii", $name,$lat,$lng,$radius,$office,$active,$req);
                $stmt->execute();
                $msg = 'Geofence created.';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $conn->prepare("DELETE FROM geofences WHERE ID=?")->execute([$id]); // note: bind separately for safety
            $stmt = $conn->prepare("DELETE FROM geofences WHERE ID=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $msg = 'Geofence deleted.';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $conn->query("UPDATE geofences SET IsActive = NOT IsActive WHERE ID=$id");
            $msg = 'Geofence toggled.';
        }
    }
}

// Fetch all fences
$fences  = $conn->query("SELECT g.*, o.OfficeName FROM geofences g LEFT JOIN Offices o ON g.OfficeID=o.ID ORDER BY g.IsActive DESC, g.Name")->fetch_all(MYSQLI_ASSOC);
$offices = $conn->query("SELECT ID, OfficeName FROM Offices ORDER BY OfficeName")->fetch_all(MYSQLI_ASSOC);

$editFence = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($fences as $f) { if ($f['ID'] == $eid) { $editFence = $f; break; } }
}
?>
<?php $pageTitle = 'Geofences';
$headerTitle = 'Geofences';
include 'includes/header.php'; ?>
<div class="admin-content">
<div class="page-header">
    <h1>GPS Geofences</h1>
    <a href="?new=1" class="btn btn-primary">+ New Geofence</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if (isset($_GET['new']) || $editFence): ?>
<div class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0"><?= $editFence ? 'Edit Geofence' : 'New Geofence' ?></h2>
    <div id="map"></div>
    <form method="post">
<?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editFence['ID'] ?? 0) ?>">
        <input type="hidden" name="lat" id="fenceLat" value="<?= $editFence['Latitude'] ?? '' ?>">
        <input type="hidden" name="lng" id="fenceLng" value="<?= $editFence['Longitude'] ?? '' ?>">

        <div class="form-row">
            <div class="form-group">
                <label>Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($editFence['Name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Radius (metres)</label>
                <input type="number" name="radius" value="<?= (int)($editFence['RadiusMeters'] ?? 200) ?>" min="10" max="50000" id="fenceRadius">
            </div>
            <div class="form-group">
                <label>Office (optional)</label>
                <select name="office_id">
                    <option value="">All offices</option>
                    <?php foreach ($offices as $o): ?>
                    <option value="<?= $o['ID'] ?>" <?= ($editFence['OfficeID'] ?? '') == $o['ID'] ? 'selected' : '' ?>><?= htmlspecialchars($o['OfficeName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <label class="switch-label">
                <input type="checkbox" name="is_active" value="1" <?= ($editFence['IsActive'] ?? 1) ? 'checked' : '' ?>>
                Active
            </label>
            <label class="switch-label" style="margin-left:24px">
                <input type="checkbox" name="is_required" value="1" <?= ($editFence['IsRequired'] ?? 1) ? 'checked' : '' ?>>
                Block clock-in when outside fence
            </label>
        </div>
        <p style="color:#666;font-size:13px">Click the map to place the geofence centre, then adjust the radius.</p>
        <div>
            <button type="submit" class="btn btn-primary">Save Geofence</button>
            <a href="geofences.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN/WPgU=" crossorigin=""></script>
<script>
(function(){
    var lat  = parseFloat(document.getElementById('fenceLat').value) || 39.8283;
    var lng  = parseFloat(document.getElementById('fenceLng').value) || -98.5795;
    var rad  = parseInt(document.getElementById('fenceRadius').value) || 200;
    var map  = L.map('map').setView([lat, lng], lat !== 39.8283 ? 15 : 4);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OSM'}).addTo(map);
    var marker = L.marker([lat,lng],{draggable:true}).addTo(map);
    var circle = L.circle([lat,lng],{radius:rad,color:'#073851',fillOpacity:0.15}).addTo(map);

    function updatePos(e){ var p=e.latlng||e.target.getLatLng(); marker.setLatLng(p); circle.setLatLng(p); document.getElementById('fenceLat').value=p.lat.toFixed(7); document.getElementById('fenceLng').value=p.lng.toFixed(7); }
    map.on('click', updatePos);
    marker.on('dragend', updatePos);
    document.getElementById('fenceRadius').addEventListener('input', function(){ circle.setRadius(parseInt(this.value)||200); });
})();
</script>
<?php endif; ?>

<div class="card">
    <?php if (empty($fences)): ?>
    <p style="color:#666;text-align:center;padding:32px">No geofences defined yet. Create one to restrict clock-ins to specific locations.</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Name</th><th>Coordinates</th><th>Radius</th><th>Office</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($fences as $f): ?>
        <tr class="fence-row">
            <td><strong><?= htmlspecialchars($f['Name']) ?></strong><?= $f['IsRequired'] ? ' <span style="color:#e74c3c;font-size:11px">ENFORCED</span>' : '' ?></td>
            <td style="font-family:monospace;font-size:12px"><?= $f['Latitude'] ?>, <?= $f['Longitude'] ?></td>
            <td><?= number_format($f['RadiusMeters']) ?> m</td>
            <td><?= htmlspecialchars($f['OfficeName'] ?? '—') ?></td>
            <td><span class="badge-<?= $f['IsActive'] ? 'active' : 'inactive' ?>"><?= $f['IsActive'] ? 'Active' : 'Inactive' ?></span></td>
            <td>
                <a href="?edit=<?= $f['ID'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="post" style="display:inline">
<?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $f['ID'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"><?= $f['IsActive'] ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this geofence?')">
<?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $f['ID'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</div>
<?php include 'includes/footer.php'; ?>
</body>
</html>
