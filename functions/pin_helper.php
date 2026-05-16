<?php
/**
 * PIN verification with transparent bcrypt migration.
 *
 * Stored format detection:
 *   SHA-256 legacy  → 64 hex chars
 *   bcrypt current  → starts with $2y$
 *
 * On successful SHA-256 verification the hash is upgraded to bcrypt
 * in-place so the user never notices the migration.
 */

function pin_verify_and_upgrade(mysqli $conn, int $userId, string $pin, string $stored): bool
{
    if (str_starts_with($stored, '$2y$')) {
        return password_verify($pin, $stored);
    }

    // Legacy SHA-256 path
    if (!hash_equals($stored, hash('sha256', $pin))) {
        return false;
    }

    // Upgrade to bcrypt silently
    $newHash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $conn->prepare("UPDATE users SET UserPIN = ? WHERE ID = ?");
    $stmt->bind_param("si", $newHash, $userId);
    $stmt->execute();
    $stmt->close();
    return true;
}

/**
 * Kiosk lookup: find a user by raw PIN.
 * Tries a fast SHA-256 index scan first; falls back to a bcrypt scan
 * for already-migrated users only (identified by the $2y$ prefix).
 *
 * @return array|null  Associative array with ID, FirstName, LastName (no hash)
 */
function pin_find_user(mysqli $conn, string $pin): ?array
{
    // Fast path: SHA-256 users (not yet migrated)
    $sha = hash('sha256', $pin);
    $stmt = $conn->prepare(
        "SELECT ID, FirstName, LastName FROM users WHERE UserPIN = ? AND LockOut = 0"
    );
    $stmt->bind_param("s", $sha);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row;

    // Slow path: bcrypt-migrated users (prefix = $2y$)
    $stmt = $conn->prepare(
        "SELECT ID, FirstName, LastName, UserPIN FROM users
         WHERE LockOut = 0 AND UserPIN LIKE '\$2y\$%'"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    while ($user = $result->fetch_assoc()) {
        if (password_verify($pin, $user['UserPIN'])) {
            unset($user['UserPIN']);
            return $user;
        }
    }
    return null;
}

function pin_hash(string $pin): string
{
    return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
}
