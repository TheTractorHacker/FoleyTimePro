<?php
/**
 * Geofence enforcement helpers
 */

/**
 * Haversine distance in metres between two lat/lng points.
 */
function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R   = 6371000; // Earth radius in metres
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a   = sin($dLat / 2) ** 2
         + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Check whether a coordinate is inside any active, required geofence.
 *
 * @param mysqli $conn
 * @param float  $lat
 * @param float  $lng
 * @param int|null $officeId  If provided, also checks office-specific fences
 * @return array ['pass' => bool, 'nearest' => ['name'=>..,'distance'=>..,'radius'=>..] | null]
 */
function checkGeofence(mysqli $conn, float $lat, float $lng, ?int $officeId = null): array
{
    $sql = "SELECT ID, Name, Latitude, Longitude, RadiusMeters
            FROM geofences
            WHERE IsActive = 1 AND IsRequired = 1
              AND (OfficeID IS NULL" . ($officeId ? " OR OfficeID = ?" : "") . ")";

    $stmt = $conn->prepare($sql);
    if ($officeId) { $stmt->bind_param("i", $officeId); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($rows)) {
        return ['pass' => true, 'nearest' => null]; // no fences = open
    }

    $nearest = null;
    foreach ($rows as $fence) {
        $dist = haversineDistance($lat, $lng, (float)$fence['Latitude'], (float)$fence['Longitude']);
        if ($nearest === null || $dist < $nearest['distance']) {
            $nearest = [
                'name'     => $fence['Name'],
                'distance' => round($dist),
                'radius'   => $fence['RadiusMeters'],
            ];
        }
        if ($dist <= $fence['RadiusMeters']) {
            return ['pass' => true, 'nearest' => $nearest];
        }
    }

    return ['pass' => false, 'nearest' => $nearest];
}
