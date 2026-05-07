<?php
// SmartBudget Google Sign-In Diagnostic Tool
// Just open this in your browser - it will tell you exactly what's wrong

require_once __DIR__ . '/includes/config.php';

function check($label, $ok, $detail) {
    $color = $ok ? '#16a34a' : '#dc2626';
    $icon = $ok ? '✅' : '❌';
    $bg = $ok ? '#dcfce7' : '#fee2e2';
    echo "<div style='background:$bg;border-left:8px solid $color;padding:20px;margin:15px 0;border-radius:8px'>";
    echo "<div style='font-size:22px;font-weight:bold;color:$color'>$icon $label</div>";
    echo "<div style='margin-top:8px;font-size:15px;color:#333;font-family:monospace;word-break:break-all'>$detail</div>";
    echo "</div>";
}
?><!DOCTYPE html>
<html><head><title>SmartBudget Diagnostic</title><style>
body{font-family:system-ui,sans-serif;max-width:800px;margin:30px auto;padding:20px;background:#f9fafb;color:#111}
h1{color:#4338ca}
.fix{background:#fef3c7;border:2px solid #f59e0b;padding:20px;border-radius:8px;margin-top:30px}
</style></head><body>
<h1>🔍 SmartBudget Google Sign-In Diagnostic</h1>
<p style='font-size:16px;color:#666'>This tool checks each part of Google sign-in and tells you what's broken. Take a screenshot of this page and send it.</p>

<?php
// Check 1: cURL extension
check(
    '1. PHP cURL extension',
    function_exists('curl_init'),
    function_exists('curl_init') ? 'cURL is enabled. Good.' : 'cURL is NOT installed in your PHP. This is the problem!'
);

// Check 2: allow_url_fopen
$urlFopen = ini_get('allow_url_fopen');
check(
    '2. PHP allow_url_fopen',
    !empty($urlFopen),
    !empty($urlFopen) ? 'Enabled (value: ' . $urlFopen . ')' : 'Disabled. Backup connection method will not work.'
);

// Check 3: GOOGLE_CLIENT_ID configured
$gcid = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
$gcidOk = !empty($gcid) && strpos($gcid, '.apps.googleusercontent.com') !== false;
check(
    '3. GOOGLE_CLIENT_ID in config.php',
    $gcidOk,
    $gcidOk ? 'Set to: ' . $gcid : ('NOT properly set! Current value: "' . htmlspecialchars($gcid) . '"')
);

// Check 4: Can we reach Google?
$testUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=test';
$reachOk = false;
$reachDetail = '';
if (function_exists('curl_init')) {
    $ch = curl_init($testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp !== false && $resp !== '') {
        $reachOk = true;
        $reachDetail = 'Connected to Google. HTTP code: ' . $httpCode . ' (400 is normal here, means Google replied)';
    } else {
        $reachDetail = 'Could NOT reach Google. cURL error: ' . $err;
    }
} else {
    $reachDetail = 'Cannot test - cURL not installed';
}
check('4. Connection to Google servers', $reachOk, $reachDetail);

// Check 5: Database connection
$dbOk = false; $dbDetail = '';
try {
    $db = getDB();
    $dbOk = true;
    $dbDetail = 'Database connection works.';
} catch (Exception $e) {
    $dbDetail = 'Database error: ' . $e->getMessage();
}
check('5. Database connection', $dbOk, $dbDetail);

// Check 6: google_id column exists
$colOk = false; $colDetail = '';
if ($dbOk) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'google_id'");
        $col = $stmt->fetch();
        if ($col) {
            $colOk = true;
            $colDetail = 'Column "google_id" exists in users table. Type: ' . $col['Type'];
        } else {
            $colDetail = 'Column "google_id" does NOT exist in users table!';
        }
    } catch (Exception $e) {
        $colDetail = 'Error checking column: ' . $e->getMessage();
    }
}
check('6. google_id column in users table', $colOk, $colDetail);

// Check 7: Server URL
$currentUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php';
check(
    '7. Your registered redirect URL should be',
    true,
    $currentUrl . '<br><br>This EXACT URL must be in Google Cloud Console under "Authorized redirect URIs"'
);
?>

<div class='fix'>
<h2>📋 What to do</h2>
<p><b>Take a screenshot of this whole page</b> and send it to me. I will tell you exactly what to fix based on the red ❌ items above.</p>
<p>If everything is green ✅ but Google sign-in still fails, then the issue is in the JavaScript on the login page.</p>
</div>

</body></html>
