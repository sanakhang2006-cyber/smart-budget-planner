<?php
// config.php - SmartBudget Pro v3.0 | Enhanced Professional Edition
// Database, Sessions, Email, Security Functions

session_start();

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'smartbudget');
define('DB_USER', 'root');
define('DB_PASS', '');

// App Configuration
define('APP_NAME', 'SmartBudget Pro');
define('APP_URL', 'http://localhost:8080/smartbudget');
define('APP_EMAIL', 'support@smartbudget.com');

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'sanakhang2006@gmail.com');
define('SMTP_PASS', 'mmon lkeo jttl tfsl');
define('SMTP_FROM', 'sanakhang2006@gmail.com');
define('SMTP_FROM_NAME', 'SmartBudget Pro');

// Google OAuth (replace with your own Client ID)
define('GOOGLE_CLIENT_ID', '312309935930-lp52gk4d17hm2rtmjj80tvi13o7q10os.apps.googleusercontent.com');

// Password Reset Token Expiry
define('RESET_TOKEN_EXPIRY', 3600);

// Cookie Settings
define('COOKIE_CONSENT_NAME', 'sb_cookie_consent');
define('COOKIE_VISIT_COUNT', 'sb_visit_count');
define('COOKIE_FIRST_VISIT', 'sb_first_visit');
define('COOKIE_LAST_VISIT', 'sb_last_visit');
define('COOKIE_LIFETIME_LONG', 365 * 24 * 60 * 60);
define('COOKIE_LIFETIME_SHORT', 30 * 24 * 60 * 60);

// Database Connection
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }
    return $db;
}

// ==================== AUTH HELPERS ====================
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['toast'] = ['type' => 'warning', 'msg' => 'Please login to access this page.'];
        header('Location: index.php?page=login');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Access denied. Admin privileges required.'];
        header('Location: index.php?page=dashboard');
        exit;
    }
}

function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// ==================== GOOGLE OAUTH HELPERS ====================
function verifyGoogleToken($id_token) {
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($id_token);
    $response = false;

    // Try cURL first (most reliable on XAMPP/WAMP)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // localhost dev: skip SSL cert verification (XAMPP often lacks CA bundle)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($response === false || $response === '') {
            $_SESSION['google_error'] = 'cURL failed: ' . $curlErr;
        }
    }

    // Fallback: file_get_contents
    if ($response === false || $response === '' || $response === null) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            $_SESSION['google_error'] = 'Could not contact Google. Enable cURL or allow_url_fopen in php.ini.';
            return false;
        }
    }

    $data = json_decode($response, true);
    if (!$data) { $_SESSION['google_error'] = 'Invalid response from Google.'; return false; }
    if (isset($data['error'])) { $_SESSION['google_error'] = 'Google says: ' . ($data['error_description'] ?? $data['error']); return false; }
    if (!isset($data['aud']) || $data['aud'] !== GOOGLE_CLIENT_ID) {
        $_SESSION['google_error'] = 'Client ID mismatch. Token aud="' . ($data['aud'] ?? 'none') . '" vs config="' . GOOGLE_CLIENT_ID . '"';
        return false;
    }
    if (empty($data['email_verified']) && $data['email_verified'] !== 'true') {
        // Some responses return "true" as string
        if ($data['email_verified'] !== true && $data['email_verified'] !== 'true') {
            $_SESSION['google_error'] = 'Email not verified by Google.';
            return false;
        }
    }

    return [
        'google_id' => $data['sub'],
        'email' => $data['email'],
        'name' => $data['name'] ?? explode('@', $data['email'])[0],
        'picture' => $data['picture'] ?? null
    ];
}

