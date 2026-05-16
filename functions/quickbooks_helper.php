<?php
/**
 * QuickBooks Online OAuth2 + Payroll API helpers
 * Uses QBO v3 REST API directly (no SDK required)
 * 
 * Required settings in `settings` table:
 *   qb_client_id, qb_client_secret, qb_redirect_uri
 */

define('QB_AUTH_URL',    'https://appcenter.intuit.com/connect/oauth2');
define('QB_TOKEN_URL',   'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
define('QB_API_BASE',    'https://quickbooks.api.intuit.com/v3/company');
define('QB_SANDBOX_API', 'https://sandbox-quickbooks.api.intuit.com/v3/company');
define('QB_SCOPES',      'com.intuit.quickbooks.payroll com.intuit.quickbooks.payroll.timetracking');

function qbGetSetting(mysqli $conn, string $key): string {
    $stmt = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey=?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    return (string)($stmt->get_result()->fetch_assoc()['SettingValue'] ?? '');
}

function qbGetAuthUrl(mysqli $conn): string {
    $clientId    = qbGetSetting($conn, 'qb_client_id');
    $redirectUri = qbGetSetting($conn, 'qb_redirect_uri');
    if (!$clientId || !$redirectUri) {
        return '/admin/settings.php?tab=integrations#quickbooks';
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['qb_oauth_state'] = $state;
    $params = http_build_query([
        'client_id'     => $clientId,
        'response_type' => 'code',
        'scope'         => QB_SCOPES,
        'redirect_uri'  => $redirectUri,
        'state'         => $state,
    ]);
    return QB_AUTH_URL . '?' . $params;
}

function qbExchangeCode(mysqli $conn, string $code, string $realmId): array {
    $clientId     = qbGetSetting($conn, 'qb_client_id');
    $clientSecret = qbGetSetting($conn, 'qb_client_secret');
    $redirectUri  = qbGetSetting($conn, 'qb_redirect_uri');

    $ch = curl_init(QB_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri]),
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode("$clientId:$clientSecret"),
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) return ['success'=>false,'error'=>"QB token exchange failed ($status)"];
    $data = json_decode($body, true);
    if (empty($data['access_token'])) return ['success'=>false,'error'=>'No access token in QB response'];

    // Fetch company name
    $companyName = qbFetchCompanyName($data['access_token'], $realmId);

    $accessExpiry  = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
    $refreshExpiry = date('Y-m-d H:i:s', time() + ($data['x_refresh_token_expires_in'] ?? 8640000));

    $stmt = $conn->prepare(
        "INSERT INTO quickbooks_connection (RealmID,AccessToken,RefreshToken,TokenExpiry,RefreshExpiry,CompanyName)
         VALUES(?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE AccessToken=VALUES(AccessToken),RefreshToken=VALUES(RefreshToken),
         TokenExpiry=VALUES(TokenExpiry),RefreshExpiry=VALUES(RefreshExpiry),CompanyName=VALUES(CompanyName),UpdatedAt=NOW()"
    );
    $stmt->bind_param("ssssss", $realmId, $data['access_token'], $data['refresh_token'], $accessExpiry, $refreshExpiry, $companyName);
    $stmt->execute();

    return ['success'=>true];
}

function qbGetValidToken(mysqli $conn): ?array {
    $qb = $conn->query("SELECT * FROM quickbooks_connection LIMIT 1")->fetch_assoc();
    if (!$qb) return null;

    if (strtotime($qb['TokenExpiry']) < time() + 60) {
        $clientId     = qbGetSetting($conn, 'qb_client_id');
        $clientSecret = qbGetSetting($conn, 'qb_client_secret');

        $ch = curl_init(QB_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type'=>'refresh_token','refresh_token'=>$qb['RefreshToken']]),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode("$clientId:$clientSecret"),
            ],
        ]);
        $body = curl_exec($ch); curl_close($ch);
        $data = json_decode($body, true);

        if (!empty($data['access_token'])) {
            $exp = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
            $conn->prepare("UPDATE quickbooks_connection SET AccessToken=?,TokenExpiry=? WHERE ID=?")
                 ->execute([$data['access_token'], $exp, $qb['ID']]);
            $qb['AccessToken'] = $data['access_token'];
        }
    }
    return $qb;
}

