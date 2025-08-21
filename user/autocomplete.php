<?php
require '../auth/db.php';

if (isset($_GET['search'])) {
    $term = '%' . $_GET['search'] . '%';

    $stmt = $conn->prepare("SELECT FirstName, LastName FROM users WHERE CONCAT(FirstName, ' ', LastName) LIKE ?");
    $stmt->bind_param("s", $term);
    $stmt->execute();
    $result = $stmt->get_result();

    $names = [];
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['FirstName'] . ' ' . $row['LastName'];
    }
    echo json_encode($names);
}