function loginOrCreateGoogleUser($userData) {
    $db = getDB();
    $google_id = $userData['google_id'];
    $email = $userData['email'];
    $name = $userData['name'];
    
    // Check if user exists by google_id first
    $stmt = $db->prepare("SELECT * FROM users WHERE google_id = ?");
    $stmt->execute([$google_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Check by email
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Link google_id to existing account
            $stmt = $db->prepare("UPDATE users SET google_id = ? WHERE id = ?");
            $stmt->execute([$google_id, $user['id']]);
        } else {
            // Create new user
            $colors = ['#8b5cf6','#ec4899','#10b981','#3b82f6','#f59e0b','#ef4444','#6366f1','#a855f7'];
            $color = $colors[array_rand($colors)];
            
            $stmt = $db->prepare("INSERT INTO users (name, email, google_id, currency, monthly_goal, avatar_color, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $email, $google_id, 'USD', 0, $color]);
            $user_id = $db->lastInsertId();
            
            // Create default categories for new user
            $cats = [
                ['Salary','income','fa-briefcase','#22c55e'],
                ['Freelance','income','fa-laptop','#3b82f6'],
                ['Investments','income','fa-chart-line','#a855f7'],
                ['Rent','expense','fa-home','#ef4444'],
                ['Groceries','expense','fa-shopping-cart','#f97316'],
                ['Entertainment','expense','fa-gamepad','#ec4899'],
                ['Utilities','expense','fa-bolt','#eab308'],
                ['Transport','expense','fa-car','#6366f1'],
            ];
            $ci = $db->prepare("INSERT INTO categories (user_id,name,type,icon,color) VALUES (?,?,?,?,?)");
            foreach ($cats as $c) $ci->execute([$user_id, ...$c]);
            
            // Send welcome email
            sendWelcomeEmail($email, $name);
            
            $user = $db->prepare("SELECT * FROM users WHERE id = ?")->execute([$user_id])->fetch();
            if (!$user) $user = ['id' => $user_id, 'name' => $name, 'email' => $email, 'currency' => 'USD', 'role' => 'user'];
        }
    }
    
    // Update login info
    $newCount = ($user['login_count'] ?? 0) + 1;
    $db->prepare("UPDATE users SET last_login_at = NOW(), login_count = ? WHERE id = ?")
       ->execute([$newCount, $user['id']]);
    
    return $user;
}

// ==================== COOKIE HELPERS ====================
function isCookieAccepted() {
    return isset($_COOKIE[COOKIE_CONSENT_NAME]) && $_COOKIE[COOKIE_CONSENT_NAME] === 'accepted';
}

function isCookieRejected() {
    return isset($_COOKIE[COOKIE_CONSENT_NAME]) && $_COOKIE[COOKIE_CONSENT_NAME] === 'rejected';
}

function isCookieDecided() {
    return isset($_COOKIE[COOKIE_CONSENT_NAME]);
}

function getVisitCount() {
    return isset($_COOKIE[COOKIE_VISIT_COUNT]) ? (int)$_COOKIE[COOKIE_VISIT_COUNT] : 0;
}

function incrementVisitCount() {
    $count = getVisitCount() + 1;
    setcookie(COOKIE_VISIT_COUNT, $count, time() + COOKIE_LIFETIME_LONG, '/');
    return $count;
}

function getFirstVisitDate() {
    return $_COOKIE[COOKIE_FIRST_VISIT] ?? null;
}

function getLastVisitDate() {
    return $_COOKIE[COOKIE_LAST_VISIT] ?? null;
}

function setVisitCookies() {
    if (!getFirstVisitDate()) {
        setcookie(COOKIE_FIRST_VISIT, date('Y-m-d H:i:s'), time() + COOKIE_LIFETIME_LONG, '/');
    }
    setcookie(COOKIE_LAST_VISIT, date('Y-m-d H:i:s'), time() + COOKIE_LIFETIME_LONG, '/');
}

function acceptCookies() {
    setcookie(COOKIE_CONSENT_NAME, 'accepted', time() + COOKIE_LIFETIME_LONG, '/');
    setVisitCookies();
    incrementVisitCount();
}

function rejectCookies() {
    setcookie(COOKIE_CONSENT_NAME, 'rejected', time() + COOKIE_LIFETIME_SHORT, '/');
}

function clearAllCookies() {
    $cookies = [COOKIE_CONSENT_NAME, COOKIE_VISIT_COUNT, COOKIE_FIRST_VISIT, COOKIE_LAST_VISIT];
    foreach ($cookies as $cookie) {
        setcookie($cookie, '', time() - 3600, '/');
    }
}

// ==================== TOKEN HELPERS ====================
function generateToken($length = 64) {
    return bin2hex(random_bytes($length));
}

function storeResetToken($userId, $token) {
    $db = getDB();
    $expiresAt = date('Y-m-d H:i:s', time() + RESET_TOKEN_EXPIRY);
    $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
    $stmt = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
    return $stmt->execute([$userId, $token, $expiresAt]);
}

function verifyResetToken($token) {
    $db = getDB();
    $stmt = $db->prepare("SELECT user_id FROM password_resets WHERE token = ? AND expires_at > NOW() AND used = 0");
    $stmt->execute([$token]);
    $result = $stmt->fetch();
    if ($result) {
        return $result['user_id'];
    }
    return false;
}

function markTokenUsed($token) {
    $db = getDB();
    $db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")->execute([$token]);
}

// ==================== EMAIL FUNCTIONS ====================
function sendEmail($to, $subject, $body, $isHtml = true) {
    $pmPath = dirname(__DIR__) . '/phpmailer/src/PHPMailer.php';

    if (file_exists($pmPath)) {
        require_once $pmPath;
        require_once dirname(__DIR__) . '/phpmailer/src/SMTP.php';
        require_once dirname(__DIR__) . '/phpmailer/src/Exception.php';

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($body);
            } else {
                $mail->Body = $body;
            }
            return $mail->send();
        } catch (Exception $e) {
            error_log('SmartBudget Email Error: ' . $e->getMessage());
            return false;
        }
    }

    // Fallback: mail()
    $headers   = [];
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8";
    $headers[] = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">";
    $headers[] = "Reply-To: " . APP_EMAIL;
    $headers[] = "X-Mailer: PHP/" . phpversion();
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function getEmailTemplate($title, $content, $buttonText = null, $buttonLink = null) {
    $buttonHtml = '';
    if ($buttonText && $buttonLink) {
        $buttonHtml = "
        <div style='text-align: center; margin: 35px 0;'>
            <a href='{$buttonLink}' 
               style='background: linear-gradient(135deg, #8b5cf6, #ec4899); color: white; padding: 16px 45px; 
                      text-decoration: none; border-radius: 30px; display: inline-block; font-weight: 700; 
                      font-size: 16px; letter-spacing: 0.5px; box-shadow: 0 8px 25px rgba(139,92,246,0.4);'>
                {$buttonText}
            </a>
        </div>";
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style=\"font-family: 'Segoe UI', Arial, sans-serif; background: #0a0a12; color: #e8e8f0; padding: 20px; margin: 0;\">
        <div style='max-width: 600px; margin: 0 auto; background: #12121a; border-radius: 24px; overflow: hidden; border: 1px solid rgba(139,92,246,0.2);'>
            <div style='background: linear-gradient(135deg, #8b5cf6, #ec4899); padding: 30px; text-align: center;'>
                <h1 style='margin: 0; color: white; font-size: 26px; letter-spacing: -0.5px;'>💰 SmartBudget Pro</h1>
                <p style='margin: 8px 0 0 0; color: rgba(255,255,255,0.85); font-size: 14px;'>{$title}</p>
            </div>
            <div style='padding: 35px 30px;'>
                {$content}
                {$buttonHtml}
            </div>
            <div style='border-top: 1px solid rgba(255,255,255,0.08); padding: 25px 30px; text-align: center;'>
                <p style='font-size: 12px; color: #64748b; margin: 0;'>
                    © " . date('Y') . " SmartBudget Pro | <a href='" . APP_URL . "' style='color: #8b5cf6; text-decoration: none;'>Visit Website</a>
                </p>
            </div>
        </div>
    </body>
    </html>";
}

function sendPasswordResetEmail($email, $name, $token) {
    $resetLink = APP_URL . "/index.php?page=reset-password&token=" . urlencode($token);
    $content = "
        <p style='font-size: 17px; margin-bottom: 20px;'>Hello <strong>{$name}</strong>,</p>
        <p style='color: #94a3b8; line-height: 1.7;'>We received a request to reset your password. Click the button below to create a new one.</p>
        <div style='background: rgba(245,158,11,0.1); border-left: 4px solid #f59e0b; padding: 16px; margin: 25px 0; border-radius: 0 12px 12px 0;'>
            <p style='margin: 0; font-size: 14px;'>⏰ <strong>This link expires in 1 hour</strong> for your security.</p>
        </div>
        <p style='font-size: 13px; color: #64748b; margin-top: 25px;'>Or copy this link:<br>
        <span style='color: #8b5cf6; word-break: break-all; font-size: 12px;'>{$resetLink}</span></p>
        <p style='font-size: 13px; color: #64748b;'>If you didn't request this, please ignore this email.</p>";
    
    $body = getEmailTemplate('Password Reset Request', $content, '🔐 Reset My Password', $resetLink);
    return sendEmail($email, "🔐 Reset Your SmartBudget Pro Password", $body);
}

function sendBudgetAlert($userId, $totalExpense, $totalIncome, $overage) {
    $db = getDB();
    $stmt = $db->prepare("SELECT name, email, currency FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return false;
    
    $cur = $user['currency'] === 'PKR' ? 'Rs ' : '$';
    $content = "
        <p style='font-size: 17px;'>Dear <strong>{$user['name']}</strong>,</p>
        <div style='background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); border-radius: 16px; padding: 25px; margin: 25px 0; text-align: center;'>
            <p style='font-size: 14px; color: #f87171; margin: 0 0 10px 0; text-transform: uppercase; letter-spacing: 1px;'>⚠️ Budget Alert</p>
            <p style='font-size: 32px; font-weight: 800; color: #ef4444; margin: 0;'>{$cur}" . number_format($overage, 2) . "</p>
            <p style='color: #94a3b8; margin: 8px 0 0 0;'>Over Budget</p>
        </div>
        <div style='display: flex; gap: 15px; margin: 25px 0;'>
            <div style='flex: 1; background: rgba(16,185,129,0.1); border-radius: 12px; padding: 18px; text-align: center;'>
                <p style='font-size: 12px; color: #94a3b8; margin: 0 0 8px 0;'>Income</p>
                <p style='font-size: 20px; font-weight: 700; color: #10b981; margin: 0;'>{$cur}" . number_format($totalIncome, 2) . "</p>
            </div>
            <div style='flex: 1; background: rgba(239,68,68,0.1); border-radius: 12px; padding: 18px; text-align: center;'>
                <p style='font-size: 12px; color: #94a3b8; margin: 0 0 8px 0;'>Expenses</p>
                <p style='font-size: 20px; font-weight: 700; color: #ef4444; margin: 0;'>{$cur}" . number_format($totalExpense, 2) . "</p>
            </div>
        </div>
        <p style='color: #94a3b8; line-height: 1.7;'>Tips to get back on track:</p>
        <ul style='color: #94a3b8; line-height: 2;'>
            <li>Review your recent expenses</li>
            <li>Look for areas to cut back</li>
            <li>Consider increasing income sources</li>
        </ul>";
    
    $body = getEmailTemplate('Your Expenses Exceed Income!', $content, '📊 Review My Budget', APP_URL . '/index.php?page=dashboard');
    return sendEmail($user['email'], "⚠️ Budget Alert: Expenses Exceed Income!", $body);
}

function sendWelcomeEmail($email, $name) {
    $content = "
        <p style='font-size: 20px; text-align: center; margin-bottom: 25px;'>Welcome aboard, <strong>{$name}</strong>! 🚀</p>
        <div style='background: rgba(16,185,129,0.1); border-left: 4px solid #10b981; padding: 20px; margin: 25px 0; border-radius: 0 12px 12px 0;'>
            <p style='margin: 0; line-height: 1.7;'>Your account has been created successfully! Start tracking your finances like a pro.</p>
        </div>
        <div style='margin: 30px 0;'>
            <p style='font-size: 14px; color: #94a3b8; margin-bottom: 15px;'><strong>What's included:</strong></p>
            <div style='display: grid; gap: 10px;'>
                <div style='padding: 12px 16px; background: rgba(255,255,255,0.03); border-radius: 10px; font-size: 14px;'>✅ Income & Expense Tracking</div>
                <div style='padding: 12px 16px; background: rgba(255,255,255,0.03); border-radius: 10px; font-size: 14px;'>📧 Smart Email Alerts</div>
                <div style='padding: 12px 16px; background: rgba(255,255,255,0.03); border-radius: 10px; font-size: 14px;'>🎯 Savings Goals</div>
                <div style='padding: 12px 16px; background: rgba(255,255,255,0.03); border-radius: 10px; font-size: 14px;'>📊 Beautiful Dashboard Analytics</div>
            </div>
        </div>";
    
    $body = getEmailTemplate('Your Financial Journey Begins!', $content, '🚀 Login to Your Account', APP_URL . '/index.php?page=login');
    return sendEmail($email, "🎉 Welcome to SmartBudget Pro, {$name}!", $body);
}
?>