function qbFetchCompanyName(string $token, string $realmId): string {
    $ch = curl_init(QB_API_BASE . "/$realmId/companyinfo/$realmId");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer $token",'Accept: application/json']]);
    $body = curl_exec($ch); curl_close($ch);
    $data = json_decode($body, true);
    return $data['QueryResponse']['CompanyInfo'][0]['CompanyName'] ?? $data['CompanyInfo']['CompanyName'] ?? 'QuickBooks';
}

function qbSyncEmployees(mysqli $conn): array {
    $qb = qbGetValidToken($conn);
    if (!$qb) return ['success'=>false,'error'=>'QuickBooks not connected'];

    $ch = curl_init(QB_API_BASE . "/{$qb['RealmID']}/query?query=" . urlencode("SELECT * FROM Employee MAXRESULTS 100"));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$qb['AccessToken']}",'Accept: application/json']]);
    $body = curl_exec($ch); curl_close($ch);
    $data = json_decode($body, true);
    $qbEmployees = $data['QueryResponse']['Employee'] ?? [];

    $matched = 0;
    foreach ($qbEmployees as $qbEmp) {
        $name  = trim(($qbEmp['GivenName'] ?? '') . ' ' . ($qbEmp['FamilyName'] ?? ''));
        $qbId  = $qbEmp['Id'];
        // Try to match by first+last name
        $stmt  = $conn->prepare("SELECT ID FROM users WHERE CONCAT(FirstName,' ',LastName) LIKE ?");
        $like  = "%$name%";
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $empId = $row['ID'];
            $conn->prepare("INSERT INTO quickbooks_employee_map (EmployeeID,QBEmployeeID,QBDisplayName,LastSyncAt) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE QBEmployeeID=VALUES(QBEmployeeID),QBDisplayName=VALUES(QBDisplayName),LastSyncAt=NOW()")
                 ->execute([$empId, $qbId, $name]);
            $matched++;
        }
    }

    $conn->prepare("INSERT INTO quickbooks_sync_log (SyncType,RecordsIn,RecordsOk) VALUES('employee',?,?)")
         ->execute([count($qbEmployees), $matched]);

    return ['success'=>true,'matched'=>$matched];
}

function qbExportTimesheets(mysqli $conn, string $startDate, string $endDate): array {
    $qb = qbGetValidToken($conn);
    if (!$qb) return ['success'=>false,'error'=>'QuickBooks not connected'];

    // Fetch punches with QB employee IDs
    $stmt = $conn->prepare(
        "SELECT tp.*, m.QBEmployeeID
         FROM timepunches tp
         JOIN quickbooks_employee_map m ON tp.EmployeeID = m.EmployeeID
         WHERE DATE(tp.Date) BETWEEN ? AND ? AND tp.TotalHours > 0 AND m.QBEmployeeID != ''"
    );
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $punches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $exported = 0; $failed = 0; $errors = [];

    foreach ($punches as $punch) {
        $hours   = round((float)$punch['TotalHours'], 2);
        $txnDate = date('Y-m-d', strtotime($punch['Date']));

        $payload = json_encode([
            'EmployeeRef'   => ['value' => $punch['QBEmployeeID']],
            'Hours'         => (int)floor($hours),
            'Minutes'       => (int)round(($hours - floor($hours)) * 60),
            'TxnDate'       => $txnDate,
            'BillableStatus'=> 'NotBillable',
            'Description'   => 'FoleyTimePro export',
        ]);

        $ch = curl_init(QB_API_BASE . "/{$qb['RealmID']}/timeactivity");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$qb['AccessToken']}",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200 || $status === 201) { $exported++; }
        else { $failed++; $errors[] = "Punch {$punch['ID']}: HTTP $status"; }
    }

    $notes = $failed > 0 ? implode('; ', array_slice($errors, 0, 3)) : null;
    $conn->prepare("INSERT INTO quickbooks_sync_log (SyncType,StartDate,EndDate,RecordsIn,RecordsOk,RecordsFail,Notes) VALUES('timesheet',?,?,?,?,?,?)")
         ->execute([$startDate, $endDate, count($punches), $exported, $failed, $notes]);

    return ['success'=>true,'exported'=>$exported,'failed'=>$failed];
}
