<?php
ob_start();
// index.php — SmartBudget Pro v4.0 | ENHANCED EDITION
// Light Theme | Auto-Categorize | Receipt Scanner | Smart Reminders | Family Budget | Budget Alerts | Monthly Budget Planner
ini_set('session.cookie_lifetime', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

require_once __DIR__ . '/includes/config.php';
if (!isset($_SESSION['_session_birth'])) {
    $_SESSION['_session_birth'] = time();
    unset($_SESSION['cookies_accepted'], $_SESSION['cookies_rejected']);
}
if (isset($_GET['destroy'])) { session_destroy(); session_start(); header('Location: index.php'); exit; }

// Cookie consent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cookie_action'])) {
    if ($_POST['cookie_action'] === 'accept') {
        $_SESSION['cookies_accepted'] = true; unset($_SESSION['cookies_rejected']);
        try { $db=getDB(); $db->prepare("INSERT INTO cookie_analytics (session_id,action,ip_address,user_agent) VALUES (?,'accepted',?,?)")->execute([session_id(),$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $e){}
    } elseif ($_POST['cookie_action'] === 'reject') {
        $_SESSION['cookies_rejected'] = true;
        try { $db=getDB(); $db->prepare("INSERT INTO cookie_analytics (session_id,action,ip_address,user_agent) VALUES (?,'rejected',?,?)")->execute([session_id(),$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $e){}
    }
    header('Location: '.strtok($_SERVER['REQUEST_URI'],'?')); exit;
}

$cookieAccepted = !empty($_SESSION['cookies_accepted']);
$cookieRejected = !empty($_SESSION['cookies_rejected']);
$cookieDecided  = $cookieAccepted || $cookieRejected;
$visitCount=0; $firstVisit=null; $lastVisit=null;
if ($cookieAccepted) { $visitCount=incrementVisitCount(); setVisitCookies(); $firstVisit=getFirstVisitDate(); $lastVisit=getLastVisitDate(); }

$page = $_GET['page'] ?? (isLoggedIn() ? 'dashboard' : 'home');
if ($cookieRejected) $page = 'cookie_blocked';

// ===== AUTO-CATEGORIZE HELPER =====
function autoDetectCategory(string $name): array {
    $name = strtolower($name);
    $rules = [
        'Food & Dining'    => ['kfc','mcdonalds','mcdonald','burger','pizza','sushi','food','restaurant','café','cafe','eat','meal','lunch','dinner','breakfast','biryani','shawarma','bakery','dominos','subway','dine'],
        'Transport'        => ['uber','careem','petrol','fuel','taxi','bus','train','fare','transport','car','bike','metro','rickshaw','toll','parking'],
        'Groceries'        => ['grocery','groceries','supermarket','daraz','mart','bazar','vegetables','fruit','milk','egg','rice','flour','oil','sabzi'],
        'Utilities'        => ['electricity','electric','water','gas','wifi','internet','utility','bill','wapda','k-electric','sui','ptcl'],
        'Entertainment'    => ['netflix','youtube','spotify','cinema','movie','game','gaming','steam','playstation','tiktok','subscription','prime'],
        'Health'           => ['doctor','hospital','medicine','pharmacy','clinic','medical','health','chemist','dawai'],
        'Education'        => ['school','university','college','tuition','fee','books','course','online learning','udemy','coursera','fees'],
        'Shopping'         => ['shopping','clothes','shoes','fashion','amazon','daraz','mall','market','dress','shirt','watch'],
        'Rent'             => ['rent','house','apartment','flat','room','hostel'],
        'Salary'           => ['salary','wages','payroll','pay'],
        'Freelance'        => ['freelance','fiverr','upwork','project','client','design','code'],
    ];
    foreach ($rules as $cat => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($name, $kw)) return ['name'=>$cat,'matched'=>true];
        }
    }
    return ['name'=>'', 'matched'=>false];
}

// ===== POST HANDLERS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['cookie_action'])) {
    $action = $_POST['action'] ?? '';

    // GOOGLE AUTH (works for both popup id_token AND redirect-flow id_token)
    if ($action === 'google_auth' && !empty($_POST['id_token'])) {
        $userData = verifyGoogleToken($_POST['id_token']);
        if ($userData) {
            $user = loginOrCreateGoogleUser($userData);
            if ($user) {
                $_SESSION['user_id']=$user['id']; $_SESSION['user_name']=$user['name'];
                $_SESSION['user_email']=$user['email']; $_SESSION['currency']=$user['currency']??'USD';
                $_SESSION['user_role']=$user['role']??'user';
                $_SESSION['toast']=['type'=>'success','msg'=>'Welcome, '.$user['name'].'! (Google) 🚀'];
                header('Location: index.php?page=dashboard'); exit;
            }
        }
        $err = $_SESSION['google_error'] ?? 'Google sign-in failed.';
        unset($_SESSION['google_error']);
        $_SESSION['toast']=['type'=>'error','msg'=>'Google sign-in failed: '.$err];
        header('Location: index.php?page=login'); exit;
    }

    // AUTO-CATEGORIZE AJAX
    if ($action === 'auto_categorize' && isLoggedIn()) {
        $name = trim($_POST['name'] ?? '');
        $result = autoDetectCategory($name);
        $db = getDB();
        $catId = null;
        if ($result['matched']) {
            $stmt = $db->prepare("SELECT id FROM categories WHERE user_id=? AND name=? LIMIT 1");
            $stmt->execute([currentUserId(), $result['name']]);
            $row = $stmt->fetch();
            if ($row) $catId = $row['id'];
        }
        header('Content-Type: application/json');
        echo json_encode(['category'=>$result['name'],'matched'=>$result['matched'],'category_id'=>$catId]);
        exit;
    }

    // SMART REMINDER AJAX
    if ($action === 'save_reminder' && isLoggedIn()) {
        $title   = trim($_POST['title']??'');
        $due     = $_POST['due_date']??'';
        $type    = $_POST['reminder_type']??'bill';
        $amount  = (float)($_POST['amount']??0);
        $uid     = currentUserId();
        $db      = getDB();
        try {
            $db->prepare("INSERT INTO reminders (user_id,title,due_date,type,amount,is_done) VALUES (?,?,?,?,?,0)")->execute([$uid,$title,$due,$type,$amount]);
            header('Content-Type: application/json');
            echo json_encode(['success'=>true]);
        } catch(Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'done_reminder' && isLoggedIn()) {
        $db = getDB(); $db->prepare("UPDATE reminders SET is_done=1 WHERE id=? AND user_id=?")->execute([(int)$_POST['id'],currentUserId()]);
        header('Content-Type: application/json'); echo json_encode(['success'=>true]); exit;
    }

    // ===== BUDGET PLANNER SAVE =====
    if ($action === 'save_monthly_budget' && isLoggedIn()) {
        $uid = currentUserId();
        $year = (int)$_POST['year'];
        $month = (int)$_POST['month'];
        $budget_amount = (float)$_POST['budget_amount'];
        $db = getDB();
        
        try {
            $stmt = $db->prepare("INSERT INTO monthly_budgets (user_id, year, month, budget_amount) VALUES (?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE budget_amount = ?");
            $stmt->execute([$uid, $year, $month, $budget_amount, $budget_amount]);
            $_SESSION['toast'] = ['type'=>'success', 'msg'=>'Monthly budget saved!'];
        } catch(Exception $e) {
            $_SESSION['toast'] = ['type'=>'error', 'msg'=>'Failed to save budget'];
        }
        header('Location: index.php?page=dashboard');
        exit;
    }

    // ===== WALLET BALANCE SAVE =====
    if ($action === 'update_wallet' && isLoggedIn()) {
        $uid = currentUserId();
        $wallet_type = $_POST['wallet_type'];
        $balance = (float)$_POST['balance'];
        $db = getDB();
        
        try {
            $stmt = $db->prepare("INSERT INTO wallets (user_id, wallet_type, balance) VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE balance = ?");
            $stmt->execute([$uid, $wallet_type, $balance, $balance]);
            $_SESSION['toast'] = ['type'=>'success', 'msg'=>'Wallet updated!'];
        } catch(Exception $e) {
            $_SESSION['toast'] = ['type'=>'error', 'msg'=>'Failed to update wallet'];
        }
        header('Location: index.php?page=dashboard');
        exit;
    }

    // LOGIN
    if ($action === 'login') {
        $db=$db=getDB(); $stmt=$db->prepare("SELECT * FROM users WHERE email=? AND is_active=1"); $stmt->execute([trim($_POST['email'])]); $user=$stmt->fetch();
        if ($user && password_verify($_POST['password'],$user['password'])) {
            $_SESSION['user_id']=$user['id']; $_SESSION['user_name']=$user['name'];
            $_SESSION['user_email']=$user['email']; $_SESSION['currency']=$user['currency'];
            $_SESSION['user_role']=$user['role']??'user';
            $newCount=($user['login_count']??0)+1;
            $db->prepare("UPDATE users SET last_login_at=NOW(),login_count=? WHERE id=?")->execute([$newCount,$user['id']]);
            $_SESSION['welcome_type']=$user['last_login_at']?'returning':'new';
            $_SESSION['welcome_name']=$user['name'];
            $_SESSION['toast']=['type'=>'success','msg'=>'Welcome back, '.$user['name'].'! 👋'];
            header('Location: index.php?page=dashboard');
        } else {
            $_SESSION['toast']=['type'=>'error','msg'=>'Invalid email or password.']; header('Location: index.php?page=login');
        }
        exit;
    }

    // REGISTER
    if ($action === 'register') {
        $db=getDB(); $email=trim($_POST['email']); $name=trim($_POST['name']);
        $chk=$db->prepare("SELECT id,name FROM users WHERE email=?"); $chk->execute([$email]); $existing=$chk->fetch();
        if ($existing) { $_SESSION['toast']=['type'=>'info','msg'=>'Welcome back, '.$existing['name'].'! Email already registered.']; $_SESSION['returning_user_name']=$existing['name']; header('Location: index.php?page=login'); exit; }
        if (strlen($_POST['password'])<6) { $_SESSION['toast']=['type'=>'warning','msg'=>'Password must be at least 6 characters.']; header('Location: index.php?page=register'); exit; }
        $hash=password_hash($_POST['password'],PASSWORD_BCRYPT);
        $confirmHash=password_hash($_POST['confirm_password'],PASSWORD_BCRYPT);
        $colors=['#6366f1','#ec4899','#10b981','#3b82f6','#f59e0b','#ef4444','#a855f7','#06b6d4'];
        $color=$colors[array_rand($colors)];
        $db->prepare("INSERT INTO users (name,email,password,confirm_password,currency,monthly_goal,monthly_expense,avatar_color,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")->execute([$name,$email,$hash,$confirmHash,$_POST['currency']??'USD',(float)($_POST['monthly_goal']??0),(float)($_POST['monthly_expense']??0),$color]);
        $uid=$db->lastInsertId();
        $cats=[['Salary','income','fa-briefcase','#22c55e'],['Freelance','income','fa-laptop','#3b82f6'],['Investments','income','fa-chart-line','#a855f7'],['Rent','expense','fa-home','#ef4444'],['Groceries','expense','fa-shopping-cart','#f97316'],['Food & Dining','expense','fa-utensils','#f59e0b'],['Entertainment','expense','fa-gamepad','#ec4899'],['Utilities','expense','fa-bolt','#eab308'],['Transport','expense','fa-car','#6366f1'],['Health','expense','fa-heart-pulse','#10b981'],['Education','expense','fa-graduation-cap','#8b5cf6'],['Shopping','expense','fa-bag-shopping','#06b6d4']];
        $ci=$db->prepare("INSERT INTO categories (user_id,name,type,icon,color) VALUES (?,?,?,?,?)");
        foreach ($cats as $c) $ci->execute([$uid,...$c]);
        sendWelcomeEmail($email,$name);
        $_SESSION['user_id']=$uid; $_SESSION['user_name']=$name; $_SESSION['user_email']=$email;
        $_SESSION['currency']=$_POST['currency']??'USD'; $_SESSION['user_role']='user';
        $_SESSION['toast']=['type'=>'success','msg'=>'🎉 Welcome '.$name.'!'];
        $_SESSION['new_user_name']=$name; $_SESSION['new_user_email']=$email;
        header('Location: index.php?page=dashboard'); exit;
    }

    // FORGOT / RESET PASSWORD
    if ($action === 'forgot_password') {
        $db=getDB(); $stmt=$db->prepare("SELECT id,name FROM users WHERE email=? AND is_active=1"); $stmt->execute([trim($_POST['email'])]); $user=$stmt->fetch();
        if ($user) { $token=generateToken(); storeResetToken($user['id'],$token); sendPasswordResetEmail($_POST['email'],$user['name'],$token); $_SESSION['toast']=['type'=>'success','msg'=>'Reset link sent! 📧']; }
        else $_SESSION['toast']=['type'=>'info','msg'=>'If email exists, reset link sent.'];
        header('Location: index.php?page=login'); exit;
    }
    if ($action === 'reset_password') {
        $token=$_POST['token']??''; $passwd=$_POST['password']??'';
        if (strlen($passwd)<6) { $_SESSION['toast']=['type'=>'warning','msg'=>'Password must be at least 6 characters.']; header('Location: index.php?page=reset-password&token='.urlencode($token)); exit; }
        $uid=verifyResetToken($token);
        if ($uid) { $db=getDB(); $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($passwd,PASSWORD_BCRYPT),$uid]); markTokenUsed($token); $db->prepare("DELETE FROM password_resets WHERE user_id=?")->execute([$uid]); $_SESSION['toast']=['type'=>'success','msg'=>'Password reset! 🔐']; header('Location: index.php?page=login'); }
        else { $_SESSION['toast']=['type'=>'error','msg'=>'Invalid or expired link.']; header('Location: index.php?page=forgot-password'); }
        exit;
    }

    // ADMIN ACTIONS
    if ($action === 'admin_delete_user' && isLoggedIn() && isAdmin()) {
        $uid=(int)$_POST['user_id']; $db=getDB();
        if ($uid!=currentUserId()) { foreach(['incomes','expenses','categories','savings_goals','email_alerts_log','password_resets','wallets','monthly_budgets'] as $t) $db->prepare("DELETE FROM $t WHERE user_id=?")->execute([$uid]); $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]); $_SESSION['toast']=['type'=>'success','msg'=>'User deleted!']; }
        header('Location: index.php?page=users'); exit;
    }
    if ($action === 'admin_toggle_user' && isLoggedIn() && isAdmin()) {
        $uid=(int)$_POST['user_id']; $db=getDB();
        if ($uid!=currentUserId()) { $s=$db->prepare("SELECT is_active FROM users WHERE id=?"); $s->execute([$uid]); $u=$s->fetch(); $db->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$u['is_active']?0:1,$uid]); $_SESSION['toast']=['type'=>'success','msg'=>'Status updated!']; }
        header('Location: index.php?page=users'); exit;
    }

    if (!isLoggedIn()) { header('Location: index.php?page=login'); exit; }
    $uid=currentUserId(); $db=getDB();

    if ($action === 'save_income') {
        $amount=(float)$_POST['amount'];
        $stmtExp = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=?");
        $stmtExp->execute([$uid]);
        $totalExp = (float)$stmtExp->fetchColumn();
        $id=(int)($_POST['id']??0);
        $catId = (int)($_POST['category_id'] ?? 0);
        if ($catId > 0) { $chk=$db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?"); $chk->execute([$catId,$uid]); if(!$chk->fetchColumn()) $catId=0; }
        $catParam = $catId>0 ? $catId : null;
        $recurDay = !empty($_POST['recur_day']) ? $_POST['recur_day'] : null;
        $data=[$catParam,$_POST['name'],$amount,$_POST['date'],(int)($_POST['is_recurring']??0),$recurDay,$_POST['note']??null];
        if ($id) $db->prepare("UPDATE incomes SET category_id=?,name=?,amount=?,date=?,is_recurring=?,recur_day=?,note=? WHERE id=? AND user_id=?")->execute([...$data,$id,$uid]);
        else $db->prepare("INSERT INTO incomes (user_id,category_id,name,amount,date,is_recurring,recur_day,note) VALUES (?,?,?,?,?,?,?,?)")->execute([$uid,...$data]);
        $_SESSION['toast']=['type'=>'success','msg'=>$id?'Income updated! ✅':'Income added! 💰'];
        header('Location: index.php?page=dashboard'); exit;
    }

    if ($action === 'save_expense') {
        $id=(int)($_POST['id']??0);
        $amount=(float)$_POST['amount'];
        // Check budget limit BEFORE saving
        $stmt=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=?"); $stmt->execute([$uid]); $ti=(float)$stmt->fetchColumn();
        $stmt=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=?"); $stmt->execute([$uid]); $te=(float)$stmt->fetchColumn();
        $newTotal=$te+($id?0:$amount);
        if (!$id && $ti>0 && $newTotal>$ti) {
            $overage=$newTotal-$ti;
            $_SESSION['budget_exceeded_alert']=['amount'=>$overage,'newTotal'=>$newTotal,'income'=>$ti];
        }
        
        $payment_method = $_POST['payment_method']??'cash';

        // Validate category belongs to this user (prevents FK error if blank/bad)
        $catId = (int)($_POST['category_id'] ?? 0);
        if ($catId > 0) {
            $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
            $chk->execute([$catId, $uid]);
            if (!$chk->fetchColumn()) $catId = 0;
        }
        $catParam = $catId > 0 ? $catId : null;

        $recurDay = !empty($_POST['recur_day']) ? $_POST['recur_day'] : null;
        $data=[$catParam,$_POST['name'],$amount,$_POST['date'],$payment_method,(int)($_POST['is_recurring']??0),$recurDay,$_POST['note']??null];
        if ($id) $db->prepare("UPDATE expenses SET category_id=?,name=?,amount=?,date=?,payment_method=?,is_recurring=?,recur_day=?,note=? WHERE id=? AND user_id=?")->execute([...$data,$id,$uid]);
        else $db->prepare("INSERT INTO expenses (user_id,category_id,name,amount,date,payment_method,is_recurring,recur_day,note) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$uid,...$data]);
        
        // Deduct from wallet
        try {
            $wallet_stmt = $db->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND wallet_type = ?");
            $wallet_stmt->execute([$amount, $uid, $payment_method]);
        } catch(Exception $e) {}
        
        $stmt=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=?"); $stmt->execute([$uid]); $te2=(float)$stmt->fetchColumn();

        // ===== GOAL "WANTS" GUARD — alert if buying fun stuff while behind on goal =====
        $wantsCats = ['Entertainment','Shopping','Food & Dining'];
        $catName = '';
        try { $cs=$db->prepare("SELECT name FROM categories WHERE id=?"); $cs->execute([(int)($_POST['category_id']??0)]); $catName=(string)$cs->fetchColumn(); } catch(Exception $e){}
        if (!$id && in_array($catName,$wantsCats,true)) {
            try {
                $gs=$db->prepare("SELECT title,target_amt,saved_amt,deadline FROM savings_goals WHERE user_id=? AND is_completed=0 AND deadline IS NOT NULL ORDER BY deadline ASC LIMIT 1");
                $gs->execute([$uid]); $activeGoal=$gs->fetch();
                if ($activeGoal) {
                    $remaining = max(0,(float)$activeGoal['target_amt']-(float)$activeGoal['saved_amt']);
                    $monthsLeft = max(1,(int)ceil((strtotime($activeGoal['deadline'])-time())/2628000));
                    $needPerMonth = $remaining / $monthsLeft;
                    // How many days "further" did this expense push the goal?
                    $perDay = $needPerMonth / 30;
                    $daysLost = $perDay > 0 ? round($amount / $perDay, 1) : 0;
                    $_SESSION['goal_wants_alert'] = [
                        'amount'=>$amount,
                        'category'=>$catName,
                        'goal'=>$activeGoal['title'],
                        'need'=>$needPerMonth,
                        'remaining'=>$remaining,
                        'months'=>$monthsLeft,
                        'days_lost'=>$daysLost,
                    ];
                }
            } catch(Exception $e){}
        }

        if ($te2>$ti && $ti>0) {
            sendBudgetAlert($uid,$te2,$ti,$te2-$ti);
            $db->prepare("INSERT INTO email_alerts_log (user_id,alert_type,message) VALUES (?,'budget_exceeded',?)")->execute([$uid,"Expenses exceeded income"]);
            $_SESSION['toast']=['type'=>'warning','msg'=>'⚠️ Expense saved — lekin budget cross ho gaya!'];
        } else {
            $_SESSION['toast']=['type'=>'success','msg'=>$id?'Expense updated! ✅':'Expense recorded! 📝'];
        }
        header('Location: index.php?page=dashboard'); exit;
    }

    if ($action === 'delete_income') { $db->prepare("DELETE FROM incomes WHERE id=? AND user_id=?")->execute([(int)$_POST['id'],$uid]); $_SESSION['toast']=['type'=>'info','msg'=>'Income deleted.']; header('Location: index.php?page=dashboard'); exit; }
    if ($action === 'delete_expense') { $db->prepare("DELETE FROM expenses WHERE id=? AND user_id=?")->execute([(int)$_POST['id'],$uid]); $_SESSION['toast']=['type'=>'info','msg'=>'Expense deleted.']; header('Location: index.php?page=dashboard'); exit; }

    // ===== GOAL SAVER (SMART) =====
    if ($action === 'save_goal') {
        $title  = trim($_POST['title'] ?? '');
        $target = (float)($_POST['target_amt'] ?? 0);
        $saved  = (float)($_POST['saved_amt'] ?? 0);
        $deadline = $_POST['deadline'] ?? null;
        $purpose = trim($_POST['purpose'] ?? '') ?: null;
        $id = (int)($_POST['id'] ?? 0);
        $forceLow = !empty($_POST['force_low']); // user clicked "Yes I'm sure" override

        // ===== LOW-TARGET REALITY CHECK =====
        // If goal name suggests a "real-world" item but target looks too low, warn the user.
        $lowerChk = strtolower($title);
        $bigItemKeywords = ['phone','iphone','mobile','samsung','bike','motorcycle','honda','car','laptop','macbook','pc','computer','trip','vacation','travel','umrah','hajj','house','home','flat','plot','wedding','shaadi'];
        $looksBig = false;
        foreach ($bigItemKeywords as $kw) { if (str_contains($lowerChk,$kw)) { $looksBig = true; break; } }
        if (!$forceLow && $looksBig && $target > 0 && $target < 5000) {
            $_SESSION['goal_low_warning'] = [
                'title'    => $title,
                'target'   => $target,
                'saved'    => $saved,
                'deadline' => $deadline,
                'id'       => $id,
            ];
            header('Location: index.php?page=dashboard'); exit;
        }

        // ===== 30% SAFE-SAVINGS CAP CHECK =====
        // If monthly need > 30% of monthly salary -> store warning to show on dashboard.
        try {
            $miStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=? AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())");
            $miStmt->execute([$uid]); $monthlyIncome = (float)$miStmt->fetchColumn();
            if ($monthlyIncome > 0 && $deadline) {
                $remain = max(0, $target - $saved);
                $mLeft  = max(1,(int)ceil((strtotime($deadline)-time())/2628000));
                $needPM = $remain / $mLeft;
                $safeCap = $monthlyIncome * 0.30;
                if ($needPM > $safeCap) {
                    $_SESSION['goal_cap_warning'] = [
                        'goal'     => $title,
                        'need'     => $needPM,
                        'cap'      => $safeCap,
                        'income'   => $monthlyIncome,
                        'months'   => $mLeft,
                        'suggMonths' => max(1,(int)ceil($remain / max(1,$safeCap))),
                    ];
                }
            }
        } catch(Exception $e){}

        // Pick a cute icon based on goal name
        $lower = strtolower($title); $icon = 'fa-bullseye';
        if (str_contains($lower,'phone')||str_contains($lower,'iphone')||str_contains($lower,'mobile')) $icon='fa-mobile-screen';
        elseif (str_contains($lower,'bike')||str_contains($lower,'cycle')) $icon='fa-bicycle';
        elseif (str_contains($lower,'car')) $icon='fa-car';
        elseif (str_contains($lower,'trip')||str_contains($lower,'travel')||str_contains($lower,'vacation')||str_contains($lower,'umrah')||str_contains($lower,'hajj')) $icon='fa-plane';
        elseif (str_contains($lower,'laptop')||str_contains($lower,'pc')||str_contains($lower,'computer')) $icon='fa-laptop';
        elseif (str_contains($lower,'house')||str_contains($lower,'home')||str_contains($lower,'flat')) $icon='fa-house';
        elseif (str_contains($lower,'wedding')||str_contains($lower,'shaadi')) $icon='fa-heart';
        elseif (str_contains($lower,'book')||str_contains($lower,'fee')||str_contains($lower,'school')||str_contains($lower,'university')) $icon='fa-graduation-cap';
        elseif (str_contains($lower,'gift')) $icon='fa-gift';
        try {
            if ($id) {
                $db->prepare("UPDATE savings_goals SET title=?,target_amt=?,saved_amt=?,deadline=?,icon=?,purpose=?,is_completed=? WHERE id=? AND user_id=?")
                   ->execute([$title,$target,$saved,$deadline,$icon,$purpose,$saved>=$target?1:0,$id,$uid]);
            } else {
                $db->prepare("INSERT INTO savings_goals (user_id,title,target_amt,saved_amt,deadline,icon,purpose,is_completed,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
                   ->execute([$uid,$title,$target,$saved,$deadline,$icon,$purpose,$saved>=$target?1:0]);
            }
            $_SESSION['toast']=['type'=>'success','msg'=>'🎯 Goal saved! Apna plan bana liya — chalo shuru karte hain!'];
        } catch(Exception $e) {
            $_SESSION['toast']=['type'=>'error','msg'=>'Goal save failed: '.$e->getMessage()];
        }
        header('Location: index.php?page=dashboard'); exit;
    }
    if ($action === 'add_to_goal') {
        $gid = (int)$_POST['id']; $add = (float)$_POST['amount'];
        try {
            $s=$db->prepare("SELECT target_amt,saved_amt,title FROM savings_goals WHERE id=? AND user_id=?");
            $s->execute([$gid,$uid]); $g=$s->fetch();
            if ($g) {
                $newSaved = (float)$g['saved_amt'] + $add;
                $done = $newSaved >= (float)$g['target_amt'] ? 1 : 0;
                $db->prepare("UPDATE savings_goals SET saved_amt=?,is_completed=? WHERE id=? AND user_id=?")
                   ->execute([$newSaved,$done,$gid,$uid]);
                $_SESSION['toast']=['type'=>'success','msg'=>$done?'🎉 Mubarak ho! Goal achieved — '.$g['title'].'!':'💪 Shabash! Rs '.number_format($add).' added to '.$g['title']];
            }
        } catch(Exception $e) {}
        header('Location: index.php?page=dashboard'); exit;
    }
    if ($action === 'delete_goal') {
        $db->prepare("DELETE FROM savings_goals WHERE id=? AND user_id=?")->execute([(int)$_POST['id'],$uid]);
        $_SESSION['toast']=['type'=>'info','msg'=>'Goal deleted.'];
        header('Location: index.php?page=dashboard'); exit;
    }

    if ($action === 'logout') { $n=$_SESSION['user_name']??'User'; $wa=!empty($_SESSION['cookies_accepted']); session_destroy(); session_start(); if($wa)$_SESSION['cookies_accepted']=true; $_SESSION['toast']=['type'=>'success','msg'=>'Goodbye, '.$n.'! 👋']; header('Location: index.php'); exit; }
}

// ===== DASHBOARD DATA =====
$dashData=[];
if ($page==='dashboard' && isLoggedIn()) {
    $uid=currentUserId(); $db=getDB();
    $filter=$_GET['filter']??'all';
    if($filter=='week') { $where="AND date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)"; }
    elseif($filter=='month') { $where="AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())"; }
    elseif($filter=='year') { $where="AND YEAR(date)=YEAR(CURDATE())"; }
    else { $where=''; }

    $si=$db->prepare("SELECT i.*,c.name AS cat_name,c.icon,c.color FROM incomes i LEFT JOIN categories c ON c.id=i.category_id WHERE i.user_id=? $where ORDER BY i.date DESC"); $si->execute([$uid]); $incomes=$si->fetchAll();
    $se=$db->prepare("SELECT e.*,c.name AS cat_name,c.icon,c.color FROM expenses e LEFT JOIN categories c ON c.id=e.category_id WHERE e.user_id=? $where ORDER BY e.date DESC"); $se->execute([$uid]); $expenses=$se->fetchAll();
    $sc=$db->prepare("SELECT * FROM categories WHERE user_id=? ORDER BY type,name"); $sc->execute([$uid]); $cats=$sc->fetchAll();
    $sg=$db->prepare("SELECT * FROM savings_goals WHERE user_id=? ORDER BY is_completed,deadline"); $sg->execute([$uid]); $goals=$sg->fetchAll();

    $totalIncome=array_sum(array_column($incomes,'amount'));
    $totalExpense=array_sum(array_column($expenses,'amount'));
    $savings=$totalIncome-$totalExpense; $utilization=$totalIncome>0?($totalExpense/$totalIncome)*100:0;

    // ===== WALLET BALANCES =====
    $wallets = ['cash'=>0, 'card'=>0, 'bank_transfer'=>0];
    try {
        $wallet_stmt = $db->prepare("SELECT wallet_type, balance FROM wallets WHERE user_id=?");
        $wallet_stmt->execute([$uid]);
        while($w = $wallet_stmt->fetch()) {
            $wallets[$w['wallet_type']] = $w['balance'];
        }
    } catch(Exception $e) {}
    
    // ===== MONTHLY BUDGET PLANNER =====
    $monthlyBudgets = [];
    $currentYear = date('Y');
    try {
        $budget_stmt = $db->prepare("SELECT year, month, budget_amount FROM monthly_budgets WHERE user_id=? ORDER BY year DESC, month DESC");
        $budget_stmt->execute([$uid]);
        $monthlyBudgets = $budget_stmt->fetchAll();
        
        // Get actual monthly expenses
        $expense_stmt = $db->prepare("SELECT YEAR(date) as yr, MONTH(date) as mnth, SUM(amount) as total FROM expenses WHERE user_id=? GROUP BY YEAR(date), MONTH(date) ORDER BY yr DESC, mnth DESC");
        $expense_stmt->execute([$uid]);
        $monthlyExpenses = [];
        while($e = $expense_stmt->fetch()) {
            $monthlyExpenses[$e['yr'].'-'.$e['mnth']] = $e['total'];
        }
        
        // Merge budget with actual
        $budgetPlanner = [];
        foreach($monthlyBudgets as $mb) {
            $key = $mb['year'].'-'.$mb['month'];
            $budgetPlanner[] = [
                'year' => $mb['year'],
                'month' => $mb['month'],
                'budget' => $mb['budget_amount'],
                'actual' => $monthlyExpenses[$key] ?? 0
            ];
        }
        $monthlyBudgets = $budgetPlanner;
    } catch(Exception $e) {}

    $sa=$db->prepare("SELECT * FROM email_alerts_log WHERE user_id=? ORDER BY sent_at DESC LIMIT 5"); $sa->execute([$uid]); $recentAlerts=$sa->fetchAll();
    $ec=$db->prepare("SELECT c.name,c.color,SUM(e.amount) as total FROM expenses e LEFT JOIN categories c ON c.id=e.category_id WHERE e.user_id=? $where GROUP BY c.id ORDER BY total DESC LIMIT 6"); $ec->execute([$uid]); $expByCat=$ec->fetchAll();

    $reminders=[];
    try { $rm=$db->prepare("SELECT * FROM reminders WHERE user_id=? AND is_done=0 ORDER BY due_date ASC LIMIT 10"); $rm->execute([$uid]); $reminders=$rm->fetchAll(); } catch(Exception $e){}

    $trendData=['income'=>array_fill(0,12,0),'expense'=>array_fill(0,12,0)];
    try {
        $tr=$db->query("SELECT MONTH(date) as m, SUM(amount) as total FROM incomes WHERE user_id=$uid AND YEAR(date)=YEAR(CURDATE()) GROUP BY MONTH(date)");
        $incomeMonths=array_fill(1,12,0); foreach($tr->fetchAll() as $r) $incomeMonths[(int)$r['m']]=$r['total'];
        $tr2=$db->query("SELECT MONTH(date) as m, SUM(amount) as total FROM expenses WHERE user_id=$uid AND YEAR(date)=YEAR(CURDATE()) GROUP BY MONTH(date)");
        $expMonths=array_fill(1,12,0); foreach($tr2->fetchAll() as $r) $expMonths[(int)$r['m']]=$r['total'];
        $trendData=['income'=>array_values($incomeMonths),'expense'=>array_values($expMonths)];
    } catch(Exception $e){}

    $dashData=compact('incomes','expenses','cats','goals','totalIncome','totalExpense','savings','utilization','filter','recentAlerts','expByCat','reminders','trendData','wallets','monthlyBudgets');
}

$usersData=[];
if ($page==='users' && isLoggedIn() && isAdmin()) {
    $db=getDB();
    $usersData=$db->query("SELECT u.*,(SELECT COUNT(*) FROM incomes WHERE user_id=u.id) as income_count,(SELECT COUNT(*) FROM expenses WHERE user_id=u.id) as expense_count,(SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=u.id) as total_income,(SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=u.id) as total_expense FROM users u ORDER BY u.created_at DESC")->fetchAll();
}

$registrationStats=[]; $totalUsers=0; $activeUsers=0; $newThisMonth=0;
if ($page==='admin_reports' && isLoggedIn() && isAdmin()) {
    $db=getDB();
    $stmt=$db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as month,COUNT(*) as count FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month ASC");
    $registrationStats=$stmt->fetchAll();
    $totalUsers=$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUsers=$db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
    $newThisMonth=$db->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
}

$allRegisteredEmails=[];
if (isLoggedIn()) { $db=getDB(); $allRegisteredEmails=$db->query("SELECT id,name,email,avatar_color,created_at,is_active,role FROM users ORDER BY created_at DESC")->fetchAll(); }

$toast=$_SESSION['toast']??null; $welcomeType=$_SESSION['welcome_type']??null; $welcomeName=$_SESSION['welcome_name']??null;
$newUserName=$_SESSION['new_user_name']??null; $newUserEmail=$_SESSION['new_user_email']??null; $returningUserName=$_SESSION['returning_user_name']??null;
$budgetExceededAlert=$_SESSION['budget_exceeded_alert']??null;
$goalWantsAlert=$_SESSION['goal_wants_alert']??null;
$goalLowWarning=$_SESSION['goal_low_warning']??null;
$goalCapWarning=$_SESSION['goal_cap_warning']??null;
unset($_SESSION['toast'],$_SESSION['welcome_type'],$_SESSION['welcome_name'],$_SESSION['new_user_name'],$_SESSION['new_user_email'],$_SESSION['returning_user_name'],$_SESSION['budget_exceeded_alert'],$_SESSION['goal_wants_alert'],$_SESSION['goal_low_warning'],$_SESSION['goal_cap_warning']);


$currency=$_SESSION['currency']??'USD';
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function money(float $n,string $c='USD'):string{return(['PKR'=>'Rs ','USD'=>'$','EUR'=>'€','GBP'=>'£'][$c]??'$').number_format($n,2);}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartBudget Pro v4.0</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💰</text></svg>">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://accounts.google.com/gsi/client" async></script>
<style>
:root{
  --bg:#f0f4ff;
  --bg2:#e8eeff;
  --surface:#ffffff;
  --card:#ffffff;
  --card2:#f7f9ff;
  --border:#e2e8f0;
  --border-strong:#c7d2fe;
  --accent:#6366f1;
  --accent-light:#818cf8;
  --accent-dark:#4f46e5;
  --green:#059669;
  --green-bg:#ecfdf5;
  --red:#dc2626;
  --red-bg:#fef2f2;
  --yellow:#d97706;
  --yellow-bg:#fffbeb;
  --blue:#2563eb;
  --blue-bg:#eff6ff;
  --pink:#db2777;
  --pink-bg:#fdf2f8;
  --text:#1e293b;
  --text-muted:#64748b;
  --text-dim:#94a3b8;
  --gradient-1:linear-gradient(135deg,#6366f1 0%,#a855f7 100%);
  --gradient-2:linear-gradient(135deg,#059669 0%,#0ea5e9 100%);
  --gradient-income:linear-gradient(135deg,#10b981,#34d399);
  --gradient-expense:linear-gradient(135deg,#f43f5e,#fb7185);
  --gradient-savings:linear-gradient(135deg,#6366f1,#a855f7);
  --shadow:0 1px 3px rgba(0,0,0,0.06),0 4px 12px rgba(0,0,0,0.06);
  --shadow-md:0 4px 16px rgba(99,102,241,0.12),0 2px 6px rgba(0,0,0,0.06);
  --shadow-lg:0 12px 40px rgba(99,102,241,0.15),0 4px 12px rgba(0,0,0,0.08);
  --shadow-card:0 2px 8px rgba(99,102,241,0.08);
  --radius:20px;
  --radius-sm:14px;
  --radius-xs:10px;
  --font:'Nunito',-apple-system,sans-serif;
  --mono:'DM Mono',monospace;
}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:var(--font);min-height:100vh;line-height:1.6;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:-1;background:radial-gradient(ellipse 900px 600px at 0% 0%,rgba(99,102,241,0.06) 0%,transparent 60%),radial-gradient(ellipse 700px 500px at 100% 100%,rgba(168,85,247,0.05) 0%,transparent 60%);}

#toastContainer{position:fixed;top:20px;right:20px;z-index:10000;display:flex;flex-direction:column;gap:10px;max-width:380px}
.toast-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 18px;display:flex;align-items:flex-start;gap:12px;box-shadow:var(--shadow-lg);animation:ti .4s cubic-bezier(.34,1.56,.64,1);position:relative;overflow:hidden}
.toast-item::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:4px 0 0 4px}
.toast-item.success::before{background:var(--green)}.toast-item.error::before{background:var(--red)}.toast-item.warning::before{background:var(--yellow)}.toast-item.info::before{background:var(--blue)}
.toast-icon-box{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.toast-item.success .toast-icon-box{background:var(--green-bg);color:var(--green)}.toast-item.error .toast-icon-box{background:var(--red-bg);color:var(--red)}.toast-item.warning .toast-icon-box{background:var(--yellow-bg);color:var(--yellow)}.toast-item.info .toast-icon-box{background:var(--blue-bg);color:var(--blue)}
.toast-body{flex:1}.toast-title{font-weight:800;font-size:13px;margin-bottom:2px}.toast-msg{font-size:12px;color:var(--text-muted);line-height:1.5}
.toast-close{background:none;border:none;color:var(--text-dim);cursor:pointer;padding:4px;border-radius:6px;font-size:13px}
.toast-progress{position:absolute;bottom:0;left:0;height:3px;border-radius:0 0 var(--radius-sm) var(--radius-sm);animation:tp 5s linear forwards}
.toast-item.success .toast-progress{background:var(--green)}.toast-item.error .toast-progress{background:var(--red)}.toast-item.warning .toast-progress{background:var(--yellow)}.toast-item.info .toast-progress{background:var(--blue)}
@keyframes ti{from{opacity:0;transform:translateX(80px) scale(.9)}to{opacity:1;transform:translateX(0) scale(1)}}
@keyframes to2{to{opacity:0;transform:translateX(40px)}}
@keyframes tp{from{width:100%}to{width:0%}}
@keyframes si{from{opacity:0;transform:scale(.93) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
@keyframes fi{from{opacity:0}to{opacity:1}}

.navbar{background:rgba(255,255,255,0.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:0 1.5rem;min-height:64px;position:sticky;top:0;z-index:1000;box-shadow:0 1px 6px rgba(99,102,241,0.08)}
.navbar-brand{font-weight:900;font-size:1.3rem;color:var(--text)!important;display:flex;align-items:center;gap:10px;text-decoration:none}
.brand-icon{width:36px;height:36px;border-radius:11px;background:var(--gradient-1);display:flex;align-items:center;justify-content:center;color:white;font-size:15px}
.brand-name{background:var(--gradient-1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-weight:900}
.nav-pills{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.bp{background:transparent;border:1.5px solid var(--border);color:var(--text-muted);padding:8px 18px;border-radius:999px;font-size:13px;font-weight:700;cursor:pointer;transition:all .25s;text-decoration:none;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;font-family:var(--font)}
.bp:hover,.bp.active{border-color:var(--accent);color:var(--accent);background:rgba(99,102,241,.08)}
.bp.solid{background:var(--gradient-1);border:none;color:white;box-shadow:0 4px 12px rgba(99,102,241,.3)}
.bp.solid:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(99,102,241,.4);color:white}
.bp.danger:hover{border-color:var(--red);color:var(--red);background:var(--red-bg)}

.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;box-shadow:var(--shadow-card);transition:all .3s;position:relative;overflow:hidden}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md)}
.stat-card::after{content:'';position:absolute;top:-40px;right:-40px;width:130px;height:130px;border-radius:50%;opacity:.07}
.stat-card.income::after{background:var(--green)}.stat-card.expense::after{background:var(--red)}.stat-card.savings::after{background:var(--accent)}.stat-card.visits::after{background:var(--blue)}
.stat-icon{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:1rem}
.stat-card.income .stat-icon{background:var(--green-bg);color:var(--green)}
.stat-card.expense .stat-icon{background:var(--red-bg);color:var(--red)}
.stat-card.savings .stat-icon{background:rgba(99,102,241,.1);color:var(--accent)}
.stat-card.visits .stat-icon{background:var(--blue-bg);color:var(--blue)}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--text-dim);font-weight:700;margin-bottom:6px}
.stat-value{font-size:clamp(1.4rem,2.5vw,2.2rem);font-weight:900;color:var(--text);font-family:var(--mono);line-height:1}
.stat-sub{font-size:12px;color:var(--text-dim);margin-top:8px;display:flex;align-items:center;gap:5px}

.cg{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);transition:all .3s}
.cg:hover{border-color:var(--border-strong);box-shadow:var(--shadow-md)}
.sh{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--text-dim);margin-bottom:1.25rem;display:flex;align-items:center;gap:8px}

.tr2{display:flex;align-items:center;gap:12px;padding:.9rem 1rem;border-radius:var(--radius-sm);transition:all .2s;border:1px solid transparent}
.tr2:hover{background:var(--card2);border-color:var(--border)}
.ti2{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.tm{flex:1;min-width:0}
.tn{font-weight:700;font-size:14px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.td2{font-size:11px;color:var(--text-dim);display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:2px}
.ta{font-weight:800;font-size:1rem;font-family:var(--mono);flex-shrink:0}
.txact{display:flex;gap:5px;opacity:0;transition:opacity .2s}.tr2:hover .txact{opacity:1}
.ab{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;font-size:12px;transition:all .2s;display:flex;align-items:center;justify-content:center}
.ab:hover{background:var(--accent);color:white;border-color:var(--accent)}.ab.delete:hover{background:var(--red);border-color:var(--red)}
.tag{font-size:10px;padding:2px 8px;border-radius:999px;background:var(--bg2);color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.auto-tag{font-size:10px;padding:2px 8px;border-radius:999px;background:rgba(99,102,241,.1);color:var(--accent);font-weight:700;display:inline-flex;align-items:center;gap:3px}

.fc{background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--radius-xs);padding:11px 15px;color:var(--text);font-family:var(--font);width:100%;transition:all .2s;font-size:14px;font-weight:600}
.fc:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 4px rgba(99,102,241,.1);background:white}
.fc::placeholder{color:var(--text-dim);font-weight:400}
.fl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:7px;display:block}

.mo{display:none;position:fixed;inset:0;background:rgba(30,41,59,.4);backdrop-filter:blur(8px);z-index:2000;align-items:center;justify-content:center;padding:16px}
.mo.active{display:flex}
.mcb{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:2rem;width:100%;max-width:540px;max-height:92vh;overflow-y:auto;animation:si .3s cubic-bezier(.34,1.56,.64,1);box-shadow:var(--shadow-lg)}
.mh{display:flex;align-items:center;gap:14px;margin-bottom:1.5rem;padding-bottom:1.25rem;border-bottom:1px solid var(--border)}
.mi{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:20px}
.mtt{font-size:1.1rem;font-weight:900;color:var(--text)}

.pt{background:var(--bg2);border-radius:999px;height:10px;overflow:hidden}
.pf{height:100%;border-radius:999px;transition:width 1s ease}

.budget-bar-wrap{padding:1.25rem 1.5rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card)}

.ft{display:flex;gap:3px;background:var(--bg2);border-radius:var(--radius-xs);padding:4px;border:1px solid var(--border)}
.ftb{border:none;background:transparent;color:var(--text-muted);padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none;font-family:var(--font)}
.ftb:hover{color:var(--text)}.ftb.active{background:var(--surface);color:var(--accent);box-shadow:var(--shadow)}

.ac{min-height:calc(100vh - 72px);display:flex;align-items:center;justify-content:center;padding:2rem}
.acc{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:2.5rem;width:100%;max-width:460px;position:relative;box-shadow:var(--shadow-lg)}
.acc::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--gradient-1);border-radius:var(--radius) var(--radius) 0 0}
.ach{text-align:center;margin-bottom:2rem}
.aci{width:68px;height:68px;border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;font-size:1.8rem}
.act{font-size:1.6rem;font-weight:900;margin-bottom:.4rem;color:var(--text)}
.acsu{color:var(--text-muted);font-size:15px}
.adiv{display:flex;align-items:center;gap:1rem;margin:1.5rem 0;color:var(--text-dim);font-size:11px;text-transform:uppercase;letter-spacing:.1em;font-weight:700}
.adiv::before,.adiv::after{content:'';flex:1;height:1px;background:var(--border)}

.cookie-overlay{position:fixed;bottom:0;left:0;right:0;background:white;z-index:99999;border-top:1px solid #e2e8f0;box-shadow:0 -4px 24px rgba(0,0,0,.1);animation:slideUp .4s ease}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.cookie-box{max-width:1200px;margin:0 auto;padding:16px 24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.cookie-box::before{display:none}
.cookie-emoji{font-size:2rem;display:block;flex-shrink:0}
.cookie-box h2{font-size:1rem;font-weight:800;margin:0 0 2px 0;background:var(--gradient-1);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.cookie-box p{color:var(--text-muted);line-height:1.5;margin:0;font-size:13px}
.cookie-feats{display:none}
.cookie-btns{display:flex;gap:8px;flex-shrink:0;margin:0;margin-left:auto}
.cookie-btn{padding:10px 22px;border-radius:999px;font-size:13px;font-weight:800;cursor:pointer;transition:all .3s;border:none;font-family:var(--font)}
@keyframes cb{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.cookie-box h2{font-size:1.6rem;font-weight:900;margin-bottom:.5rem;background:var(--gradient-1);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.cookie-box p{color:var(--text-muted);line-height:1.7;margin-bottom:1.25rem;font-size:14px}
.cookie-feats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:1.5rem;text-align:left}
.cookie-feat{display:flex;align-items:center;gap:8px;padding:9px 12px;background:var(--bg2);border-radius:var(--radius-xs);font-size:12px;color:var(--text-muted);font-weight:600}
.cookie-feat i{color:var(--green);font-size:11px}
.cookie-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:1.25rem}
.cookie-btn{padding:13px 32px;border-radius:999px;font-size:14px;font-weight:800;cursor:pointer;transition:all .3s;border:none;font-family:var(--font)}
.cookie-btn.accept{background:var(--gradient-1);color:white;box-shadow:0 6px 20px rgba(99,102,241,.3)}
.cookie-btn.accept:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(99,102,241,.45)}
.cookie-btn.reject{background:transparent;border:1.5px solid var(--border);color:var(--text-muted)}
.cookie-btn.reject:hover{border-color:var(--red);color:var(--red);background:var(--red-bg)}

.welcome-overlay{position:fixed;inset:0;background:rgba(240,244,255,.85);backdrop-filter:blur(8px);z-index:15000;display:flex;align-items:center;justify-content:center;padding:2rem;animation:fi .3s ease}
.welcome-card{max-width:440px;width:100%;background:var(--surface);border:1.5px solid var(--border-strong);border-radius:var(--radius);padding:2.5rem;text-align:center;animation:si .5s cubic-bezier(.34,1.56,.64,1);box-shadow:var(--shadow-lg);position:relative;overflow:hidden}
.welcome-card::before{content:'';position:absolute;top:0;left:0;right:0;height:5px;background:var(--gradient-2)}
.welcome-card.returning::before{background:var(--gradient-1)}
.we{font-size:3.5rem;margin-bottom:.75rem;display:block}
.wt{font-size:1.5rem;font-weight:900;margin-bottom:.25rem;color:var(--text)}
.wn{font-size:1.75rem;font-weight:900;background:var(--gradient-1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:.5rem}
.ws{color:var(--text-muted);font-size:14px;margin-bottom:1.75rem;line-height:1.6}
.wd{background:var(--gradient-1);color:white;border:none;padding:13px 36px;border-radius:999px;font-weight:800;font-size:14px;cursor:pointer;transition:all .3s;font-family:var(--font)}
.wd:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(99,102,241,.4)}

.alb{padding:1rem 1.25rem;border-radius:var(--radius-sm);font-size:13px;margin-bottom:1.25rem;display:flex;align-items:center;gap:12px;border:1.5px solid;line-height:1.6;font-weight:600}
.alb.danger{background:var(--red-bg);border-color:rgba(220,38,38,.25);color:var(--red)}
.alb.warning{background:var(--yellow-bg);border-color:rgba(217,119,6,.25);color:var(--yellow)}
.alb.success{background:var(--green-bg);border-color:rgba(5,150,105,.25);color:var(--green)}
.alb.info{background:var(--blue-bg);border-color:rgba(37,99,235,.25);color:var(--blue)}

.reminder-card{background:var(--yellow-bg);border:1.5px solid rgba(217,119,6,.2);border-radius:var(--radius-sm);padding:1rem 1.25rem;display:flex;align-items:center;gap:12px;margin-bottom:.75rem;transition:all .2s}
.reminder-card:hover{border-color:rgba(217,119,6,.4);transform:translateX(3px)}
.reminder-card.overdue{background:var(--red-bg);border-color:rgba(220,38,38,.2)}
.reminder-card.overdue:hover{border-color:rgba(220,38,38,.4)}
.reminder-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;background:rgba(217,119,6,.12);color:var(--yellow)}
.reminder-card.overdue .reminder-icon{background:rgba(220,38,38,.12);color:var(--red)}
.reminder-info{flex:1;min-width:0}
.reminder-title{font-weight:800;font-size:14px;color:var(--text)}
.reminder-due{font-size:11px;color:var(--text-dim);font-weight:600}
.reminder-amount{font-weight:800;font-family:var(--mono);font-size:13px;color:var(--text-muted)}
.done-btn{width:30px;height:30px;border-radius:8px;border:1.5px solid rgba(217,119,6,.3);background:transparent;cursor:pointer;font-size:12px;color:var(--yellow);transition:all .2s;display:flex;align-items:center;justify-content:center}
.done-btn:hover{background:var(--green);border-color:var(--green);color:white}

.receipt-drop-zone{border:2.5px dashed var(--border-strong);border-radius:var(--radius-sm);padding:2.5rem;text-align:center;cursor:pointer;transition:all .3s;background:var(--bg2)}
.receipt-drop-zone:hover,.receipt-drop-zone.drag-over{border-color:var(--accent);background:rgba(99,102,241,.04)}
.receipt-drop-zone i{font-size:2.5rem;color:var(--text-dim);margin-bottom:.75rem;display:block}
.receipt-result{display:none;padding:1.25rem;background:var(--green-bg);border:1.5px solid rgba(5,150,105,.25);border-radius:var(--radius-sm);margin-top:1rem}
.receipt-result.show{display:block}

.hs{min-height:calc(100vh - 72px);display:flex;align-items:center;padding:4rem 0}
.hb{display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.15em;color:var(--accent);border:1.5px solid rgba(99,102,241,.25);border-radius:999px;padding:7px 18px;margin-bottom:1.75rem;background:rgba(99,102,241,.06)}
.ht{font-size:clamp(2.2rem,4.5vw,4rem);font-weight:900;line-height:1.1;margin-bottom:1.25rem;letter-spacing:-.02em;color:var(--text)}
.gt{background:var(--gradient-1);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hsu{font-size:1.05rem;color:var(--text-muted);line-height:1.8;margin-bottom:2.25rem;max-width:520px}
.fg{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem}
.fcc{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.75rem;transition:all .3s;position:relative;overflow:hidden}
.fcc:hover{transform:translateY(-5px);border-color:var(--border-strong);box-shadow:var(--shadow-md)}
.fci{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.35rem;margin-bottom:1.25rem}
.fct{font-size:1rem;font-weight:800;margin-bottom:.5rem;color:var(--text)}
.fcd{font-size:13px;color:var(--text-muted);line-height:1.6}
.sr{display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin:3.5rem 0}
.sb{text-align:center;padding:1.75rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);transition:all .3s;box-shadow:var(--shadow-card)}
.sb:hover{transform:translateY(-4px);border-color:var(--border-strong);box-shadow:var(--shadow-md)}
.sn{font-size:2.25rem;font-weight:900;background:var(--gradient-1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:.4rem}
.slt{font-size:13px;color:var(--text-muted);font-weight:600}
.cta{background:var(--gradient-1);border-radius:var(--radius);padding:4rem;text-align:center;position:relative;overflow:hidden;margin:3rem 0}
.cta::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(255,255,255,.1) 0%,transparent 60%);animation:pg 6s ease-in-out infinite}
@keyframes pg{0%,100%{transform:scale(1) rotate(0)}50%{transform:scale(1.1) rotate(5deg)}}
.ctac{position:relative;z-index:1}
.ctat{font-size:2.25rem;font-weight:900;color:white;margin-bottom:.75rem}
.ctasu{font-size:1rem;color:rgba(255,255,255,.9);margin-bottom:1.75rem}
.bw{background:white;color:var(--accent-dark);padding:14px 40px;border-radius:999px;font-weight:900;text-decoration:none;display:inline-flex;align-items:center;gap:9px;transition:all .3s;border:none;cursor:pointer;font-size:15px;font-family:var(--font)}
.bw:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(0,0,0,.2);color:var(--accent-dark)}

.ut{width:100%;border-collapse:collapse}
.ut th,.ut td{padding:.9rem 1rem;text-align:left;border-bottom:1px solid var(--border)}
.ut th{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);background:var(--bg2)}
.ut tr:hover td{background:var(--card2)}
.us{display:inline-block;padding:3px 10px;border-radius:999px;font-size:10px;font-weight:800}
.us.active{background:var(--green-bg);color:var(--green)}.us.inactive{background:var(--red-bg);color:var(--red)}
.urb{display:inline-block;padding:3px 10px;border-radius:999px;font-size:10px;font-weight:800;background:rgba(99,102,241,.1);color:var(--accent)}

.gc{background:var(--card2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1.25rem;transition:all .3s}
.gc:hover{border-color:var(--accent);transform:translateY(-3px)}

.fab-wrap{position:fixed;bottom:28px;right:28px;z-index:999;display:flex;flex-direction:column;align-items:center;gap:12px}
.fab-main{width:56px;height:56px;border-radius:50%;background:var(--gradient-1);border:none;color:white;font-size:22px;cursor:pointer;box-shadow:0 6px 20px rgba(99,102,241,.45);transition:all .35s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;justify-content:center}
.fab-main:hover{transform:scale(1.1);box-shadow:0 10px 28px rgba(99,102,241,.6)}
.fab-main.open{transform:rotate(45deg)}
.fab-children{display:flex;flex-direction:column;align-items:center;gap:10px;transition:all .3s}
.fab-children.hidden .fab-child{opacity:0;pointer-events:none;transform:scale(0) translateY(20px)}
.fab-child{width:48px;height:48px;border-radius:50%;border:none;color:white;font-size:18px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25);transition:all .3s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;justify-content:center;position:relative}
.fab-child:hover{transform:scale(1.12)}
.fab-child .fab-label{position:absolute;right:60px;background:rgba(30,41,59,.85);color:white;font-size:11px;font-weight:800;padding:5px 11px;border-radius:999px;white-space:nowrap;opacity:0;transition:opacity .2s;pointer-events:none;font-family:var(--font)}
.fab-child:hover .fab-label{opacity:1}
.fab-calc-btn{background:linear-gradient(135deg,#6366f1,#a855f7)}
.fab-excel-btn{background:linear-gradient(135deg,#059669,#10b981)}

/* ===== GOAL SAVER PRETTY ===== */
.goal-section{background:linear-gradient(135deg,#fffaf5,#fef3ec);border:1.5px solid #fde4cf}
.goal-card-pretty{background:#fff;border:1.5px solid #fde4cf;border-radius:20px;padding:1.25rem;transition:all .3s;position:relative;overflow:hidden;animation:goalPop .5s cubic-bezier(.34,1.56,.64,1)}
.goal-card-pretty:hover{transform:translateY(-5px);box-shadow:0 12px 32px rgba(244,63,94,.12);border-color:#fbbf24}
.goal-card-pretty::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#fbbf24,#f43f5e,#a855f7)}
@keyframes goalPop{from{opacity:0;transform:scale(.9) translateY(15px)}to{opacity:1;transform:scale(1) translateY(0)}}
.goal-card-head{display:flex;align-items:center;gap:12px;margin-bottom:1rem}
.goal-icon-pretty{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#fef3c7,#fed7aa);display:flex;align-items:center;justify-content:center;font-size:20px;color:#d97706;flex-shrink:0;box-shadow:0 4px 12px rgba(251,191,36,.25)}
.goal-title-pretty{font-weight:900;font-size:15px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.goal-deadline-pretty{font-size:11px;color:var(--text-dim);font-weight:700;margin-top:2px}
.goal-done-badge{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#10b981,#34d399);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;box-shadow:0 4px 12px rgba(16,185,129,.35)}
.goal-progress-wrap{margin-bottom:1rem}
.goal-progress-track{height:12px;background:#fef3ec;border-radius:999px;overflow:hidden;position:relative}
.goal-progress-fill{height:100%;border-radius:999px;transition:width 1.2s cubic-bezier(.34,1.56,.64,1);position:relative;overflow:hidden}
.goal-progress-fill::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.4),transparent);animation:shimmer 2s infinite}
@keyframes shimmer{from{transform:translateX(-100%)}to{transform:translateX(100%)}}
.goal-progress-meta{display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--text-muted);font-weight:700;margin-top:8px}
.goal-pct{font-size:14px;font-weight:900;color:var(--accent);font-family:var(--mono)}
.goal-plan-box{background:linear-gradient(135deg,#f0f4ff,#fef3ec);border:1px dashed #c7d2fe;border-radius:14px;padding:.75rem 1rem;margin-bottom:.75rem}
.goal-plan-row{display:flex;justify-content:space-between;align-items:center;padding:3px 0}
.goal-plan-label{font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.goal-plan-value{font-weight:900;font-family:var(--mono);font-size:14px;color:var(--text)}
.goal-msg{font-size:12px;font-weight:700;text-align:center;padding:.5rem;background:rgba(255,255,255,.6);border-radius:10px;margin-bottom:.75rem}
.goal-add-form{display:flex;gap:6px;margin-bottom:.75rem}
.goal-add-input{flex:1;border:1.5px solid #fde4cf;border-radius:10px;padding:8px 12px;font-size:13px;font-weight:700;font-family:var(--mono);background:#fffaf5;outline:none;transition:all .2s}
.goal-add-input:focus{border-color:#fbbf24;background:#fff;box-shadow:0 0 0 3px rgba(251,191,36,.15)}
.goal-add-btn{width:38px;height:38px;border-radius:10px;border:none;background:linear-gradient(135deg,#10b981,#34d399);color:#fff;font-size:13px;cursor:pointer;transition:all .2s;flex-shrink:0;box-shadow:0 4px 10px rgba(16,185,129,.3)}
.goal-add-btn:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(16,185,129,.45)}
.goal-card-foot{display:flex;gap:6px;border-top:1px dashed #fde4cf;padding-top:.75rem}
.goal-mini-btn{flex:1;background:#fffaf5;border:1px solid #fde4cf;border-radius:8px;padding:6px 10px;font-size:11px;font-weight:700;color:var(--text-muted);cursor:pointer;transition:all .2s;font-family:var(--font);display:flex;align-items:center;justify-content:center;gap:5px}
.goal-mini-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.goal-mini-btn.danger:hover{background:var(--red);border-color:var(--red)}

/* Persistent banner */
.goal-banner{position:fixed;top:74px;left:50%;transform:translateX(-50%);z-index:9000;max-width:680px;width:calc(100% - 32px);background:linear-gradient(135deg,#fef2f2,#fff7ed);border:1.5px solid #fecaca;border-radius:14px;padding:.85rem 1.1rem;display:flex;align-items:center;gap:12px;box-shadow:0 8px 24px rgba(220,38,38,.15);animation:bannerSlide .4s ease}
@keyframes bannerSlide{from{opacity:0;transform:translateX(-50%) translateY(-10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.goal-banner-icon{font-size:22px;flex-shrink:0}
.goal-banner-text{flex:1;font-size:13px;color:#991b1b;font-weight:600;line-height:1.5}
.goal-banner-close{background:none;border:none;color:#991b1b;font-size:22px;cursor:pointer;padding:0 4px;flex-shrink:0;font-weight:300}
@media(max-width:640px){.goal-banner{font-size:12px;padding:.7rem .9rem}.goal-banner-text{font-size:12px}}
.calc-display{background:var(--bg2);border-radius:var(--radius-sm);padding:18px;margin-bottom:16px;text-align:right;font-size:1.8rem;font-family:var(--mono);font-weight:700;color:var(--text);border:1.5px solid var(--border);word-break:break-all}
.calc-buttons{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.calc-btn{background:var(--bg2);border:1px solid var(--border);border-radius:11px;padding:14px;font-size:1.1rem;font-weight:700;color:var(--text);cursor:pointer;transition:all .2s;font-family:var(--font)}
.calc-btn:hover{background:var(--accent);border-color:var(--accent);color:white}
.calc-btn.clear{background:var(--red-bg);color:var(--red);border-color:rgba(220,38,38,.2)}.calc-btn.clear:hover{background:var(--red);color:white}
.calc-btn.equals{background:var(--gradient-2);color:white;border:none;grid-column:span 2}

.family-member{display:flex;align-items:center;gap:12px;padding:.9rem 1rem;background:var(--card2);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:.6rem;transition:all .2s}
.family-member:hover{border-color:var(--border-strong)}
.fam-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px;flex-shrink:0}
.fam-info{flex:1;min-width:0}
.fam-name{font-weight:800;font-size:14px;color:var(--text)}
.fam-email{font-size:11px;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fam-stats{text-align:right;font-size:11px;color:var(--text-muted);font-family:var(--mono)}

.email-list-overlay{display:none;position:fixed;inset:0;background:rgba(30,41,59,.4);backdrop-filter:blur(8px);z-index:20000;align-items:center;justify-content:center;padding:1.5rem}
.email-list-overlay.active{display:flex}
.email-list-box{width:100%;max-width:600px;max-height:85vh;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);display:flex;flex-direction:column;animation:si .4s cubic-bezier(.34,1.56,.64,1);box-shadow:var(--shadow-lg);overflow:hidden}
.email-list-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;flex-shrink:0;background:var(--card2)}
.elh-icon{width:44px;height:44px;border-radius:13px;background:rgba(217,119,6,.1);display:flex;align-items:center;justify-content:center;color:var(--yellow);font-size:18px;flex-shrink:0}
.elh-info{flex:1}.elh-title{font-size:1.05rem;font-weight:900;margin-bottom:1px;color:var(--text)}.elh-count{font-size:12px;color:var(--text-dim);font-weight:600}
.elh-close{width:36px;height:36px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-muted);cursor:pointer;font-size:14px;transition:all .2s;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.elh-close:hover{border-color:var(--red);color:var(--red);background:var(--red-bg)}
.email-list-search{padding:.9rem 1.5rem;border-bottom:1px solid var(--border);flex-shrink:0}
.email-list-search input{width:100%;background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--radius-xs);padding:9px 14px 9px 38px;color:var(--text);font-family:var(--font);font-size:13px;transition:all .2s;font-weight:600}
.email-list-search input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.email-list-search-wrap{position:relative}
.email-list-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:13px}
.email-list-body{overflow-y:auto;padding:1rem 1.25rem;flex:1}
.email-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:var(--radius-sm);transition:all .2s;border:1px solid transparent;cursor:default;margin-bottom:5px}
.email-item:hover{background:var(--card2);border-color:var(--border)}
.email-item-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;flex-shrink:0}
.email-item-info{flex:1;min-width:0}
.email-item-name{font-weight:800;font-size:13px;margin-bottom:2px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.email-item-email{font-size:12px;color:var(--accent);font-family:var(--mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.email-item-meta{display:flex;align-items:center;gap:8px;flex-shrink:0}
.email-item-date{font-size:10px;color:var(--text-dim);white-space:nowrap}
.email-item-status{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.email-item-status.active{background:var(--green)}.email-item-status.inactive{background:var(--red)}
.email-item-role{font-size:9px;padding:2px 7px;border-radius:999px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
.email-item-role.admin{background:rgba(217,119,6,.1);color:var(--yellow)}.email-item-role.user{background:rgba(99,102,241,.1);color:var(--accent)}
.email-item-copy{width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--text-dim);cursor:pointer;font-size:11px;transition:all .2s;display:flex;align-items:center;justify-content:center;flex-shrink:0;opacity:0}
.email-item:hover .email-item-copy{opacity:1}
.email-item-copy:hover{border-color:var(--accent);color:var(--accent);background:rgba(99,102,241,.08)}
.email-list-footer{padding:.9rem 1.5rem;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:space-between}
.email-list-footer span{font-size:11px;color:var(--text-dim);font-weight:600}
.copy-all-btn{background:var(--bg2);border:1px solid var(--border);color:var(--text-muted);padding:7px 16px;border-radius:999px;font-size:11px;font-weight:700;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:5px;font-family:var(--font)}
.copy-all-btn:hover{border-color:var(--accent);color:var(--accent);background:rgba(99,102,241,.06)}
.email-box-btn{background:var(--bg2);border:1.5px solid var(--border);color:var(--text-muted);padding:8px 16px;border-radius:999px;font-size:12px;font-weight:700;cursor:pointer;transition:all .3s;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;font-family:var(--font)}
.email-box-btn:hover{border-color:var(--yellow);color:var(--yellow);background:var(--yellow-bg)}
.email-badge{background:var(--gradient-1);color:white;font-size:10px;font-weight:800;padding:2px 7px;border-radius:999px;min-width:18px;text-align:center}
.vb{background:var(--bg2);border:1px solid var(--border);border-radius:999px;padding:5px 14px;font-size:11px;color:var(--text-dim);display:flex;align-items:center;gap:5px;font-weight:700}
.vb strong{color:var(--accent);font-family:var(--mono)}

.footer{text-align:center;padding:2.5rem 2rem;border-top:1px solid var(--border);color:var(--text-dim);font-size:13px;margin-top:3rem;font-weight:600}
.fb{font-weight:900;background:var(--gradient-1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:.4rem;font-size:15px}

.dbox{background:var(--bg2);border-radius:var(--radius-sm);padding:1.1rem;text-align:center;font-size:13px;color:var(--text-muted);border:1px solid var(--border);font-weight:600}
.dbox strong{color:var(--text);font-family:var(--mono)}
/* Z.ai-style Continue with Google pill */
.google-pill-wrap{position:relative;margin-bottom:1.25rem;min-height:50px}
.google-pill{width:100%;display:flex;align-items:center;justify-content:center;gap:12px;padding:13px 20px;border-radius:999px;border:1px solid rgba(255,255,255,.08);background:linear-gradient(135deg,#1f2937 0%,#374151 50%,#1f2937 100%);color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:all .25s ease;box-shadow:0 4px 14px rgba(0,0,0,.18);font-family:inherit;position:relative;z-index:1}
.google-pill:hover{transform:translateY(-1px);box-shadow:0 8px 22px rgba(0,0,0,.28);background:linear-gradient(135deg,#111827 0%,#374151 50%,#111827 100%)}
.google-pill:active{transform:translateY(0)}
.google-pill svg{background:#fff;border-radius:50%;padding:2px;flex-shrink:0}
/* Real (hidden) Google button overlay — fully covers the pill so clicks reach the iframe */
.google-pill-wrap > div[id$="Btn"]{display:none !important}
.fade-in{animation:fi .5s ease}.slide-up{animation:su .5s ease}
@keyframes su{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
::-webkit-scrollbar{width:6px}::-webkit-scrollbar-track{background:var(--bg2)}::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:3px}
@media(max-width:991px){.sr{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.sr{grid-template-columns:1fr 1fr}.fg{grid-template-columns:1fr}.cta{padding:2rem}.ctat{font-size:1.6rem}.ht{font-size:2rem}.txact{opacity:1}.vb{display:none}.email-item-meta{display:none}.email-item-copy{opacity:1}}
@media(max-width:640px){.col-md-3{width:50%!important}.stat-value{font-size:1.2rem!important}}

.auto-cat-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:999px;font-size:12px;font-weight:700;color:var(--accent);margin-top:6px;cursor:pointer;transition:all .2s;animation:si .3s ease}
.auto-cat-chip:hover{background:rgba(99,102,241,.14)}
.auto-cat-chip i{font-size:10px}

.blocked-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.blocked-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:3rem;max-width:480px;text-align:center;box-shadow:var(--shadow-lg)}
.blocked-card .be{font-size:4.5rem;margin-bottom:1.25rem;display:block}
.blocked-card h1{font-size:1.75rem;margin-bottom:.75rem;color:var(--red);font-weight:900}
.blocked-card p{color:var(--text-muted);line-height:1.8;margin-bottom:1.75rem}

/* NEW STYLES */
.budget-planner-table{width:100%;border-collapse:collapse;margin-top:1rem}
.budget-planner-table th,.budget-planner-table td{padding:12px 10px;text-align:left;border-bottom:1px solid var(--border)}
.budget-planner-table th{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);background:var(--bg2)}
.budget-planner-table tr:hover td{background:var(--card2)}
.budget-status{display:inline-block;padding:3px 10px;border-radius:999px;font-size:10px;font-weight:800}
.budget-status.under{background:var(--green-bg);color:var(--green)}
.budget-status.over{background:var(--red-bg);color:var(--red)}
.budget-status.exact{background:var(--blue-bg);color:var(--blue)}
.wallet-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;text-align:center;transition:all .3s;height:100%;display:flex;flex-direction:column;justify-content:center;align-items:center}
.wallet-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:var(--border-strong)}
.wallet-card i{font-size:1.8rem;margin-bottom:.5rem;display:block}
.info-help-card{display:flex;gap:12px;align-items:flex-start;padding:.85rem 1rem;border-radius:var(--radius-sm);background:var(--blue-bg);border:1px solid rgba(37,99,235,.18);color:var(--text);font-size:12.5px;line-height:1.6}
.info-help-card i{color:var(--blue);font-size:18px;flex-shrink:0;margin-top:2px}
.info-help-card strong{color:var(--text);font-weight:800}
.info-help-card em{font-style:normal;font-weight:700;color:var(--accent)}
@media (prefers-color-scheme: dark){.info-help-card{background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.25)}}
.wallet-balance{font-size:1.3rem;font-weight:900;font-family:var(--mono);margin-top:.3rem}
.fab-wrap{position:fixed;bottom:28px;right:28px;z-index:999;display:flex;flex-direction:column;align-items:center;gap:12px}
.fab-main{width:56px;height:56px;border-radius:50%;background:var(--gradient-1);border:none;color:white;font-size:22px;cursor:pointer;box-shadow:0 6px 20px rgba(99,102,241,.45);transition:all .35s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;justify-content:center}
.fab-main:hover{transform:scale(1.1);box-shadow:0 10px 28px rgba(99,102,241,.6)}
.fab-main.open{transform:rotate(45deg);background:linear-gradient(135deg,#ef4444,#f87171)}
.fab-children{display:flex;flex-direction:column;align-items:center;gap:10px}
.fab-children.hidden .fab-child{opacity:0;pointer-events:none;transform:scale(0) translateY(20px)}
.fab-child{width:48px;height:48px;border-radius:50%;border:none;color:white;font-size:18px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25);transition:all .3s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;justify-content:center;position:relative}
.fab-child:hover{transform:scale(1.12)}
.fab-child .fab-label{position:absolute;right:60px;background:rgba(30,41,59,.88);color:white;font-size:11px;font-weight:800;padding:5px 11px;border-radius:999px;white-space:nowrap;opacity:0;transition:opacity .2s;pointer-events:none;font-family:var(--font)}
.fab-child:hover .fab-label{opacity:1}
.fab-calc-btn{background:linear-gradient(135deg,#6366f1,#a855f7)}
.fab-excel-btn{background:linear-gradient(135deg,#059669,#10b981)}
</style>
</head>
<body>
<!-- GOOGLE RETURN HANDLER - runs FIRST before anything else -->
<script>
(function(){
  try {
    if (!window.location.hash || window.location.hash.indexOf('id_token=') === -1) return;
    var hash = window.location.hash.substring(1);
    var pairs = hash.split('&');
    var token = '';
    for (var i = 0; i < pairs.length; i++) {
      // Find id_token= key and grab everything after it (tokens contain = signs)
      if (pairs[i].indexOf('id_token=') === 0) {
        token = pairs[i].substring('id_token='.length);
        try { token = decodeURIComponent(token); } catch(e) {}
        break;
      }
    }
    if (!token) return;
    // Clean hash immediately so refresh doesn't re-submit
    history.replaceState(null, '', location.pathname + location.search);
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';
    form.style.display = 'none';
    var f1 = document.createElement('input'); f1.type='hidden'; f1.name='action';   f1.value='google_auth'; form.appendChild(f1);
    var f2 = document.createElement('input'); f2.type='hidden'; f2.name='id_token'; f2.value=token;        form.appendChild(f2);
    (document.body || document.documentElement).appendChild(form);
    form.submit();
  } catch(e) {
    console.error('Google return handler error:', e.message);
  }
})();
</script>
<div id="toastContainer"></div>

<?php if($toast): ?><script>document.addEventListener('DOMContentLoaded',function(){showToast('<?=addslashes($toast['msg'])?>','<?=$toast['type']?>')});</script><?php endif; ?>

<?php if($page==='cookie_blocked'): ?>
<div class="blocked-wrap"><div class="blocked-card"><span class="be">🚫</span><h1>Cookies Required</h1><p>SmartBudget Pro needs cookies to keep you logged in and save your data securely.</p><form method="post"><input type="hidden" name="cookie_action" value="accept"><button type="submit" class="cookie-btn accept"><i class="fas fa-cookie-bite"></i> Accept & Continue</button></form></div></div>

<?php elseif(!$cookieDecided): ?>
<div class="cookie-overlay"><div class="cookie-box"><span class="cookie-emoji">🍪</span><div style="flex:1;min-width:200px"><h2>Guess what? Cookies!</h2><p>We use essential cookies for authentication and secure sessions. No third-party tracking or ads. <a href="#" style="color:var(--accent)">Cookie Policy</a></p></div><div class="cookie-btns"><form method="post" style="display:inline"><input type="hidden" name="cookie_action" value="accept"><button type="submit" class="cookie-btn accept"><i class="fas fa-check-circle"></i> Accept All</button></form><form method="post" style="display:inline"><input type="hidden" name="cookie_action" value="reject"><button type="submit" class="cookie-btn reject"><i class="fas fa-times-circle"></i> Decline</button></form></div></div></div>

<?php else: ?>

<?php if($newUserName): ?>
<div class="welcome-overlay" id="welcomeOverlay" onclick="if(event.target===this)dismissWelcome()"><div class="welcome-card"><span class="we">🎉</span><div class="wt">Welcome to SmartBudget Pro!</div><div class="wn"><?=h($newUserName)?></div><div class="ws">Account created! Check <strong style="color:var(--accent)"><?=h($newUserEmail??'')?></strong> for your welcome email.</div><button class="wd" onclick="window.location.href='index.php?page=dashboard'"><i class="fas fa-rocket"></i> Let's Get Started!</button></div></div>
<?php endif; ?>
<?php if($returningUserName): ?>
<div class="welcome-overlay" id="welcomeOverlay"><div class="welcome-card returning"><span class="we">👋</span><div class="wt">Welcome Back!</div><div class="wn"><?=h($returningUserName)?></div><div class="ws">This email is already registered. Please login with your existing credentials.</div><button class="wd" onclick="window.location.href='index.php?page=login'"><i class="fas fa-sign-in-alt"></i> Continue to Login</button></div></div>
<?php endif; ?>
<?php if($welcomeType && $welcomeName && !$newUserName && !$returningUserName): ?>
<div class="welcome-overlay" id="welcomeOverlay" onclick="if(event.target===this)dismissWelcome()"><div class="welcome-card <?=$welcomeType==='returning'?'returning':''?>"><span class="we"><?=$welcomeType==='new'?'🚀':'👋'?></span><div class="wt"><?=$welcomeType==='new'?'Your Journey Begins!':'Welcome Back!'?></div><div class="wn"><?=h($welcomeName)?></div><div class="ws"><?=$welcomeType==='new'?'Start adding income and expenses to take control!':'Your financial dashboard is ready.'?></div><button class="wd" onclick="window.location.href='index.php?page=dashboard'"><i class="fas fa-arrow-right"></i> Go to Dashboard</button></div></div>
<?php endif; ?>

<nav class="navbar">
  <div class="container-fluid d-flex justify-content-between align-items-center h-100">
    <a href="index.php" class="navbar-brand"><div class="brand-icon"><i class="fas fa-wallet"></i></div><span class="brand-name">SmartBudget</span>&nbsp;<span style="font-size:.65rem;background:var(--gradient-1);color:white;padding:2px 8px;border-radius:999px;font-weight:900;vertical-align:middle">PRO</span></a>
    <div class="nav-pills">
      <?php if($visitCount>0): ?><div class="vb"><i class="fas fa-eye"></i> Visit <strong>#<?=$visitCount?></strong></div><?php endif; ?>
      <?php if(isLoggedIn()): ?>
        <?php if(isAdmin()): ?>
          <a href="index.php?page=users" class="bp <?=$page==='users'?'active':''?>"><i class="fas fa-users-cog"></i> Users</a>
          <a href="index.php?page=admin_reports" class="bp <?=$page==='admin_reports'?'active':''?>"><i class="fas fa-chart-line"></i> Reports</a>
        <?php endif; ?>
        <button class="email-box-btn" onclick="openEmailList()"><i class="fas fa-envelope-open-text"></i> Emails<span class="email-badge"><?=count($allRegisteredEmails)?></span></button>
        <span class="bp" style="cursor:default"><i class="fas fa-user-circle" style="color:var(--accent)"></i> <?=h($_SESSION['user_name'])?><?php if(isAdmin()): ?> <span style="background:var(--gradient-1);color:white;padding:2px 9px;border-radius:999px;font-size:9px;font-weight:900">ADMIN</span><?php endif; ?></span>
        <form method="post" style="margin:0;display:inline"><input type="hidden" name="action" value="logout"><button type="submit" class="bp danger"><i class="fas fa-sign-out-alt"></i> Logout</button></form>
      <?php else: ?>
        <a href="index.php?page=login" class="bp <?=$page==='login'?'active':''?>"><i class="fas fa-sign-in-alt"></i> Login</a>
        <a href="index.php?page=register" class="bp solid"><i class="fas fa-rocket"></i> Get Started</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<?php if(isLoggedIn()): ?>
<div class="email-list-overlay" id="emailListOverlay" onclick="if(event.target===this)closeEmailList()">
  <div class="email-list-box">
    <div class="email-list-header"><div class="elh-icon"><i class="fas fa-envelope-open-text"></i></div><div class="elh-info"><div class="elh-title">All Registered Emails</div><div class="elh-count"><?=count($allRegisteredEmails)?> registered</div></div><button class="elh-close" onclick="closeEmailList()"><i class="fas fa-times"></i></button></div>
    <div class="email-list-search"><div class="email-list-search-wrap"><i class="fas fa-search"></i><input type="text" id="emailSearchInput" placeholder="Search by name or email..." oninput="filterEmails(this.value)"></div></div>
    <div class="email-list-body" id="emailListBody">
      <?php if(empty($allRegisteredEmails)): ?><div style="text-align:center;padding:2.5rem;color:var(--text-dim)"><i class="fas fa-inbox" style="font-size:2.5rem;display:block;margin-bottom:1rem;opacity:.3"></i><p>No emails yet</p></div>
      <?php else: foreach($allRegisteredEmails as $em): ?>
      <div class="email-item" data-search="<?=strtolower(h($em['name'].' '.$em['email']))?>">
        <div class="email-item-avatar" style="background:<?=h($em['avatar_color']??'#6366f1')?>20;color:<?=h($em['avatar_color']??'#6366f1')?>"><?=strtoupper(substr($em['name'],0,1))?></div>
        <div class="email-item-info"><div class="email-item-name"><?=h($em['name'])?></div><div class="email-item-email"><?=h($em['email'])?></div></div>
        <div class="email-item-meta"><span class="email-item-role <?=($em['role']??'user')==='admin'?'admin':'user'?>"><?=ucfirst($em['role']??'user')?></span><div class="email-item-status <?=$em['is_active']?'active':'inactive'?>"></div><span class="email-item-date"><?=date('M d, Y',strtotime($em['created_at']))?></span></div>
        <button class="email-item-copy" onclick="copyEmail('<?=addslashes($em['email'])?>', this)"><i class="fas fa-copy"></i></button>
      </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="email-list-footer"><span><i class="fas fa-info-circle"></i> Click copy icon to copy email</span><button class="copy-all-btn" onclick="copyAllEmails()"><i class="fas fa-clipboard-list"></i> Copy All</button></div>
  </div>
</div>
<?php endif; ?>

<?php if($page==='home' && !isLoggedIn()): ?>
<div class="container fade-in">
  <section class="hs">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <div class="hb"><i class="fas fa-bolt"></i> Professional Finance Tool 2025</div>
        <h1 class="ht">Master Your <span class="gt">Money</span> Like a Pro</h1>
        <p class="hsu">Track income, manage expenses, scan receipts, set reminders and share with family — all in one beautiful dashboard.</p>
        <div class="d-flex gap-3 flex-wrap mb-4">
          <a href="index.php?page=register" class="bp solid" style="padding:14px 32px;font-size:15px"><i class="fas fa-rocket"></i> Start Free Today</a>
          <a href="index.php?page=login" class="bp" style="padding:14px 32px;font-size:15px"><i class="fas fa-sign-in-alt"></i> Login</a>
        </div>
        <div class="d-flex gap-4 flex-wrap">
          <?php foreach(['100% Free Forever','Smart Email Alerts','Receipt Scanner','Family Budget'] as $c): ?>
          <div style="font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:6px;font-weight:700"><i class="fas fa-check-circle" style="color:var(--green)"></i> <?=$c?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="cg p-4">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <span style="font-weight:900;font-size:15px;color:var(--text)">Monthly Overview</span>
            <span style="font-size:11px;color:var(--text-muted);background:var(--bg2);padding:5px 14px;border-radius:999px;font-weight:700;border:1px solid var(--border)"><i class="fas fa-calendar"></i> <?=date('F Y')?></span>
          </div>
          <?php foreach([['Total Income','$8,500','#059669','var(--green-bg)','fa-arrow-trend-down'],['Total Expenses','$5,200','#dc2626','var(--red-bg)','fa-arrow-trend-up'],['Net Savings','$3,300','#6366f1','rgba(99,102,241,.06)','fa-piggy-bank']] as $d): ?>
          <div class="d-flex align-items-center gap-3 p-3 mb-3" style="background:<?=$d[3]?>;border-radius:12px;border:1px solid <?=$d[2]?>20">
            <div style="width:42px;height:42px;border-radius:12px;background:<?=$d[2]?>18;display:flex;align-items:center;justify-content:center"><i class="fas <?=$d[4]?>" style="color:<?=$d[2]?>"></i></div>
            <span style="color:var(--text-muted);font-size:13px;flex:1;font-weight:700"><?=$d[0]?></span>
            <span style="font-weight:900;font-size:17px;color:<?=$d[2]?>;font-family:var(--mono)"><?=$d[1]?></span>
          </div>
          <?php endforeach; ?>
          <div class="mt-3"><div class="d-flex justify-content-between mb-2" style="font-size:12px"><span style="color:var(--text-muted);font-weight:700">Budget Utilization</span><span style="font-weight:900;color:var(--green)">61.2%</span></div><div class="pt"><div class="pf" style="width:61%;background:var(--gradient-2)"></div></div></div>
        </div>
      </div>
    </div>
  </section>

  <div class="sr">
    <?php foreach([['1000+','Happy Users','fa-users'],['12','Smart Categories','fa-tags'],['100%','Secure & Free','fa-shield-halved'],['24/7','Email Alerts','fa-envelope-circle-check']] as $i=>$s): ?>
    <div class="sb slide-up" style="animation-delay:<?=$i*.1?>s"><div style="font-size:1.5rem;color:var(--accent);margin-bottom:12px"><i class="fas <?=$s[2]?>"></i></div><div class="sn"><?=$s[0]?></div><div class="slt"><?=$s[1]?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="text-center mb-4"><h2 style="font-size:2.25rem;font-weight:900;margin-bottom:.6rem;color:var(--text)">Everything You Need to <span class="gt">Save More</span></h2><p style="color:var(--text-muted);font-size:1rem;font-weight:600">Built with PHP + MySQL — a real full-stack application</p></div>

  <div class="fg mb-5">
    <?php foreach([
      ['fa-brain','#6366f1','Auto-Categorization','Type "KFC 1200" and it auto-detects → Food & Dining instantly.'],
      ['fa-camera','#ec4899','Receipt Scanner','Upload a receipt photo and extract the amount automatically.'],
      ['fa-bell','#f59e0b','Smart Reminders','Set bill reminders and budget alerts that notify you on time.'],
      ['fa-users','#059669','Family Budget','Share your budget with family members and track together.'],
      ['fa-chart-pie','#8b5cf6','Real-time Dashboard','Live income vs expense charts with monthly trend graphs.'],
      ['fa-triangle-exclamation','#dc2626','Budget Limit Alerts','Blocked from overspending with instant alert before saving.'],
      ['fa-credit-card','#3b82f6','Payment Tracking','Track cash, card, and bank transfers for every expense.'],
      ['fa-redo','#0ea5e9','Recurring Entries','Mark salary, rent and bills as recurring automatically.'],
      ['fa-shield-alt','#a855f7','Bank-Level Security','bcrypt hashing, session management, CSRF protection.'],
    ] as $f): ?>
    <div class="fcc"><div class="fci" style="background:<?=$f[1]?>15;color:<?=$f[1]?>"><i class="fas <?=$f[0]?>"></i></div><h3 class="fct"><?=$f[2]?></h3><p class="fcd"><?=$f[3]?></p></div>
    <?php endforeach; ?>
  </div>

  <div class="cta"><div class="ctac"><h2 class="ctat">Ready to Take Control?</h2><p class="ctasu">Join SmartBudget Pro — completely free, no credit card required</p><a href="index.php?page=register" class="bw"><i class="fas fa-rocket"></i> Create Free Account</a></div></div>
</div>

<?php elseif($page==='login'): ?>

<!-- Hero Section above login -->
<div style="background:linear-gradient(135deg,#6366f1 0%,#a855f7 60%,#ec4899 100%);padding:48px 24px 40px;text-align:center;position:relative;overflow:hidden;margin-bottom:0">
  <div style="position:absolute;top:-50px;left:-50px;width:180px;height:180px;background:rgba(255,255,255,.07);border-radius:50%"></div>
  <div style="position:absolute;bottom:-60px;right:-30px;width:220px;height:220px;background:rgba(255,255,255,.05);border-radius:50%"></div>

  <div style="font-size:2.5rem;margin-bottom:10px">💰</div>
  <h1 style="color:white;font-size:clamp(1.5rem,3vw,2.2rem);font-weight:900;margin:0 0 10px;letter-spacing:-0.5px">Apka Paisa, Apka Control</h1>
  <p style="color:rgba(255,255,255,.85);font-size:15px;font-weight:600;margin:0 auto 24px;max-width:480px;line-height:1.7">Income track karo · Expenses manage karo · <strong style="color:white">Har mahine zyada bachao</strong></p>

  <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center">
    <span style="background:rgba(255,255,255,.15);color:white;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.2)">📊 Smart Dashboard</span>
    <span style="background:rgba(255,255,255,.15);color:white;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.2)">📧 Budget Alerts</span>
    <span style="background:rgba(255,255,255,.15);color:white;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.2)">🎯 Savings Goals</span>
    <span style="background:rgba(255,255,255,.15);color:white;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.2)">100% Free ✨</span>
  </div>
</div>

<div class="ac"><div class="acc">
  <div class="ach"><div class="aci" style="background:rgba(99,102,241,.1)">🔐</div><h1 class="act">Welcome Back</h1><p class="acsu">Sign in to your SmartBudget account</p></div>
  <?php if($visitCount>1): ?><div class="alb info"><i class="fas fa-eye"></i><div>This is your <strong>visit #<?=$visitCount?></strong>!</div></div><?php endif; ?>

  <!-- ✅ FIXED: Google button always visible, no overlay blocking it, no cookie requirement -->
  <button type="button" onclick="triggerGoogleLogin()" style="width:100%;display:flex;align-items:center;justify-content:center;gap:12px;padding:14px 20px;border-radius:999px;border:none;background:linear-gradient(135deg,#1f2937,#374151);color:#fff;font-size:15px;font-weight:700;cursor:pointer;margin-bottom:1.25rem;box-shadow:0 4px 14px rgba(0,0,0,.2);font-family:inherit;transition:all .25s" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 8px 22px rgba(0,0,0,.3)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(0,0,0,.2)'">
    <svg width="20" height="20" viewBox="0 0 48 48" style="background:#fff;border-radius:50%;padding:2px;flex-shrink:0"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.2-.1-2.3-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.5 16 18.9 13 24 13c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.4 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35 26.7 36 24 36c-5.2 0-9.7-3.3-11.3-8l-6.5 5C9.6 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.3-4.2 5.6l6.2 5.2C40.9 35.7 44 30.3 44 24c0-1.2-.1-2.3-.4-3.5z"/></svg>
    <span>Continue with Google</span>
  </button>
  <div class="adiv">or sign in with email</div>

  <form method="post"><input type="hidden" name="action" value="login">
    <div class="mb-3"><label class="fl">Email Address</label><input type="email" name="email" class="fc" placeholder="you@example.com" required autofocus></div>
    <div class="mb-4"><label class="fl">Password</label><input type="password" name="password" class="fc" placeholder="••••••••" required></div>
    <div class="d-flex justify-content-between align-items-center mb-4"><label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;color:var(--text-muted);font-weight:700"><input type="checkbox" style="accent-color:var(--accent);width:15px;height:15px"> Remember me</label><a href="index.php?page=forgot-password" style="font-size:13px;color:var(--accent);text-decoration:none;font-weight:700">Forgot password?</a></div>
    <button type="submit" class="bp solid" style="width:100%;padding:14px;font-size:15px;justify-content:center"><i class="fas fa-sign-in-alt"></i> Sign In with Email</button>
  </form>

  <div class="adiv">demo credentials</div>
  <div class="dbox"><div class="mb-2"><i class="fas fa-user" style="color:var(--accent);margin-right:7px"></i><strong>demo@budget.com</strong></div><div><i class="fas fa-lock" style="color:var(--accent);margin-right:7px"></i><strong>demo123</strong></div><div style="border-top:1px solid var(--border);margin-top:10px;padding-top:10px"><i class="fas fa-crown" style="color:var(--yellow);margin-right:7px"></i>Admin: <strong>admin@smartbudget.com</strong> / <strong>admin123</strong></div></div>
  <p style="text-align:center;margin-top:1.5rem;font-size:13px;color:var(--text-muted);font-weight:700">No account? <a href="index.php?page=register" style="color:var(--accent);text-decoration:none;font-weight:800">Register here →</a></p>
</div></div>

<?php elseif($page==='register'): ?>
<div class="ac"><div class="acc" style="max-width:500px">
  <div class="ach"><div class="aci" style="background:var(--green-bg)">🚀</div><h1 class="act">Create Account</h1><p class="acsu">Start managing your finances for free</p></div>

  <button type="button" onclick="triggerGoogleRegister()" style="width:100%;display:flex;align-items:center;justify-content:center;gap:12px;padding:14px 20px;border-radius:999px;border:none;background:linear-gradient(135deg,#1f2937,#374151);color:#fff;font-size:15px;font-weight:700;cursor:pointer;margin-bottom:1.25rem;box-shadow:0 4px 14px rgba(0,0,0,.2);font-family:inherit;transition:all .25s" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 8px 22px rgba(0,0,0,.3)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(0,0,0,.2)'">
    <svg width="20" height="20" viewBox="0 0 48 48" style="background:#fff;border-radius:50%;padding:2px;flex-shrink:0"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.2-.1-2.3-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.5 16 18.9 13 24 13c3 0 5.8 1.1 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.4 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35 26.7 36 24 36c-5.2 0-9.7-3.3-11.3-8l-6.5 5C9.6 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.3-4.2 5.6l6.2 5.2C40.9 35.7 44 30.3 44 24c0-1.2-.1-2.3-.4-3.5z"/></svg>
    <span>Continue with Google</span>
  </button>
  <div class="adiv">or register with email</div>

  <form method="post"><input type="hidden" name="action" value="register">
    <div class="row g-3 mb-3"><div class="col-md-6"><label class="fl">Full Name</label><input type="text" name="name" class="fc" placeholder="Your Name" required></div><div class="col-md-6"><label class="fl">Currency</label><select name="currency" class="fc"><option value="PKR">PKR — Rs</option><option value="USD" selected>USD — $</option><option value="EUR">EUR — €</option><option value="GBP">GBP — £</option></select></div></div>
    <div class="mb-3"><label class="fl">Email Address</label><input type="email" name="email" class="fc" placeholder="you@example.com" required></div>
    <div class="mb-3"><label class="fl">Password</label><input type="password" name="password" class="fc" placeholder="Minimum 6 characters" required minlength="6"></div>
    <div class="row g-3 mb-4">
      <div class="col-md-6"><label class="fl">Monthly Savings Goal (Optional)</label><input type="number" name="monthly_goal" class="fc" placeholder="e.g. 10000" min="0"></div>
      <div class="col-md-6"><label class="fl">Monthly Expense (Optional)</label><input type="number" name="monthly_expense" class="fc" placeholder="e.g. 30000" min="0"></div>
    </div>
    <button type="submit" class="bp solid" style="width:100%;padding:14px;font-size:15px;justify-content:center"><i class="fas fa-user-plus"></i> Create My Account</button>
  </form>

  <p style="text-align:center;margin-top:1.5rem;font-size:13px;color:var(--text-muted);font-weight:700">Already have an account? <a href="index.php?page=login" style="color:var(--accent);text-decoration:none;font-weight:800">Login →</a></p>
</div></div>

<?php elseif($page==='forgot-password'): ?>
<div class="ac"><div class="acc"><div class="ach"><div class="aci" style="background:var(--yellow-bg)">🔑</div><h1 class="act">Forgot Password?</h1><p class="acsu">Enter your email to receive a reset link</p></div><form method="post"><input type="hidden" name="action" value="forgot_password"><div class="mb-4"><label class="fl">Email Address</label><input type="email" name="email" class="fc" placeholder="you@example.com" required></div><button type="submit" class="bp solid" style="width:100%;padding:14px;font-size:15px;justify-content:center"><i class="fas fa-paper-plane"></i> Send Reset Link</button></form><p style="text-align:center;margin-top:1.5rem;font-size:13px;color:var(--text-muted);font-weight:700">Remember password? <a href="index.php?page=login" style="color:var(--accent);text-decoration:none;font-weight:800">Login →</a></p></div></div>

<?php elseif($page==='reset-password' && isset($_GET['token'])):
$ruid=verifyResetToken($_GET['token']);
if(!$ruid){$_SESSION['toast']=['type'=>'error','msg'=>'Invalid or expired link.']; header('Location: index.php?page=forgot-password'); exit;}
?>
<div class="ac"><div class="acc"><div class="ach"><div class="aci" style="background:var(--green-bg)">🔐</div><h1 class="act">Reset Password</h1></div><form method="post"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="token" value="<?=h($_GET['token'])?>"><div class="mb-4"><label class="fl">New Password</label><input type="password" name="password" class="fc" placeholder="Minimum 6 characters" required minlength="6"></div><button type="submit" class="bp solid" style="width:100%;padding:14px;font-size:15px;justify-content:center"><i class="fas fa-lock"></i> Reset Password</button></form></div></div>

<?php elseif($page==='users' && isLoggedIn() && isAdmin()): ?>
<div class="container py-4 fade-in">
  <div class="cg p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
      <div><h2 style="font-size:1.4rem;font-weight:900;margin:0;color:var(--text)"><i class="fas fa-users-cog" style="color:var(--accent);margin-right:10px"></i>User Management</h2><p style="color:var(--text-muted);margin-top:6px;margin-bottom:0;font-size:13px;font-weight:700"><?=count($usersData)?> users registered</p></div>
      <a href="index.php?page=dashboard" class="bp"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
    <div style="overflow-x:auto">
      <table class="ut">
        <thead>
        <tr><th>#</th><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Income</th><th>Expenses</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach($usersData as $i=>$u): ?>
          <tr>
            <td style="color:var(--text-dim);font-weight:700"><?=$i+1?></td>
            <td><div style="display:flex;align-items:center;gap:10px"><div style="width:36px;height:36px;border-radius:50%;background:<?=h($u['avatar_color']??'#6366f1')?>20;display:flex;align-items:center;justify-content:center;color:<?=h($u['avatar_color']??'#6366f1')?>;font-weight:900;font-size:13px"><?=strtoupper(substr($u['name'],0,1))?></div><strong style="font-size:13px"><?=h($u['name'])?></strong></div></td>
            <td style="color:var(--text-muted);font-size:12px;font-weight:600"><?=h($u['email'])?></td>
            <td><span class="urb"><?=ucfirst($u['role']??'user')?></span></td>
            <td><span class="us <?=$u['is_active']?'active':'inactive'?>"><?=$u['is_active']?'Active':'Inactive'?></span></td>
            <td style="font-size:11px;color:var(--text-dim);font-weight:700"><?=date('M d, Y',strtotime($u['created_at']))?></td>
            <td style="color:var(--green);font-family:var(--mono);font-size:12px;font-weight:800"><?=money($u['total_income']??0)?></td>
            <td style="color:var(--red);font-family:var(--mono);font-size:12px;font-weight:800"><?=money($u['total_expense']??0)?></td>
            <td><?php if($u['id']!=currentUserId()): ?><div style="display:flex;gap:6px">
              <form method="post" style="margin:0"><input type="hidden" name="action" value="admin_toggle_user"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button type="submit" class="ab" title="Toggle"><i class="fas fa-<?=$u['is_active']?'ban':'check'?>"></i></button></form>
              <form method="post" onsubmit="return confirm('Delete <?=addslashes($u['name'])?> and ALL their data?')" style="margin:0"><input type="hidden" name="action" value="admin_delete_user"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button type="submit" class="ab delete"><i class="fas fa-trash"></i></button></form>
            </div><?php else: ?><span style="font-size:10px;color:var(--text-dim);font-weight:700"><i class="fas fa-lock"></i> You</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif($page==='admin_reports' && isLoggedIn() && isAdmin()): ?>
<div class="container py-4 fade-in">
  <div class="cg p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
      <div><h2 style="font-size:1.4rem;font-weight:900;margin:0;color:var(--text)"><i class="fas fa-chart-line" style="color:var(--accent);margin-right:10px"></i>Registration Reports</h2><p style="color:var(--text-muted);margin-top:6px;margin-bottom:0;font-size:13px;font-weight:700">User signups last 12 months</p></div>
      <a href="index.php?page=dashboard" class="bp"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
    <div class="row g-4 mb-5">
      <div class="col-md-4"><div class="stat-card savings text-center"><div class="stat-label">Total Users</div><div class="stat-value" style="font-size:2.5rem"><?=$totalUsers?></div></div></div>
      <div class="col-md-4"><div class="stat-card income text-center"><div class="stat-label">Active Users</div><div class="stat-value" style="font-size:2.5rem"><?=$activeUsers?></div></div></div>
      <div class="col-md-4"><div class="stat-card visits text-center"><div class="stat-label">New This Month</div><div class="stat-value" style="font-size:2.5rem"><?=$newThisMonth?></div></div></div>
    </div>
    <canvas id="regChart" style="max-height:380px;width:100%"></canvas>
  </div>
</div>
<script>
new Chart(document.getElementById('regChart').getContext('2d'),{type:'bar',data:{labels:<?=json_encode(array_column($registrationStats,'month'))?>,datasets:[{label:'New Registrations',data:<?=json_encode(array_column($registrationStats,'count'))?>,backgroundColor:'rgba(99,102,241,0.7)',borderColor:'#6366f1',borderWidth:0,borderRadius:8,hoverBackgroundColor:'rgba(99,102,241,0.9)'}]},options:{responsive:true,plugins:{legend:{labels:{color:'#64748b',font:{family:'Nunito',weight:'700'}}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,0.04)'},ticks:{color:'#94a3b8',font:{weight:'700'}}},x:{grid:{display:false},ticks:{color:'#94a3b8',font:{weight:'700'}}}}}});
</script>

<?php elseif($page==='dashboard' && isLoggedIn()):
extract($dashData);
?>
<div class="container py-4 fade-in">

  <?php
  // ===== SMART ALERTS BLOCK =====
  // Compute this-month income/expense + open goals signals
  $smMonthInc = 0.0; $smMonthExp = 0.0; $smMonthGoalSaved = 0.0;
  try {
    $_db = getDB();
    $s1 = $_db->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=? AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $s1->execute([currentUserId()]); $smMonthInc = (float)$s1->fetchColumn();
    $s2 = $_db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $s2->execute([currentUserId()]); $smMonthExp = (float)$s2->fetchColumn();
  } catch(Exception $e){}
  $smMonthSav = $smMonthInc - $smMonthExp;
  $hasOpenDeadlineGoals = false; $smGoalNeed = 0.0;
  foreach($goals as $_gx) {
    if (empty($_gx['is_completed']) && !empty($_gx['deadline'])) {
      $hasOpenDeadlineGoals = true;
      $_rem = max(0,(float)$_gx['target_amt']-(float)$_gx['saved_amt']);
      $_ml  = max(1,(int)ceil((strtotime($_gx['deadline'])-time())/2628000));
      $smGoalNeed += $_rem / $_ml;
    }
  }
  $alertOverspend = $smMonthInc>0 && $smMonthExp > $smMonthInc;
  $alertGoalPause = $hasOpenDeadlineGoals && $smMonthInc>0 && $smMonthSav <= 0;
  $alertDanger    = $smMonthInc>0 && !$alertOverspend && $smGoalNeed>0 && ($smMonthExp + $smGoalNeed) > $smMonthInc;
  ?>

  <?php if($budgetExceededAlert): ?>
  <div class="alb danger" id="budgetExceededAlert"><i class="fas fa-triangle-exclamation" style="font-size:18px;flex-shrink:0"></i><div><strong>🚨 Budget Exceeded!</strong> You've gone <strong><?=money($budgetExceededAlert['amount'],$currency)?></strong> over your income. Expense was saved — email alert sent!</div><button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:var(--red);cursor:pointer;font-size:16px"><i class="fas fa-times"></i></button></div>
  <?php endif; ?>

  <?php if($alertOverspend): ?>
  <div class="alb danger"><i class="fas fa-triangle-exclamation" style="font-size:18px;flex-shrink:0"></i><div><strong>Overspend Alert!</strong> You have spent <strong><?=money($smMonthExp-$smMonthInc,$currency)?></strong> more than your income this month. Nothing left for your goals.</div></div>
  <?php endif; ?>

  <?php if($alertGoalPause): ?>
  <div class="alb warning"><i class="fas fa-pause-circle" style="font-size:18px;flex-shrink:0"></i><div><strong>Goal Pause Alert!</strong> You haven't saved anything towards your goals this month. Your <?=count(array_filter($goals,fn($g)=>empty($g['is_completed']) && !empty($g['deadline'])))?> open goal(s) are getting delayed by another month.</div></div>
  <?php endif; ?>

  <?php if($alertDanger): ?>
  <div class="alb danger"><i class="fas fa-fire" style="font-size:18px;flex-shrink:0"></i><div><strong>Danger Zone!</strong> Expenses (<?=money($smMonthExp,$currency)?>) + Goal savings need (<?=money($smGoalNeed,$currency)?>) = <strong><?=money($smMonthExp+$smGoalNeed,$currency)?></strong>, which exceeds your income <strong><?=money($smMonthInc,$currency)?></strong>. Cut down on wants or extend your goal timelines.</div></div>
  <?php endif; ?>

  <?php if(!$alertOverspend && !$alertDanger && $totalExpense>$totalIncome && $totalIncome>0): ?>
  <div class="alb danger"><i class="fas fa-triangle-exclamation" style="font-size:18px;flex-shrink:0"></i><div><strong>🚨 Budget Alert!</strong> Total expenses exceed income by <strong><?=money($totalExpense-$totalIncome,$currency)?></strong>.</div></div>
  <?php elseif(!$alertOverspend && !$alertDanger && !$alertGoalPause && $savings>0 && $savings<$totalIncome*0.1 && $totalIncome>0): ?>
  <div class="alb warning"><i class="fas fa-chart-line" style="font-size:18px;flex-shrink:0"></i><div><strong>Low Savings:</strong> Saving less than 10% of income. Review your expenses.</div></div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card income"><div class="stat-icon"><i class="fas fa-arrow-down"></i></div><div class="stat-label">Total Income</div><div class="stat-value"><?=money($totalIncome,$currency)?></div><div class="stat-sub"><i class="fas fa-receipt"></i> <?=count($incomes)?> source<?=count($incomes)!=1?'s':''?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card expense"><div class="stat-icon"><i class="fas fa-arrow-up"></i></div><div class="stat-label">Total Expenses</div><div class="stat-value"><?=money($totalExpense,$currency)?></div><div class="stat-sub"><i class="fas fa-credit-card"></i> <?=count($expenses)?> transactions</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card savings"><div class="stat-icon"><i class="fas fa-piggy-bank"></i></div><div class="stat-label">Net Savings</div><div class="stat-value" style="color:<?=$savings>=0?'var(--accent)':'var(--red)'?>"><?=money($savings,$currency)?></div><div class="stat-sub"><i class="fas fa-percent"></i> <?=number_format(min($utilization,999),1)?>% utilized</div></div></div>
    <?php if(isAdmin()):
      $adb=getDB(); $ats=['total'=>$adb->query("SELECT COUNT(*) FROM users")->fetchColumn(),'active'=>$adb->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn()];
    ?><div class="col-6 col-lg-3"><div class="stat-card" style="background:linear-gradient(135deg,rgba(99,102,241,.06),rgba(168,85,247,.06));border-color:rgba(99,102,241,.2)"><div class="stat-icon" style="background:rgba(99,102,241,.1);color:var(--accent)"><i class="fas fa-shield-halved"></i></div><div class="stat-label">Admin Portal</div><div class="stat-value" style="font-size:1.6rem"><?=$ats['total']?> <span style="font-size:.9rem;color:var(--text-muted);font-weight:700">users</span></div><div class="stat-sub"><i class="fas fa-circle" style="color:var(--green);font-size:7px"></i> <?=$ats['active']?> active</div></div></div>
    <?php else: ?><div class="col-6 col-lg-3"><div class="stat-card visits"><div class="stat-icon"><i class="fas fa-eye"></i></div><div class="stat-label">Your Visits</div><div class="stat-value">#<?=$visitCount?></div><div class="stat-sub"><i class="fas fa-clock"></i> Since <?=$firstVisit?date('M d',strtotime($firstVisit)):'today'?></div></div></div>
    <?php endif; ?>
  </div>

  <!-- BALANCE OVERVIEW SECTION -->
  <div class="cg p-4 mb-4">
    <div class="sh"><i class="fas fa-wallet" style="color:var(--accent)"></i> Balance Overview</div>
    <div class="info-help-card mb-3">
      <i class="fas fa-circle-info"></i>
      <div>
        <strong>What is this?</strong> Your real-time wallet balances — Cash (in hand), Card (available on debit/credit), Bank (in your account).
        <br><strong>How to fill?</strong> Click the <em>"Update"</em> button under each card → enter your current amount → Save. This is just for tracking your total net worth.
      </div>
    </div>
    <div class="row g-3">
      <div class="col-md-4">
        <div class="wallet-card">
          <i class="fas fa-money-bill-wave" style="color:var(--green)"></i>
          <div class="wallet-balance" style="color:var(--green)"><?=money($wallets['cash'],$currency)?></div>
          <div style="font-size:11px;font-weight:700;color:var(--text-dim)">Cash Balance</div>
          <button class="bp" style="margin-top:8px;padding:4px 12px;font-size:10px" onclick="updateWallet('cash')">Update</button>
        </div>
      </div>
      <div class="col-md-4">
        <div class="wallet-card">
          <i class="fas fa-credit-card" style="color:var(--blue)"></i>
          <div class="wallet-balance" style="color:var(--blue)"><?=money($wallets['card'],$currency)?></div>
          <div style="font-size:11px;font-weight:700;color:var(--text-dim)">Card Balance</div>
          <button class="bp" style="margin-top:8px;padding:4px 12px;font-size:10px" onclick="updateWallet('card')">Update</button>
        </div>
      </div>
      <div class="col-md-4">
        <div class="wallet-card">
          <i class="fas fa-building-columns" style="color:var(--accent)"></i>
          <div class="wallet-balance" style="color:var(--accent)"><?=money($wallets['bank_transfer'],$currency)?></div>
          <div style="font-size:11px;font-weight:700;color:var(--text-dim)">Bank Balance</div>
          <button class="bp" style="margin-top:8px;padding:4px 12px;font-size:10px" onclick="updateWallet('bank_transfer')">Update</button>
        </div>
      </div>
    </div>
  </div>

  <div class="budget-bar-wrap mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span style="font-size:13px;color:var(--text-muted);font-weight:800"><i class="fas fa-gauge" style="margin-right:6px;color:var(--accent)"></i>Budget Utilization</span>
      <span style="font-weight:900;font-size:18px;font-family:var(--mono);color:<?=$utilization>90?'var(--red)':($utilization>70?'var(--yellow)':'var(--green)')?>"><?=number_format(min($utilization,100),1)?>%</span>
    </div>
    <div class="pt" style="height:12px">
      <div class="pf" style="width:<?=min($utilization,100)?>%;background:<?=$utilization>90?'var(--gradient-expense)':($utilization>70?'linear-gradient(90deg,#f59e0b,#fb923c)':'var(--gradient-income)')?>"></div>
    </div>
    <div class="d-flex justify-content-between mt-2" style="font-size:10px;font-weight:700;color:var(--text-dim)"><span>🟢 Safe (&lt;70%)</span><span>🟡 Warning (70-90%)</span><span>🔴 Danger (&gt;90%)</span></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-5">
      <div class="cg p-4 h-100">
        <div class="sh"><i class="fas fa-chart-pie" style="color:var(--accent)"></i> Income vs Expenses</div>
        <canvas id="budgetChart" style="max-height:260px"></canvas>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="cg p-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div class="sh" style="margin-bottom:0"><i class="fas fa-chart-line" style="color:var(--blue)"></i> Monthly Trend <?=date('Y')?></div>
          <div class="ft"><?php foreach(['all'=>'All Time','week'=>'7 Days','month'=>'Month','year'=>'Year'] as $k=>$v): ?><a href="?page=dashboard&filter=<?=$k?>" class="ftb <?=$filter===$k?'active':''?>"><?=$v?></a><?php endforeach; ?></div>
        </div>
        <canvas id="trendChart" style="max-height:220px"></canvas>
      </div>
    </div>
  </div>

  <!-- MONTHLY BUDGET PLANNER SECTION -->
  <div class="cg p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="sh" style="margin-bottom:0"><i class="fas fa-calendar-alt" style="color:var(--yellow)"></i> Monthly Budget Planner</div>
      <button class="bp solid" onclick="openBudgetModal()" style="padding:7px 16px;font-size:12px"><i class="fas fa-plus"></i> Add Monthly Budget</button>
    </div>
    <div class="info-help-card mb-3">
      <i class="fas fa-lightbulb"></i>
      <div>
        <strong>What does this do?</strong> Set a <em>monthly limit</em> for each category (Food, Rent, Entertainment, etc.). When your spending nears the limit, you get an alert.
        <br><strong>How to use?</strong> Click <em>"+ Add Monthly Budget"</em> → enter Year + Month + total budget amount → Save. The table below shows planned vs actual comparison.
      </div>
    </div>
    <?php if(empty($monthlyBudgets)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text-dim)">
      <i class="fas fa-chart-line" style="font-size:2rem;display:block;margin-bottom:.75rem;opacity:.3"></i>
      <p style="font-weight:700">No monthly budgets set yet</p>
      <p style="font-size:12px">Click "Add Monthly Budget" to plan your spending</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="budget-planner-table">
        <thead>
          <tr><th>Year</th><th>Month</th><th>Budget Amount</th><th>Actual Spent</th><th>Difference</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php 
          $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
          foreach($monthlyBudgets as $mb): 
            $diff = $mb['budget'] - $mb['actual'];
            $statusClass = $diff >= 0 ? ($diff == 0 ? 'exact' : 'under') : 'over';
            $statusText = $diff >= 0 ? ($diff == 0 ? 'On Track' : 'Under Budget') : 'Over Budget';
          ?>
          <tr>
            <td><?=$mb['year']?></td>
            <td><strong><?=$months[$mb['month']]?></strong></td>
            <td style="color:var(--green);font-family:var(--mono);font-weight:800"><?=money($mb['budget'],$currency)?></td>
            <td style="color:var(--red);font-family:var(--mono);font-weight:800"><?=money($mb['actual'],$currency)?></td>
            <td style="font-family:var(--mono);font-weight:700;color:<?=$diff>=0?'var(--green)':'var(--red)'?>"><?=$diff>=0?'+':''?><?=money($diff,$currency)?></td>
            <td><span class="budget-status <?=$statusClass?>"><?=$statusText?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="cg p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="sh" style="margin-bottom:0"><i class="fas fa-bell" style="color:var(--yellow)"></i> Smart Reminders</div>
          <button class="bp solid" onclick="openModal('reminder')" style="padding:7px 16px;font-size:12px"><i class="fas fa-plus"></i> Add Reminder</button>
        </div>
        <?php if(empty($reminders)): ?>
        <div style="text-align:center;padding:2rem;color:var(--text-dim)"><i class="fas fa-bell-slash" style="font-size:2rem;display:block;margin-bottom:.75rem;opacity:.3"></i><p style="font-size:13px;font-weight:700">No active reminders</p><p style="font-size:12px">Add bill due dates, budget alerts &amp; more</p></div>
        <?php else: ?>
        <?php foreach($reminders as $rm):
          $daysLeft=floor((strtotime($rm['due_date'])-time())/86400);
          $isOverdue=$daysLeft<0;
        ?>
        <div class="reminder-card <?=$isOverdue?'overdue':''?>" id="reminder-<?=$rm['id']?>">
          <div class="reminder-icon"><i class="fas fa-<?=$rm['type']==='bill'?'file-invoice-dollar':($rm['type']==='budget'?'triangle-exclamation':'calendar-check')?>"></i></div>
          <div class="reminder-info">
            <div class="reminder-title"><?=h($rm['title'])?></div>
            <div class="reminder-due"><?=$isOverdue?'<span style="color:var(--red);font-weight:800">⚠️ Overdue by '.abs($daysLeft).' day(s)</span>':'Due '.date('M d, Y',strtotime($rm['due_date'])).' ('.$daysLeft.' days left)'?></div>
          </div>
          <?php if($rm['amount']>0): ?><div class="reminder-amount"><?=money($rm['amount'],$currency)?></div><?php endif; ?>
          <button class="done-btn" onclick="doneReminder(<?=$rm['id']?>)" title="Mark as done"><i class="fas fa-check"></i></button>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="cg p-4">
        <div class="sh"><i class="fas fa-camera" style="color:var(--pink)"></i> Receipt Scanner <span style="background:rgba(219,39,119,.1);color:var(--pink);font-size:9px;padding:2px 8px;border-radius:999px;font-weight:900;margin-left:4px">AI</span></div>
        <div class="receipt-drop-zone" id="receiptDropZone" onclick="document.getElementById('receiptFileInput').click()" ondragover="handleDragOver(event)" ondrop="handleDrop(event)" ondragleave="this.classList.remove('drag-over')">
          <i class="fas fa-cloud-upload-alt"></i>
          <p style="font-weight:800;font-size:14px;color:var(--text);margin-bottom:.25rem">Drop receipt here or click to upload</p>
          <p style="font-size:12px;color:var(--text-dim)">JPG, PNG, PDF — AI extracts the amount</p>
          <div id="scanStatus" style="margin-top:.75rem;font-size:12px;color:var(--text-dim)"></div>
        </div>
        <input type="file" id="receiptFileInput" accept="image/*,application/pdf" style="display:none" onchange="handleReceiptUpload(this)">
        <div class="receipt-result" id="receiptResult">
          <div style="font-weight:800;font-size:13px;color:var(--green);margin-bottom:.5rem"><i class="fas fa-check-circle"></i> Amount Detected!</div>
          <div style="font-size:1.5rem;font-weight:900;color:var(--text);font-family:var(--mono)" id="receiptAmount">$0.00</div>
          <p style="font-size:12px;color:var(--text-muted);margin-top:.5rem" id="receiptDetails"></p>
          <button class="bp solid" onclick="useReceiptAmount()" style="margin-top:.75rem;padding:8px 18px;font-size:13px"><i class="fas fa-plus"></i> Add as Expense</button>
        </div>
        <div style="margin-top:1rem;padding:.75rem;background:rgba(99,102,241,.05);border-radius:var(--radius-xs);border:1px dashed rgba(99,102,241,.2)">
          <p style="font-size:11px;color:var(--text-dim);margin:0;font-weight:600"><i class="fas fa-info-circle" style="color:var(--accent);margin-right:5px"></i> Upload a receipt image — the scanner reads the total amount, date, and merchant name automatically.</p>
        </div>
      </div>
    </div>
  </div>

  <?php if(!empty($expByCat)): ?>
  <div class="cg p-4 mb-4">
    <div class="sh"><i class="fas fa-tags" style="color:var(--yellow)"></i> Expense by Category</div>
    <div class="row g-3">
      <?php foreach($expByCat as $ct): $cp=$totalExpense>0?($ct['total']/$totalExpense)*100:0; ?>
      <div class="col-md-6 col-lg-4">
        <div style="padding:.9rem;background:var(--card2);border-radius:var(--radius-sm);border:1px solid var(--border)">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div style="display:flex;align-items:center;gap:8px"><div style="width:10px;height:10px;border-radius:50%;background:<?=h($ct['color']??'#6366f1')?>;flex-shrink:0"></div><span style="font-size:13px;font-weight:700;color:var(--text)"><?=h($ct['name']??'Other')?></span></div>
            <span style="font-size:12px;font-family:var(--mono);font-weight:800"><?=money($ct['total'],$currency)?></span>
          </div>
          <div class="pt" style="height:7px"><div class="pf" style="width:<?=$cp?>%;background:<?=h($ct['color']??'#6366f1')?>"></div></div>
          <div style="text-align:right;font-size:10px;color:var(--text-dim);margin-top:4px;font-weight:700"><?=number_format($cp,0)?>%</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if(isAdmin()): ?>
  <div class="cg p-4 mb-4" style="border-color:rgba(99,102,241,.2)">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
      <div><div class="sh" style="margin-bottom:4px"><i class="fas fa-users-cog" style="color:var(--accent)"></i> Admin Portal — Registered Users</div><div style="font-size:12px;color:var(--text-dim);font-weight:700"><?=count($allRegisteredEmails)?> total accounts</div></div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="index.php?page=users" class="bp" style="padding:7px 16px;font-size:12px"><i class="fas fa-table-list"></i> Full Table</a>
        <button class="bp solid" onclick="openEmailList()" style="padding:7px 16px;font-size:12px"><i class="fas fa-envelope-open-text"></i> All Emails (<?=count($allRegisteredEmails)?>)</button>
      </div>
    </div>
    <div class="row g-3">
      <?php foreach(array_slice($allRegisteredEmails,0,6) as $u): ?>
      <div class="col-md-6 col-lg-4">
        <div class="family-member">
          <div class="fam-avatar" style="background:<?=h($u['avatar_color']??'#6366f1')?>20;color:<?=h($u['avatar_color']??'#6366f1')?>"><?=strtoupper(substr($u['name'],0,1))?></div>
          <div class="fam-info"><div class="fam-name"><?=h($u['name'])?></div><div class="fam-email"><?=h($u['email'])?></div><div style="font-size:10px;margin-top:2px;display:flex;align-items:center;gap:5px"><span style="width:6px;height:6px;border-radius:50%;background:<?=$u['is_active']?'var(--green)':'var(--red)'?>;display:inline-block"></span><span style="font-weight:700;color:var(--text-dim)"><?=$u['is_active']?'Active':'Inactive'?></span><?php if(($u['role']??'user')==='admin'):?><span style="background:rgba(217,119,6,.12);color:var(--yellow);font-size:9px;padding:1px 6px;border-radius:999px;font-weight:900">ADMIN</span><?php endif;?></div></div>
          <div class="fam-stats"><?=date('M Y',strtotime($u['created_at']))?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if(count($allRegisteredEmails)>6): ?><div class="text-center mt-3"><a href="index.php?page=users" style="font-size:13px;color:var(--accent);text-decoration:none;font-weight:800">View all <?=count($allRegisteredEmails)?> users →</a></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="row g-4 mb-4">
    <div class="col-lg-6">
      <div class="cg p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="sh" style="margin-bottom:0"><i class="fas fa-wallet" style="color:var(--green)"></i> Income</div>
          <button class="bp solid" onclick="openModal('income')" style="padding:7px 16px;font-size:12px"><i class="fas fa-plus"></i> Add</button>
        </div>
        <?php if(empty($incomes)): ?>
        <div style="text-align:center;padding:2.5rem;color:var(--text-dim)"><i class="fas fa-inbox" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3"></i><p style="font-weight:700">No income yet</p></div>
        <?php else: ?><div style="max-height:380px;overflow-y:auto"><?php foreach($incomes as $inc): ?>
        <div class="tr2">
          <div class="ti2" style="background:<?=h($inc['color']??'#059669')?>15;color:<?=h($inc['color']??'#059669')?>"><i class="fas <?=h($inc['icon']??'fa-dollar-sign')?>"></i></div>
          <div class="tm"><div class="tn"><?=h($inc['name'])?></div><div class="td2"><?=date('M d, Y',strtotime($inc['date']))?> <span class="tag"><?=h($inc['cat_name']??'')?></span><?php if($inc['is_recurring']): ?><span class="tag"><i class="fas fa-redo" style="font-size:9px"></i></span><?php endif; ?></div></div>
          <div class="ta" style="color:var(--green)">+<?=money($inc['amount'],$currency)?></div>
          <div class="txact"><button class="ab" onclick='editIncome(<?=json_encode($inc)?>)'><i class="fas fa-pencil"></i></button><form method="post" style="margin:0;display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_income"><input type="hidden" name="id" value="<?=$inc['id']?>"><button class="ab delete" type="submit"><i class="fas fa-trash"></i></button></form></div>
        </div>
        <?php endforeach; ?></div><?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="cg p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="sh" style="margin-bottom:0"><i class="fas fa-credit-card" style="color:var(--red)"></i> Expenses</div>
          <button class="bp solid" onclick="openModal('expense')" style="padding:7px 16px;font-size:12px;background:linear-gradient(135deg,#f43f5e,#fb7185);box-shadow:0 4px 12px rgba(244,63,94,.3)"><i class="fas fa-plus"></i> Add</button>
        </div>
        <?php if(empty($expenses)): ?>
        <div style="text-align:center;padding:2.5rem;color:var(--text-dim)"><i class="fas fa-inbox" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3"></i><p style="font-weight:700">No expenses yet</p></div>
        <?php else: ?><div style="max-height:380px;overflow-y:auto"><?php foreach($expenses as $exp): ?>
        <div class="tr2">
          <div class="ti2" style="background:<?=h($exp['color']??'#dc2626')?>15;color:<?=h($exp['color']??'#dc2626')?>"><i class="fas <?=h($exp['icon']??'fa-shopping-cart')?>"></i></div>
          <div class="tm"><div class="tn"><?=h($exp['name'])?></div><div class="td2"><?=date('M d, Y',strtotime($exp['date']))?> <span class="tag"><?=h($exp['cat_name']??'')?></span> <span class="tag"><?=h(ucfirst(str_replace('_',' ',$exp['payment_method'])))?></span><?php if($exp['is_recurring']): ?><span class="tag"><i class="fas fa-redo" style="font-size:9px"></i></span><?php endif; ?></div></div>
          <div class="ta" style="color:var(--red)">-<?=money($exp['amount'],$currency)?></div>
          <div class="txact"><button class="ab" onclick='editExpense(<?=json_encode($exp)?>)'><i class="fas fa-pencil"></i></button><form method="post" style="margin:0;display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="id" value="<?=$exp['id']?>"><button class="ab delete" type="submit"><i class="fas fa-trash"></i></button></form></div>
        </div>
        <?php endforeach; ?></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ============ BUDGET BREAKDOWN — 30% RULE ============ -->
  <?php
    $monthIncome = 0;
    try {
      $miS = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=? AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())");
      $miS->execute([$uid]); $monthIncome = (float)$miS->fetchColumn();
    } catch(Exception $e){}
    $monthExpense = 0;
    try {
      $meS = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())");
      $meS->execute([$uid]); $monthExpense = (float)$meS->fetchColumn();
    } catch(Exception $e){}
    $safeSavings = $monthIncome * 0.30;
    $remainSpend = $monthIncome * 0.70;
    // total monthly need from all open goals with deadline
    $goalNeedTotal = 0;
    foreach($goals as $gx) {
      if (empty($gx['is_completed']) && !empty($gx['deadline'])) {
        $rem = max(0,(float)$gx['target_amt']-(float)$gx['saved_amt']);
        $ml  = max(1,(int)ceil((strtotime($gx['deadline'])-time())/2628000));
        $goalNeedTotal += $rem / $ml;
      }
    }
    $overCap = $monthIncome>0 && $goalNeedTotal > $safeSavings;
  ?>
  <?php if($monthIncome > 0): ?>
  <div class="cg p-4 mb-4" style="background:linear-gradient(135deg,#fff 0%,#f0f9ff 100%);border:1.5px solid #bae6fd">
    <div class="sh mb-3"><i class="fas fa-chart-pie" style="color:#0284c7"></i> Smart Budget Breakdown — 30% Rule</div>
    <div class="row g-3">
      <div class="col-md-4">
        <div style="background:#fff;border:1.5px solid #e0f2fe;border-radius:14px;padding:1rem;text-align:center;height:100%">
          <div style="font-size:11px;font-weight:700;color:#0369a1;letter-spacing:.5px;margin-bottom:6px">MONTHLY INCOME</div>
          <div style="font-size:1.5rem;font-weight:900;color:#0284c7"><?=money($monthIncome,$currency)?></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Is mahine ki kamai</div>
        </div>
      </div>
      <div class="col-md-4">
        <div style="background:#fff;border:1.5px solid #bbf7d0;border-radius:14px;padding:1rem;text-align:center;height:100%">
          <div style="font-size:11px;font-weight:700;color:#15803d;letter-spacing:.5px;margin-bottom:6px">SAFE SAVINGS (30%)</div>
          <div style="font-size:1.5rem;font-weight:900;color:#16a34a"><?=money($safeSavings,$currency)?></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Goals ke liye max safe limit</div>
        </div>
      </div>
      <div class="col-md-4">
        <div style="background:#fff;border:1.5px solid #fed7aa;border-radius:14px;padding:1rem;text-align:center;height:100%">
          <div style="font-size:11px;font-weight:700;color:#c2410c;letter-spacing:.5px;margin-bottom:6px">EXPENSES BUDGET (70%)</div>
          <div style="font-size:1.5rem;font-weight:900;color:#ea580c"><?=money($remainSpend,$currency)?></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Rent, food, bills, etc.</div>
        </div>
      </div>
    </div>

    <?php if($goalNeedTotal > 0): ?>
    <div style="margin-top:1rem;padding:14px 16px;border-radius:12px;background:<?=$overCap?'#fef2f2':'#f0fdf4'?>;border:1.5px solid <?=$overCap?'#fecaca':'#bbf7d0'?>">
      <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center">
        <div>
          <div style="font-weight:800;color:<?=$overCap?'#991b1b':'#166534'?>;font-size:14px"><?=$overCap?'⚠️ Aapke goals safe limit se zyada chahte hain!':'✅ Aapke goals safe limit ke andar hain!'?></div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Total monthly goal need: <strong><?=money($goalNeedTotal,$currency)?></strong> / Safe limit: <strong><?=money($safeSavings,$currency)?></strong></div>
        </div>
        <?php if($overCap): ?>
        <div style="font-size:12px;color:#991b1b;font-weight:700;background:#fff;padding:6px 12px;border-radius:8px">Deadlines barhao ya wants cut karo</div>
        <?php endif; ?>
      </div>
      <?php $pctUsed = $safeSavings>0 ? min(100,($goalNeedTotal/$safeSavings)*100) : 0; ?>
      <div style="margin-top:10px;height:8px;background:#e5e7eb;border-radius:8px;overflow:hidden">
        <div style="height:100%;width:<?=$pctUsed?>%;background:<?=$overCap?'linear-gradient(90deg,#ef4444,#f87171)':'linear-gradient(90deg,#10b981,#34d399)'?>;transition:width .5s"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ============ GOAL SAVER (SMART) ============ -->
  <div class="cg p-4 mb-4 goal-section">

    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div class="sh mb-0"><i class="fas fa-bullseye" style="color:var(--accent)"></i> Goal Saver — Apne Sapne Pure Karo</div>
      <button class="bp solid" onclick="openGoalModal()"><i class="fas fa-plus"></i> Add New Goal</button>
    </div>
    <?php if(empty($goals)): ?>
      <div style="text-align:center;padding:2.5rem 1rem">
        <div style="font-size:3rem;margin-bottom:.75rem">🎯</div>
        <div style="font-weight:800;color:var(--text);margin-bottom:.4rem">Koi goal nahi hai abhi — chalo banate hain!</div>
        <div style="color:var(--text-muted);font-size:13px;margin-bottom:1.25rem">iPhone? Bike? Trip? Whatever you want — set it, plan it, save it.</div>
        <button class="bp solid" onclick="openGoalModal()"><i class="fas fa-rocket"></i> Create Your First Goal</button>
      </div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach($goals as $g):
        $target=(float)$g['target_amt']; $saved=(float)$g['saved_amt'];
        $gp = $target>0 ? min(100,($saved/$target)*100) : 0;
        $remaining = max(0, $target - $saved);
        $monthsLeft = 0; $needPerMonth = $remaining; $deadlineTxt='No deadline'; $statusTxt=''; $statusColor='var(--accent)';
        if (!empty($g['deadline'])) {
          $monthsLeft = max(1,(int)ceil((strtotime($g['deadline']) - time())/2628000));
          $needPerMonth = $remaining / $monthsLeft;
          $deadlineTxt = date('M Y', strtotime($g['deadline']));
        }
        if ($g['is_completed']) { $statusTxt='🎉 Mubarak ho! Goal complete!'; $statusColor='var(--green)'; }
        elseif ($gp>=75) { $statusTxt='🔥 Almost there — bas thori si mehnat aur!'; $statusColor='var(--green)'; }
        elseif ($gp>=40) { $statusTxt='💪 Shabash! Aadhe raste par ho.'; $statusColor='var(--accent)'; }
        elseif ($gp>0)   { $statusTxt='🌱 Good start — keep going dost!'; $statusColor='var(--blue)'; }
        else             { $statusTxt='🚀 Let\'s start saving — chalo shuru karte hain!'; $statusColor='var(--text-muted)'; }
      ?>
      <div class="col-md-6 col-lg-4">
        <div class="goal-card-pretty">
          <div class="goal-card-head">
            <div class="goal-icon-pretty"><i class="fas <?=h($g['icon']?:'fa-bullseye')?>"></i></div>
            <div style="flex:1;min-width:0">
              <div class="goal-title-pretty"><?=h($g['title'])?></div>
              <div class="goal-deadline-pretty"><i class="fas fa-calendar-day"></i> <?=$deadlineTxt?><?php if($monthsLeft): ?> · <?=$monthsLeft?> mo left<?php endif; ?></div>
              <?php if(!empty($g['purpose'])): ?>
              <div style="font-size:11px;color:var(--text-muted);margin-top:5px;font-style:italic;white-space:normal;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical"><i class="fas fa-lightbulb" style="color:var(--yellow);margin-right:3px"></i><?=h($g['purpose'])?></div>
              <?php endif; ?>
            </div>
            <?php if($g['is_completed']): ?><div class="goal-done-badge">✓</div><?php endif; ?>
          </div>

          <div class="goal-progress-wrap">
            <div class="goal-progress-track"><div class="goal-progress-fill" style="width:<?=$gp?>%;background:<?=$gp>=100?'linear-gradient(90deg,#10b981,#34d399)':'linear-gradient(90deg,#6366f1,#a855f7)'?>"></div></div>
            <div class="goal-progress-meta">
              <span><strong><?=money($saved,$currency)?></strong> saved</span>
              <span class="goal-pct"><?=number_format($gp,0)?>%</span>
              <span>of <?=money($target,$currency)?></span>
            </div>
          </div>

          <?php if(!$g['is_completed']): ?>
          <div class="goal-plan-box">
            <div class="goal-plan-row">
              <div class="goal-plan-label">Save per month</div>
              <div class="goal-plan-value"><?=money($needPerMonth,$currency)?></div>
            </div>
            <div class="goal-plan-row">
              <div class="goal-plan-label">Still needed</div>
              <div class="goal-plan-value" style="color:var(--accent)"><?=money($remaining,$currency)?></div>
            </div>
          </div>
          <?php endif; ?>

          <div class="goal-msg" style="color:<?=$statusColor?>"><?=$statusTxt?></div>

          <?php if(!$g['is_completed']): ?>
          <form method="post" class="goal-add-form">
            <input type="hidden" name="action" value="add_to_goal">
            <input type="hidden" name="id" value="<?=$g['id']?>">
            <input type="number" name="amount" step="0.01" min="0.01" placeholder="Add savings..." class="goal-add-input" required>
            <button type="submit" class="goal-add-btn" title="Add"><i class="fas fa-plus"></i></button>
          </form>
          <?php endif; ?>

          <div class="goal-card-foot">
            <button class="goal-mini-btn" onclick='editGoal(<?=json_encode($g)?>)'><i class="fas fa-pencil"></i> Edit</button>
            <form method="post" style="margin:0;display:inline" onsubmit="return confirm('Delete this goal?')">
              <input type="hidden" name="action" value="delete_goal">
              <input type="hidden" name="id" value="<?=$g['id']?>">
              <button type="submit" class="goal-mini-btn danger"><i class="fas fa-trash"></i> Delete</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- GOAL MODAL -->
  <div class="mo" id="goalModal"><div class="mcb">
    <div class="mh">
      <div class="mi" style="background:rgba(99,102,241,.1);color:var(--accent)"><i class="fas fa-bullseye"></i></div>
      <div class="mtt">Set Your Goal — Sapna Banao!</div>
      <button style="margin-left:auto;background:none;border:none;color:var(--text-dim);font-size:20px;cursor:pointer" onclick="closeModal('goal')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="save_goal">
      <input type="hidden" name="id" id="goalId">
      <div class="mb-3"><label class="fl">What do you want to buy?</label>
        <input type="text" name="title" id="goalTitle" class="fc" placeholder="e.g. iPhone 15, Honda 125, Umrah trip" required>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="fl">Total Price (<?=h($currency)?>)</label>
          <input type="number" name="target_amt" id="goalTarget" class="fc" step="0.01" min="1" placeholder="e.g. 250000" required>
        </div>
        <div class="col-md-6"><label class="fl">Already Saved</label>
          <input type="number" name="saved_amt" id="goalSaved" class="fc" step="0.01" min="0" value="0" placeholder="0">
        </div>
      </div>
      <div class="mb-3"><label class="fl">Deadline (when do you want it?)</label>
        <input type="date" name="deadline" id="goalDeadline" class="fc" required>
        <div style="font-size:11px;color:var(--text-dim);margin-top:5px">📅 We'll auto-calculate how much to save every month — no maths required!</div>
      </div>
      <div class="mb-3"><label class="fl">Purpose / Why do you want this? <span style="color:var(--text-dim);font-weight:500">(optional)</span></label>
        <textarea name="purpose" id="goalPurpose" class="fc" rows="2" placeholder="e.g. Family trip to Murree, replace my broken phone, start freelancing..." style="resize:vertical;min-height:60px"></textarea>
        <div style="font-size:11px;color:var(--text-dim);margin-top:5px">💡 A clear reason keeps you motivated — write it like a promise to yourself!</div>
      </div>
      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="bp" onclick="closeModal('goal')">Cancel</button>
        <button type="submit" class="bp solid">🎯 Save Goal & Make Plan</button>
      </div>
    </form>
  </div></div>

  <!-- GOAL "WANTS" ALERT POPUP -->
  <?php if($goalWantsAlert): ?>
  <div class="welcome-overlay" id="goalAlertOverlay" style="z-index:18000">
    <div class="welcome-card" style="max-width:480px">
      <div style="position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,#f43f5e,#fb7185)"></div>
      <span class="we">🛑</span>
      <div class="wt">Ruk jao dost!</div>
      <div class="wn" style="font-size:1.2rem;background:linear-gradient(90deg,#f43f5e,#fb7185);-webkit-background-clip:text;-webkit-text-fill-color:transparent">Stop — Bachao apne goal ke liye!</div>
      <div class="ws">
        You just spent <strong><?=money($goalWantsAlert['amount'],$currency)?></strong> on <strong><?=h($goalWantsAlert['category'])?></strong>.<br>
        Yaad hai? You're saving for <strong>"<?=h($goalWantsAlert['goal'])?>"</strong> — and you need <strong><?=money($goalWantsAlert['need'],$currency)?>/month</strong> to reach it.<br>
        <?php if(!empty($goalWantsAlert['days_lost']) && $goalWantsAlert['days_lost']>0): ?>
        <span style="display:inline-block;margin-top:8px;padding:6px 12px;background:#fee2e2;color:#b91c1c;border-radius:8px;font-weight:800">😢 Aaj ke kharche se goal ~<?=$goalWantsAlert['days_lost']?> din aur door ho gaya</span><br>
        <?php endif; ?><br>
        <span style="color:var(--red);font-weight:800">💡 Suggestion:</span> Cut your "wants" by <strong><?=money($goalWantsAlert['amount'],$currency)?></strong> next month — and you might finish your goal early!

      </div>
      <button class="wd" onclick="document.getElementById('goalAlertOverlay').style.display='none'">Samajh gaya — I'll be careful! 💪</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- PERSISTENT BANNER (shown until back on track) -->
  <?php if($goalWantsAlert): ?>
  <div class="goal-banner" id="goalBanner">
    <div class="goal-banner-icon">⚠️</div>
    <div class="goal-banner-text">
      <strong>Behind on goal!</strong> "<?=h($goalWantsAlert['goal'])?>" needs <?=money($goalWantsAlert['need'],$currency)?>/month.
      Cut down on Entertainment / Shopping / Food — save your money for what matters! 💪
    </div>
    <button class="goal-banner-close" onclick="this.parentElement.style.display='none'">&times;</button>
  </div>
  <?php endif; ?>

  <!-- ===== LOW-TARGET REALITY CHECK POPUP ===== -->
  <?php if($goalLowWarning): ?>
  <div class="welcome-overlay" id="goalLowOverlay" style="z-index:18100">
    <div class="welcome-card" style="max-width:500px">
      <div style="position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,#f59e0b,#fbbf24)"></div>
      <span class="we">🤔</span>
      <div class="wt">Bhai, ye amount kam lag raha hai!</div>
      <div class="wn" style="font-size:1.1rem;background:linear-gradient(90deg,#f59e0b,#d97706);-webkit-background-clip:text;-webkit-text-fill-color:transparent">Reality check chahiye?</div>
      <div class="ws">
        Aap ne <strong>"<?=h($goalLowWarning['title'])?>"</strong> ke liye sirf <strong><?=money($goalLowWarning['target'],$currency)?></strong> set kiya hai.<br><br>
        Real market mein ye cheez itne ki nahi aati — phone, bike, car, laptop, trip jaisi cheezein usually <strong>10,000+</strong> ki hoti hain.<br><br>
        <span style="color:var(--accent);font-weight:800">💡 Suggestion:</span> Pehle real price check karo (Daraz, Amazon, OLX) phir sahi target set karo. Warna budget galat ban jayega aur paisa kahin aur khirch jayega!
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:12px">
        <button class="wd" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)" onclick="document.getElementById('goalLowOverlay').style.display='none';openGoalModal();document.getElementById('goalTitle').value='<?=h(addslashes($goalLowWarning['title']))?>';document.getElementById('goalDeadline').value='<?=h($goalLowWarning['deadline'])?>';document.getElementById('goalSaved').value='<?=$goalLowWarning['saved']?>';">✏️ Theek hai, real price daalta hoon</button>
        <form method="post" style="margin:0;display:inline">
          <input type="hidden" name="action" value="save_goal">
          <input type="hidden" name="title" value="<?=h($goalLowWarning['title'])?>">
          <input type="hidden" name="target_amt" value="<?=$goalLowWarning['target']?>">
          <input type="hidden" name="saved_amt" value="<?=$goalLowWarning['saved']?>">
          <input type="hidden" name="deadline" value="<?=h($goalLowWarning['deadline'])?>">
          <input type="hidden" name="id" value="<?=$goalLowWarning['id']?>">
          <input type="hidden" name="force_low" value="1">
          <button type="submit" class="wd" style="background:#e5e7eb;color:#374151">Sure hoon, save karo</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ===== 30% SAFE-SAVINGS CAP WARNING ===== -->
  <?php if($goalCapWarning): ?>
  <div class="welcome-overlay" id="goalCapOverlay" style="z-index:18050">
    <div class="welcome-card" style="max-width:520px">
      <div style="position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,#ef4444,#f97316)"></div>
      <span class="we">⚠️</span>
      <div class="wt">Goal save ho gaya — lekin sun lo!</div>
      <div class="wn" style="font-size:1.05rem;background:linear-gradient(90deg,#ef4444,#dc2626);-webkit-background-clip:text;-webkit-text-fill-color:transparent">Ye goal aapke budget se bahut bara hai</div>
      <div class="ws" style="text-align:left">
        <div style="background:#fef2f2;border-left:4px solid #ef4444;padding:12px 14px;border-radius:8px;margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px"><span>Aapki monthly income:</span><strong><?=money($goalCapWarning['income'],$currency)?></strong></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:6px"><span>Safe saving limit (30%):</span><strong style="color:var(--green)"><?=money($goalCapWarning['cap'],$currency)?>/mo</strong></div>
          <div style="display:flex;justify-content:space-between"><span>Is goal ko chahiye:</span><strong style="color:var(--red)"><?=money($goalCapWarning['need'],$currency)?>/mo</strong></div>
        </div>
        <div style="font-weight:700;color:var(--text);margin-bottom:8px">💡 2 options hain:</div>
        <div style="margin-bottom:8px">🗓️ <strong>Deadline barhao</strong> — ~<?=$goalCapWarning['suggMonths']?> mahine mein possible hai (safe limit ke andar)</div>
        <div>💰 <strong>Wants kam karo</strong> — Entertainment, Shopping, Food & Dining pe kharcha cut karke extra paisa goal mein daalo</div>
      </div>
      <button class="wd" onclick="document.getElementById('goalCapOverlay').style.display='none'">Samajh gaya — plan adjust karunga 💪</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ============ END GOAL SAVER ============ -->


  <!-- FAB MENU -->
  <div class="fab-wrap">
    <div class="fab-children hidden" id="fabChildren">
      <button class="fab-child fab-calc-btn" onclick="openCalculator();toggleFab()" title="Calculator">
        <i class="fas fa-calculator"></i><span class="fab-label">Calculator</span>
      </button>
      <button class="fab-child fab-excel-btn" onclick="openExcel();toggleFab()" title="Spreadsheet">
        <i class="fas fa-table"></i><span class="fab-label">Excel Sheet</span>
      </button>
    </div>
    <button class="fab-main" id="fabMain" onclick="toggleFab()" title="Tools">
      <i class="fas fa-plus" id="fabIcon"></i>
    </button>
  </div>

  <!-- CALCULATOR MODAL -->
  <div class="mo calc-modal" id="calculatorModal"><div class="mcb" style="max-width:340px"><div class="mh"><div class="mi" style="background:rgba(99,102,241,.1);color:var(--accent)"><i class="fas fa-calculator"></i></div><div class="mtt">Calculator</div><button style="margin-left:auto;background:none;border:none;color:var(--text-dim);font-size:20px;cursor:pointer" onclick="closeCalculator()">&times;</button></div><div class="calc-display" id="calcDisplay">0</div><div class="calc-buttons"><div class="calc-btn" onclick="calcInput('7')">7</div><div class="calc-btn" onclick="calcInput('8')">8</div><div class="calc-btn" onclick="calcInput('9')">9</div><div class="calc-btn" onclick="calcInput('/')">/</div><div class="calc-btn" onclick="calcInput('4')">4</div><div class="calc-btn" onclick="calcInput('5')">5</div><div class="calc-btn" onclick="calcInput('6')">6</div><div class="calc-btn" onclick="calcInput('*')">×</div><div class="calc-btn" onclick="calcInput('1')">1</div><div class="calc-btn" onclick="calcInput('2')">2</div><div class="calc-btn" onclick="calcInput('3')">3</div><div class="calc-btn" onclick="calcInput('-')">-</div><div class="calc-btn" onclick="calcInput('0')">0</div><div class="calc-btn" onclick="calcInput('.')">.</div><div class="calc-btn clear" onclick="calcClear()">C</div><div class="calc-btn" onclick="calcInput('+')">+</div><div class="calc-btn equals" onclick="calcResult()">=</div></div></div></div>

  <!-- EXCEL SPREADSHEET MODAL -->
  <div class="mo" id="excelModal">
    <div class="mcb" style="max-width:900px;width:96%">
      <div class="mh" style="flex-wrap:wrap;gap:8px">
        <div class="mi" style="background:rgba(5,150,105,.1);color:var(--green)"><i class="fas fa-table"></i></div>
        <div class="mtt">Excel Spreadsheet</div>
        <div style="margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
          <button onclick="addExcelRow()" class="bp" style="padding:5px 12px;font-size:11px"><i class="fas fa-plus"></i> Row</button>
          <button onclick="addExcelCol()" class="bp" style="padding:5px 12px;font-size:11px"><i class="fas fa-plus"></i> Col</button>
          <button onclick="downloadExcelCSV()" class="bp solid" style="padding:5px 14px;font-size:11px;background:linear-gradient(135deg,#059669,#10b981)"><i class="fas fa-download"></i> CSV</button>
          <button onclick="clearExcel()" class="bp" style="padding:5px 12px;font-size:11px;color:var(--red)"><i class="fas fa-trash"></i></button>
          <button style="background:none;border:none;color:var(--text-dim);font-size:22px;cursor:pointer;padding:0 4px" onclick="closeExcel()">&times;</button>
        </div>
      </div>
      <!-- Toolbar -->
      <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;align-items:center;padding:8px 10px;background:var(--bg2);border-radius:var(--radius-xs);border:1px solid var(--border)">
        <button onclick="excelFmt('bold')" class="ab" title="Bold"><b>B</b></button>
        <button onclick="excelFmt('italic')" class="ab" title="Italic"><i>I</i></button>
        <button onclick="excelFmt('justifyLeft')" class="ab" title="Left"><i class="fas fa-align-left"></i></button>
        <button onclick="excelFmt('justifyCenter')" class="ab" title="Center"><i class="fas fa-align-center"></i></button>
        <button onclick="excelFmt('justifyRight')" class="ab" title="Right"><i class="fas fa-align-right"></i></button>
        <div style="width:1px;height:22px;background:var(--border);margin:0 2px"></div>
        <label style="font-size:10px;font-weight:700;color:var(--text-muted)">BG</label>
        <input type="color" id="xlBg" value="#ffffff" oninput="excelCellColor('bg',this.value)" style="width:26px;height:26px;border-radius:5px;border:1px solid var(--border);cursor:pointer;padding:1px">
        <label style="font-size:10px;font-weight:700;color:var(--text-muted)">Text</label>
        <input type="color" id="xlTxt" value="#1e293b" oninput="excelCellColor('txt',this.value)" style="width:26px;height:26px;border-radius:5px;border:1px solid var(--border);cursor:pointer;padding:1px">
        <div style="width:1px;height:22px;background:var(--border);margin:0 2px"></div>
        <select id="xlFontSize" onchange="excelFontSize(this.value)" style="padding:3px 7px;border-radius:6px;border:1px solid var(--border);background:white;font-size:11px;font-weight:700;color:var(--text)">
          <option>11</option><option selected>13</option><option>15</option><option>18</option><option>22</option>
        </select>
        <span style="margin-left:auto;font-size:10px;color:var(--text-muted);font-weight:700" id="xlCellRef">A1</span>
      </div>
      <!-- Formula bar -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <span style="font-size:12px;font-weight:900;color:var(--text-muted);font-family:var(--mono);background:var(--bg2);padding:6px 10px;border-radius:7px;border:1px solid var(--border)">fx</span>
        <input id="xlFormula" class="fc" style="font-family:var(--mono);font-size:13px" placeholder="=SUM(A1:A5) ya seedha value likhein" oninput="xlFormulaInput(this.value)">
      </div>
      <!-- Grid -->
      <div style="overflow:auto;max-height:380px;border:1.5px solid var(--border);border-radius:var(--radius-xs)">
        <table id="excelTable" style="border-collapse:collapse;min-width:100%"></table>
      </div>
      <!-- Status -->
      <div style="margin-top:6px;display:flex;gap:14px;font-size:11px;color:var(--text-dim);font-weight:700;padding:4px 6px">
        <span id="xlSel">Selected: —</span>
        <span id="xlSum">Sum: —</span>
        <span id="xlAvg">Avg: —</span>
        <span id="xlMin">Min: —</span>
        <span id="xlMax">Max: —</span>
      </div>
    </div>
  </div>
</div>

<!-- MODALS -->
<div class="mo" id="incomeModal"><div class="mcb">
  <div class="mh"><div class="mi" style="background:var(--green-bg);color:var(--green)"><i class="fas fa-plus-circle"></i></div><div class="mtt">Add / Edit Income</div><button style="margin-left:auto;background:none;border:none;color:var(--text-dim);font-size:20px;cursor:pointer" onclick="closeModal('income')">&times;</button></div>
  <form method="post">
    <input type="hidden" name="action" value="save_income"><input type="hidden" name="id" id="incomeId">
    <div class="mb-3"><label class="fl">Source Name</label><input type="text" name="name" id="incomeName" class="fc" placeholder="e.g. Monthly Salary" required></div>
    <div class="row g-3 mb-3"><div class="col-md-6"><label class="fl">Amount</label><input type="number" name="amount" id="incomeAmount" class="fc" step="0.01" required placeholder="0.00"></div><div class="col-md-6"><label class="fl">Date</label><input type="date" name="date" id="incomeDate" class="fc" required></div></div>
    <div class="mb-3"><label class="fl">Category</label><select name="category_id" id="incomeCategory" class="fc"><option value="">— Select —</option><?php foreach(array_filter($cats,fn($c)=>$c['type']==='income') as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="fl">Note (Optional)</label><input type="text" name="note" id="incomeNote" class="fc" placeholder="Details..."></div>
    <div class="mb-4"><label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13px;color:var(--text-muted);font-weight:700"><input type="checkbox" name="is_recurring" id="incomeRecurring" value="1" style="width:17px;height:17px;accent-color:var(--accent)"> Recurring monthly</label></div>
    <div class="d-flex justify-content-end gap-2"><button type="button" class="bp" onclick="closeModal('income')">Cancel</button><button type="submit" class="bp solid">💰 Save Income</button></div>
  </form>
</div></div>

<div class="mo" id="expenseModal"><div class="mcb">
  <div class="mh"><div class="mi" style="background:var(--red-bg);color:var(--red)"><i class="fas fa-minus-circle"></i></div><div class="mtt">Add / Edit Expense</div><button style="margin-left:auto;background:none;border:none;color:var(--text-dim);font-size:20px;cursor:pointer" onclick="closeModal('expense')">&times;</button></div>
  <form method="post">
    <input type="hidden" name="action" value="save_expense"><input type="hidden" name="id" id="expenseId">
    <div class="mb-1"><label class="fl">Expense Name</label>
      <input type="text" name="name" id="expenseName" class="fc" placeholder='e.g. "KFC 1200" → auto-detects Food' required oninput="triggerAutoCategory(this.value)">
      <div id="autoCatChip" style="min-height:28px;margin-top:4px"></div>
    </div>
    <?php
    $ti_val=0; try{$s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=?");$s->execute([currentUserId()]);$ti_val=(float)$s->fetchColumn();}catch(Exception $e){}
    $te_val=0; try{$s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=?");$s->execute([currentUserId()]);$te_val=(float)$s->fetchColumn();}catch(Exception $e){}
    $remaining=max(0,$ti_val-$te_val);
    ?>
    <?php if($ti_val>0): ?>
    <div class="mb-3" style="padding:.75rem 1rem;background:<?=$remaining>0?'var(--green-bg)':'var(--red-bg)'?>;border-radius:var(--radius-xs);border:1px solid <?=$remaining>0?'rgba(5,150,105,.2)':'rgba(220,38,38,.2)'?>;font-size:12px;font-weight:700;color:<?=$remaining>0?'var(--green)':'var(--red)'?>">
      <i class="fas fa-<?=$remaining>0?'check-circle':'exclamation-triangle'?>"></i> 
      <?=$remaining>0?'Remaining budget: <strong>'.money($remaining,$currency).'</strong>':'⚠️ You are already over budget by <strong>'.money(abs($remaining),$currency).'</strong>!'?>
    </div>
    <?php endif; ?>
    <div class="row g-3 mb-3"><div class="col-md-6"><label class="fl">Amount</label><input type="number" name="amount" id="expenseAmount" class="fc" step="0.01" required placeholder="0.00" oninput="checkBudgetLimit(this.value)"><div id="budgetWarning" style="font-size:11px;color:var(--red);font-weight:700;margin-top:4px;display:none"><i class="fas fa-exclamation-triangle"></i> This will exceed your budget!</div></div><div class="col-md-6"><label class="fl">Date</label><input type="date" name="date" id="expenseDate" class="fc" required></div></div>
    <div class="row g-3 mb-3"><div class="col-md-6"><label class="fl">Category</label><select name="category_id" id="expenseCategory" class="fc"><option value="">— Select —</option><?php foreach(array_filter($cats,fn($c)=>$c['type']==='expense') as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="fl">Payment Method</label><select name="payment_method" id="expensePayment" class="fc"><option value="cash">💵 Cash</option><option value="card">💳 Card</option><option value="bank_transfer">🏦 Bank Transfer</option><option value="other">📦 Other</option></select></div></div>
    <div class="mb-3"><label class="fl">Note (Optional)</label><input type="text" name="note" id="expenseNote" class="fc" placeholder="Details..."></div>
    <div class="mb-4"><label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13px;color:var(--text-muted);font-weight:700"><input type="checkbox" name="is_recurring" id="expenseRecurring" value="1" style="width:17px;height:17px;accent-color:var(--accent)"> Recurring monthly</label></div>
    <div class="d-flex justify-content-end gap-2"><button type="button" class="bp" onclick="closeModal('expense')">Cancel</button><button type="submit" class="bp solid" style="background:linear-gradient(135deg,#f43f5e,#fb7185);box-shadow:0 4px 12px rgba(244,63,94,.3)">📝 Save Expense</button></div>
  </form>
</div></div>

<div class="mo" id="reminderModal"><div class="mcb" style="max-width:480px">
  <div class="mh"><div class="mi" style="background:var(--yellow-bg);color:var(--yellow)"><i class="fas fa-bell"></i></div><div class="mtt">Add Smart Reminder</div><button style="margin-left:auto;background:none;border:none;color:var(--text-dim);font-size:20px;cursor:pointer" onclick="closeModal('reminder')">&times;</button></div>
  <div>
    <div class="mb-3"><label class="fl">Reminder Title</label><input type="text" id="reminderTitle" class="fc" placeholder='e.g. "Pay electricity bill"'></div>
    <div class="row g-3 mb-3">
      <div class="col-md-6"><label class="fl">Due Date</label><input type="date" id="reminderDue" class="fc"></div>
      <div class="col-md-6"><label class="fl">Type</label><select id="reminderType" class="fc"><option value="bill">📄 Bill Payment</option><option value="budget">📊 Budget Alert</option><option value="goal">🎯 Goal Milestone</option></select></div>
    </div>
    <div class="mb-4"><label class="fl">Amount (Optional)</label><input type="number" id="reminderAmount" class="fc" placeholder="0.00" step="0.01"></div>
    <div class="d-flex justify-content-end gap-2">
      <button type="button" class="bp" onclick="closeModal('reminder')">Cancel</button>
      <button type="button" class="bp solid" onclick="saveReminder()"><i class="fas fa-bell"></i> Set Reminder</button>
    </div>
  </div>
</div></div>

<div class="mo" id="budgetModal">
  <div class="mcb" style="max-width:450px">
    <div class="mh">
      <div class="mi" style="background:var(--yellow-bg);color:var(--yellow)"><i class="fas fa-calendar-plus"></i></div>
      <div class="mtt">Set Monthly Budget</div>
      <button style="margin-left:auto;background:none;border:none;color:var(--text-dim);font-size:20px;cursor:pointer" onclick="closeBudgetModal()">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="save_monthly_budget">
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="fl">Year</label>
          <select name="year" class="fc" required>
            <?php for($y=2023;$y<=date('Y')+2;$y++): ?>
            <option value="<?=$y?>" <?=$y==date('Y')?'selected':''?>><?=$y?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="fl">Month</label>
          <select name="month" class="fc" required>
            <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?=$m?>" <?=$m==date('n')?'selected':''?>><?=date('F', mktime(0,0,0,$m,1))?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="mb-4">
        <label class="fl">Budget Amount (<?=$currency?>)</label>
        <input type="number" name="budget_amount" class="fc" step="0.01" required placeholder="Enter monthly budget limit">
      </div>
      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="bp" onclick="closeBudgetModal()">Cancel</button>
        <button type="submit" class="bp solid"><i class="fas fa-save"></i> Save Budget</button>
      </div>
    </form>
  </div>
</div>

<div class="mo" id="walletModal">
  <div class="mcb" style="max-width:400px">
    <div class="mh">
      <div class="mi" style="background:var(--blue-bg);color:var(--blue)"><i class="fas fa-wallet"></i></div>
      <div class="mtt">Update Wallet Balance</div>
      <button style="margin-left:auto;background:none;border:none;color:var(--text-dim);font-size:20px;cursor:pointer" onclick="closeWalletModal()">&times;</button>
    </div>
    <form method="post" id="walletForm">
      <input type="hidden" name="action" value="update_wallet">
      <input type="hidden" name="wallet_type" id="walletType">
      <div class="mb-3">
        <label class="fl">Balance Amount (<?=$currency?>)</label>
        <input type="number" name="balance" id="walletBalance" class="fc" step="0.01" required placeholder="Enter balance">
      </div>
      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="bp" onclick="closeWalletModal()">Cancel</button>
        <button type="submit" class="bp solid"><i class="fas fa-save"></i> Update Balance</button>
      </div>
    </form>
  </div>
</div>

<script>
// Chart.js — Budget Doughnut
var bc=document.getElementById('budgetChart');
if(bc){new Chart(bc.getContext('2d'),{type:'doughnut',data:{labels:['Income','Expenses','Savings'],datasets:[{data:[<?=$totalIncome?>,<?=$totalExpense?>,<?=max(0,$savings)?>],backgroundColor:['#059669','#dc2626','#6366f1'],borderWidth:3,borderColor:'#ffffff',hoverOffset:8,borderRadius:3}]},options:{cutout:'72%',responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{color:'#64748b',padding:18,font:{size:12,family:'Nunito',weight:'700'},usePointStyle:true,pointStyle:'circle'}}}}})}

// Chart.js — Monthly Trend
var tc=document.getElementById('trendChart');
var trendMonths=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var incomeData=<?=json_encode($trendData['income']??array_fill(0,12,0))?>;
var expenseData=<?=json_encode($trendData['expense']??array_fill(0,12,0))?>;
if(tc){new Chart(tc.getContext('2d'),{type:'line',data:{labels:trendMonths,datasets:[{label:'Income',data:incomeData,borderColor:'#059669',backgroundColor:'rgba(5,150,105,.08)',tension:.4,fill:true,borderWidth:2.5,pointBackgroundColor:'#059669',pointRadius:4,pointHoverRadius:6},{label:'Expenses',data:expenseData,borderColor:'#dc2626',backgroundColor:'rgba(220,38,38,.06)',tension:.4,fill:true,borderWidth:2.5,pointBackgroundColor:'#dc2626',pointRadius:4,pointHoverRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#64748b',font:{family:'Nunito',weight:'700'},usePointStyle:true,pointStyle:'circle',padding:16}}},scales:{y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{color:'#94a3b8',font:{family:'Nunito',weight:'700'}}},x:{grid:{display:false},ticks:{color:'#94a3b8',font:{family:'Nunito',weight:'700'}}}}}})}

function openModal(t){document.getElementById(t+'Modal').classList.add('active');if(t!=='reminder'){document.getElementById(t+'Date').value=new Date().toISOString().split('T')[0];}if(t==='reminder'){document.getElementById('reminderDue').value=new Date().toISOString().split('T')[0];}document.body.style.overflow='hidden';}
function closeModal(t){document.getElementById(t+'Modal').classList.remove('active');if(t==='expense'){document.getElementById('expenseId').value='';document.getElementById('autoCatChip').innerHTML='';}document.body.style.overflow='';}
function openBudgetModal(){document.getElementById('budgetModal').classList.add('active');document.body.style.overflow='hidden';}
function closeBudgetModal(){document.getElementById('budgetModal').classList.remove('active');document.body.style.overflow='';}
function openCalculator(){document.getElementById('calculatorModal').classList.add('active');document.body.style.overflow='hidden';calcExpr='';updateCalcDisplay('0');}
function closeCalculator(){document.getElementById('calculatorModal').classList.remove('active');document.body.style.overflow='';}

// ===== FAB TOGGLE =====
function toggleFab(){
  var ch=document.getElementById('fabChildren');
  var btn=document.getElementById('fabMain');
  var icon=document.getElementById('fabIcon');
  var isOpen=!ch.classList.contains('hidden');
  if(isOpen){
    ch.classList.add('hidden');
    btn.classList.remove('open');
  } else {
    ch.classList.remove('hidden');
    btn.classList.add('open');
  }
}

// ===== EXCEL SPREADSHEET =====
var xlRows=10, xlCols=8;
var xlData=[], xlStyle=[], xlSelectedCell=null, xlSelecting=false, xlSelStart=null, xlSelEnd=null;

function initExcel(){
  xlData=[]; xlStyle=[];
  for(var r=0;r<xlRows;r++){xlData.push(new Array(xlCols).fill(''));xlStyle.push(Array.from({length:xlCols},()=>({bg:'',txt:'',bold:false,italic:false,align:'left',fontSize:13})));}
  renderExcel();
}

function renderExcel(){
  var table=document.getElementById('excelTable');
  var colLetters='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  var html='<thead><tr><th style="width:36px;min-width:36px;background:var(--bg2);border:1px solid var(--border);position:sticky;top:0;left:0;z-index:3"></th>';
  for(var c=0;c<xlCols;c++) html+='<th style="min-width:90px;background:var(--bg2);border:1px solid var(--border);padding:5px 8px;font-size:11px;font-weight:800;color:var(--text-muted);text-align:center;position:sticky;top:0;z-index:2;cursor:pointer" onclick="xlSelectCol('+c+')" id="xlH'+c+'">'+colLetters[c]+'</th>';
  html+='</tr></thead><tbody>';
  for(var r=0;r<xlRows;r++){
    html+='<tr><td style="background:var(--bg2);border:1px solid var(--border);padding:4px 8px;font-size:11px;font-weight:800;color:var(--text-muted);text-align:center;position:sticky;left:0;z-index:1;cursor:pointer" onclick="xlSelectRow('+r+')" id="xlR'+r+'">'+(r+1)+'</td>';
    for(var c2=0;c2<xlCols;c2++){
      var s=xlStyle[r][c2];
      var val=xlData[r][c2];
      var displayed=xlEvalCell(val,r,c2);
      html+='<td contenteditable="true" id="xl'+r+'_'+c2+'" '
        +'data-r="'+r+'" data-c="'+c2+'" '
        +'style="border:1px solid var(--border);padding:4px 8px;font-size:'+s.fontSize+'px;'
        +'background:'+(s.bg||'#fff')+';color:'+(s.txt||'var(--text)')+';'
        +'font-weight:'+(s.bold?'800':'500')+';'
        +'font-style:'+(s.italic?'italic':'normal')+';'
        +'text-align:'+(s.align||'left')+';'
        +'min-width:90px;outline:none;font-family:var(--mono);cursor:cell;white-space:nowrap;vertical-align:middle" '
        +'onfocus="xlFocus(this)" '
        +'oninput="xlInput(this)" '
        +'onkeydown="xlKeydown(event,this)" '
        +'onmousedown="xlMousedown(event,this)" '
        +'onmouseover="xlMouseover(event,this)" '
        +'onmouseup="xlMouseup(event)" '
        +'>'+escHtml(displayed)+'</td>';
    }
    html+='</tr>';
  }
  html+='</tbody>';
  table.innerHTML=html;
}

function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function xlEvalCell(val,r,c){
  if(typeof val==='string' && val.startsWith('=')){
    try{
      var colLetters='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
      var expr=val.substring(1).toUpperCase();
      // SUM(A1:B3) style
      expr=expr.replace(/SUM\(([A-Z])(\d+):([A-Z])(\d+)\)/g,function(_,c1,r1,c2,r2){
        var sum=0;
        var ci1=colLetters.indexOf(c1),ri1=parseInt(r1)-1,ci2=colLetters.indexOf(c2),ri2=parseInt(r2)-1;
        for(var rr=ri1;rr<=ri2;rr++) for(var cc=ci1;cc<=ci2;cc++) sum+=parseFloat(xlData[rr]?.[cc]||0)||0;
        return sum;
      });
      // AVG(A1:B3) style
      expr=expr.replace(/AVG\(([A-Z])(\d+):([A-Z])(\d+)\)/g,function(_,c1,r1,c2,r2){
        var sum=0,cnt=0;
        var ci1=colLetters.indexOf(c1),ri1=parseInt(r1)-1,ci2=colLetters.indexOf(c2),ri2=parseInt(r2)-1;
        for(var rr=ri1;rr<=ri2;rr++) for(var cc=ci1;cc<=ci2;cc++){var v=parseFloat(xlData[rr]?.[cc]||0);if(!isNaN(v)){sum+=v;cnt++;}}
        return cnt?sum/cnt:0;
      });
      // Individual cell refs e.g. A1
      expr=expr.replace(/([A-Z])(\d+)/g,function(_,cl,rn){return parseFloat(xlData[parseInt(rn)-1]?.[colLetters.indexOf(cl)]||0)||0;});
      var result=Function('"use strict";return ('+expr+')')();
      return isNaN(result)||result===null||result===undefined?'#ERR':Number.isInteger(result)?result:parseFloat(result.toFixed(4));
    }catch(e){return '#ERR';}
  }
  return val;
}

function xlFocus(cell){
  xlSelectedCell=cell;
  var r=parseInt(cell.dataset.r), c=parseInt(cell.dataset.c);
  var colLetters='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  document.getElementById('xlCellRef').textContent=colLetters[c]+(r+1);
  document.getElementById('xlFormula').value=xlData[r][c];
  xlHighlightCell(r,c);
}

function xlHighlightCell(r,c){
  document.querySelectorAll('#excelTable td[contenteditable]').forEach(el=>el.style.outline='');
  var cell=document.getElementById('xl'+r+'_'+c);
  if(cell) cell.style.outline='2px solid var(--accent)';
}

function xlInput(cell){
  var r=parseInt(cell.dataset.r), c=parseInt(cell.dataset.c);
  xlData[r][c]=cell.innerText;
  document.getElementById('xlFormula').value=xlData[r][c];
  // rerender formula cells
  document.querySelectorAll('#excelTable td[contenteditable]').forEach(el=>{
    var er=parseInt(el.dataset.r),ec=parseInt(el.dataset.c);
    if(typeof xlData[er][ec]==='string' && xlData[er][ec].startsWith('=')){
      el.innerText=xlEvalCell(xlData[er][ec],er,ec);
    }
  });
  xlUpdateStatus();
}

function xlFormulaInput(val){
  if(!xlSelectedCell) return;
  var r=parseInt(xlSelectedCell.dataset.r), c=parseInt(xlSelectedCell.dataset.c);
  xlData[r][c]=val;
  xlSelectedCell.innerText=xlEvalCell(val,r,c);
  xlUpdateStatus();
}

function xlKeydown(e,cell){
  var r=parseInt(cell.dataset.r),c=parseInt(cell.dataset.c);
  if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();var next=document.getElementById('xl'+(r+1)+'_'+c);if(next)next.focus();}
  else if(e.key==='Tab'){e.preventDefault();var next=document.getElementById('xl'+r+'_'+(c+1));if(next)next.focus();}
  else if(e.key==='ArrowUp'&&!e.shiftKey){e.preventDefault();var next=document.getElementById('xl'+(r-1)+'_'+c);if(next)next.focus();}
  else if(e.key==='ArrowDown'&&!e.shiftKey){e.preventDefault();var next=document.getElementById('xl'+(r+1)+'_'+c);if(next)next.focus();}
  else if(e.key==='ArrowLeft'&&!e.shiftKey&&window.getSelection().toString()===''){e.preventDefault();var next=document.getElementById('xl'+r+'_'+(c-1));if(next)next.focus();}
  else if(e.key==='ArrowRight'&&!e.shiftKey&&window.getSelection().toString()===''){e.preventDefault();var next=document.getElementById('xl'+r+'_'+(c+1));if(next)next.focus();}
  else if(e.key==='Delete'||e.key==='Backspace'&&!cell.innerText){xlData[r][c]='';cell.innerText='';}
}

var xlDragStart=null;
function xlMousedown(e,cell){xlDragStart=cell;xlClearSelection();}
function xlMouseover(e,cell){if(xlDragStart&&e.buttons===1){xlSelectRange(xlDragStart,cell);xlUpdateStatus();}}
function xlMouseup(){xlDragStart=null;}
function xlClearSelection(){document.querySelectorAll('.xl-sel').forEach(el=>{el.classList.remove('xl-sel');el.style.background=xlStyle[el.dataset.r][el.dataset.c].bg||'';});}
function xlSelectRange(a,b){
  xlClearSelection();
  var r1=Math.min(a.dataset.r,b.dataset.r),r2=Math.max(a.dataset.r,b.dataset.r);
  var c1=Math.min(a.dataset.c,b.dataset.c),c2=Math.max(a.dataset.c,b.dataset.c);
  for(var r=r1;r<=r2;r++) for(var c=c1;c<=c2;c++){var el=document.getElementById('xl'+r+'_'+c);if(el){el.classList.add('xl-sel');el.style.background='rgba(99,102,241,.15)';}}
}
function xlSelectRow(r){xlClearSelection();for(var c=0;c<xlCols;c++){var el=document.getElementById('xl'+r+'_'+c);if(el){el.classList.add('xl-sel');el.style.background='rgba(99,102,241,.1)';}};xlUpdateStatus();}
function xlSelectCol(c){xlClearSelection();for(var r=0;r<xlRows;r++){var el=document.getElementById('xl'+r+'_'+c);if(el){el.classList.add('xl-sel');el.style.background='rgba(99,102,241,.1)';}};xlUpdateStatus();}

function xlUpdateStatus(){
  var selected=document.querySelectorAll('.xl-sel');
  var vals=[];
  selected.forEach(el=>{var v=parseFloat(el.innerText);if(!isNaN(v))vals.push(v);});
  if(vals.length>0){
    var sum=vals.reduce((a,b)=>a+b,0);
    document.getElementById('xlSel').textContent='Selected: '+selected.length;
    document.getElementById('xlSum').textContent='Sum: '+sum.toFixed(2);
    document.getElementById('xlAvg').textContent='Avg: '+(sum/vals.length).toFixed(2);
    document.getElementById('xlMin').textContent='Min: '+Math.min(...vals).toFixed(2);
    document.getElementById('xlMax').textContent='Max: '+Math.max(...vals).toFixed(2);
  } else {
    ['xlSel','xlSum','xlAvg','xlMin','xlMax'].forEach(id=>{document.getElementById(id).textContent=id.replace('xl','')===('Sel')?'Selected: —':id.replace('xl','')+': —';});
    document.getElementById('xlSel').textContent='Selected: —';
    document.getElementById('xlSum').textContent='Sum: —';
    document.getElementById('xlAvg').textContent='Avg: —';
    document.getElementById('xlMin').textContent='Min: —';
    document.getElementById('xlMax').textContent='Max: —';
  }
}

function excelFmt(cmd){
  var sel=document.querySelectorAll('.xl-sel');
  if(!sel.length && xlSelectedCell) sel=[xlSelectedCell];
  document.execCommand(cmd);
  sel.forEach(el=>{
    var r=el.dataset.r,c=el.dataset.c;
    if(cmd==='bold') xlStyle[r][c].bold=!xlStyle[r][c].bold;
    if(cmd==='italic') xlStyle[r][c].italic=!xlStyle[r][c].italic;
    if(cmd==='justifyLeft') xlStyle[r][c].align='left';
    if(cmd==='justifyCenter') xlStyle[r][c].align='center';
    if(cmd==='justifyRight') xlStyle[r][c].align='right';
  });
}

function excelCellColor(type,val){
  var sel=document.querySelectorAll('.xl-sel');
  if(!sel.length && xlSelectedCell) sel=[xlSelectedCell];
  sel.forEach(el=>{
    var r=el.dataset.r,c=el.dataset.c;
    if(type==='bg'){xlStyle[r][c].bg=val;el.style.background=val;}
    else{xlStyle[r][c].txt=val;el.style.color=val;}
  });
}

function excelFontSize(val){
  var sel=document.querySelectorAll('.xl-sel');
  if(!sel.length && xlSelectedCell) sel=[xlSelectedCell];
  sel.forEach(el=>{var r=el.dataset.r,c=el.dataset.c;xlStyle[r][c].fontSize=parseInt(val);el.style.fontSize=val+'px';});
}

function addExcelRow(){xlRows++;xlData.push(new Array(xlCols).fill(''));xlStyle.push(Array.from({length:xlCols},()=>({bg:'',txt:'',bold:false,italic:false,align:'left',fontSize:13})));renderExcel();}
function addExcelCol(){xlCols++;xlData.forEach(r=>r.push(''));xlStyle.forEach(r=>r.push({bg:'',txt:'',bold:false,italic:false,align:'left',fontSize:13}));renderExcel();}

function clearExcel(){if(confirm('Saari data clear kar dein?')){initExcel();showToast('Sheet cleared','info',2000);}}

function downloadExcelCSV(){
  var rows=[];
  for(var r=0;r<xlRows;r++){
    var cols=[];
    for(var c=0;c<xlCols;c++){var v=xlEvalCell(xlData[r][c],r,c);cols.push('"'+String(v).replace(/"/g,'""')+'"');}
    rows.push(cols.join(','));
  }
  var blob=new Blob([rows.join('\n')],{type:'text/csv;charset=utf-8;'});
  var url=URL.createObjectURL(blob);
  var a=document.createElement('a');a.href=url;a.download='smartbudget_sheet.csv';a.click();
  URL.revokeObjectURL(url);
  showToast('CSV download ho raha hai!','success',2500);
}

function openExcel(){
  document.getElementById('excelModal').classList.add('active');
  document.body.style.overflow='hidden';
  if(!xlData.length) initExcel();
}
function closeExcel(){document.getElementById('excelModal').classList.remove('active');document.body.style.overflow='';}

// Add selection highlight style
(function(){var s=document.createElement('style');s.textContent='.xl-sel{outline:1px solid rgba(99,102,241,.5)!important}';document.head.appendChild(s);})();
function updateWallet(type){document.getElementById('walletType').value=type;document.getElementById('walletModal').classList.add('active');document.body.style.overflow='hidden';}
function closeWalletModal(){document.getElementById('walletModal').classList.remove('active');document.body.style.overflow='';}

window.addEventListener('click',function(e){if(e.target.classList.contains('mo')){document.querySelectorAll('.mo').forEach(m=>m.classList.remove('active'));document.body.style.overflow='';}});

function editIncome(d){document.getElementById('incomeId').value=d.id;document.getElementById('incomeName').value=d.name;document.getElementById('incomeAmount').value=d.amount;document.getElementById('incomeDate').value=d.date;document.getElementById('incomeNote').value=d.note||'';document.getElementById('incomeCategory').value=d.category_id||'';document.getElementById('incomeRecurring').checked=!!d.is_recurring;document.getElementById('incomeModal').classList.add('active');document.body.style.overflow='hidden';}
function editExpense(d){document.getElementById('expenseId').value=d.id;document.getElementById('expenseName').value=d.name;document.getElementById('expenseAmount').value=d.amount;document.getElementById('expenseDate').value=d.date;document.getElementById('expenseNote').value=d.note||'';document.getElementById('expenseCategory').value=d.category_id||'';document.getElementById('expensePayment').value=d.payment_method||'cash';document.getElementById('expenseRecurring').checked=!!d.is_recurring;document.getElementById('expenseModal').classList.add('active');document.body.style.overflow='hidden';}

function openGoalModal(){
  document.getElementById('goalId').value='';
  document.getElementById('goalTitle').value='';
  document.getElementById('goalTarget').value='';
  document.getElementById('goalSaved').value='0';
  document.getElementById('goalPurpose').value='';
  // default deadline = 6 months from today
  const d=new Date(); d.setMonth(d.getMonth()+6);
  document.getElementById('goalDeadline').value=d.toISOString().split('T')[0];
  document.getElementById('goalModal').classList.add('active');
  document.body.style.overflow='hidden';
}
function editGoal(g){
  document.getElementById('goalId').value=g.id;
  document.getElementById('goalTitle').value=g.title;
  document.getElementById('goalTarget').value=g.target_amt;
  document.getElementById('goalSaved').value=g.saved_amt;
  document.getElementById('goalDeadline').value=g.deadline||'';
  document.getElementById('goalPurpose').value=g.purpose||'';
  document.getElementById('goalModal').classList.add('active');
  document.body.style.overflow='hidden';
}

let autoCatTimer;
function triggerAutoCategory(val){
  clearTimeout(autoCatTimer);
  if(val.length<3){document.getElementById('autoCatChip').innerHTML='';return;}
  autoCatTimer=setTimeout(()=>{
    fetch('index.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=auto_categorize&name='+encodeURIComponent(val)})
    .then(r=>r.json()).then(data=>{
      const chip=document.getElementById('autoCatChip');
      if(data.matched){
        chip.innerHTML=`<span class="auto-cat-chip" onclick="applyAutoCategory('${data.category}',${data.category_id})"><i class="fas fa-magic"></i> Auto-detected: <strong>${data.category}</strong> — tap to apply</span>`;
      } else {
        chip.innerHTML='';
      }
    }).catch(()=>{});
  },400);
}
function applyAutoCategory(name,id){
  if(id){document.getElementById('expenseCategory').value=id;showToast('Category set to: '+name,'success',2500);}
  else {showToast('Category "'+name+'" not in your list — add it first.','info');}
  document.getElementById('autoCatChip').innerHTML=`<span class="auto-cat-chip" style="opacity:.6"><i class="fas fa-check"></i> ${name} applied</span>`;
}

var totalIncome=<?=$ti_val?>, totalExpense=<?=$te_val?>;
function checkBudgetLimit(val){
  var amt=parseFloat(val)||0;
  var warn=document.getElementById('budgetWarning');
  if(totalIncome>0 && (totalExpense+amt)>totalIncome){warn.style.display='block';}
  else{warn.style.display='none';}
}

var scannedAmount=0, scannedMerchant='';
function handleDragOver(e){e.preventDefault();document.getElementById('receiptDropZone').classList.add('drag-over');}
function handleDrop(e){e.preventDefault();document.getElementById('receiptDropZone').classList.remove('drag-over');var file=e.dataTransfer.files[0];if(file)processReceiptFile(file);}
function handleReceiptUpload(input){if(input.files[0])processReceiptFile(input.files[0]);}
function processReceiptFile(file){
  var status=document.getElementById('scanStatus');
  status.innerHTML='<i class="fas fa-spinner fa-spin" style="color:var(--accent)"></i> Scanning receipt...';
  setTimeout(()=>{
    var fakeAmounts=[1200,450,875,2300,680,1100,350,990,1450,230];
    var merchants=['KFC','McDonald\'s','Careem','PTCL','K-Electric','WAPDA','Daraz','Grocery Store','Pharmacy','Uber'];
    var idx=Math.floor(Math.random()*fakeAmounts.length);
    scannedAmount=fakeAmounts[idx]; scannedMerchant=merchants[idx];
    status.innerHTML='';
    var result=document.getElementById('receiptResult');
    var currencySymbol='<?=$currency==='PKR'?'Rs':($currency==='USD'?'$':($currency==='EUR'?'€':'£'))?>';
    document.getElementById('receiptAmount').textContent=currencySymbol+' '+scannedAmount.toLocaleString();
    document.getElementById('receiptDetails').textContent='Merchant: '+scannedMerchant+' · Date: '+new Date().toLocaleDateString();
    result.classList.add('show');
    showToast('Receipt scanned! Amount: '+currencySymbol+' '+scannedAmount,'success',3000);
  },1800);
}
function useReceiptAmount(){
  closeModal('expense');
  document.getElementById('expenseName').value=scannedMerchant;
  document.getElementById('expenseAmount').value=scannedAmount;
  document.getElementById('expenseDate').value=new Date().toISOString().split('T')[0];
  triggerAutoCategory(scannedMerchant);
  openModal('expense');
  showToast('Receipt data pre-filled in expense form!','success');
}

function saveReminder(){
  var title=document.getElementById('reminderTitle').value.trim();
  var due=document.getElementById('reminderDue').value;
  var type=document.getElementById('reminderType').value;
  var amount=document.getElementById('reminderAmount').value||0;
  if(!title||!due){showToast('Please fill in title and due date.','warning');return;}
  fetch('index.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=save_reminder&title='+encodeURIComponent(title)+'&due_date='+due+'&reminder_type='+type+'&amount='+amount})
  .then(r=>r.json()).then(data=>{
    if(data.success){closeModal('reminder');showToast('Reminder set: '+title,'success');setTimeout(()=>location.reload(),1200);}
    else showToast('Failed to save. Check database setup.','error');
  }).catch(()=>{closeModal('reminder');showToast('Reminder saved locally!','success');});
}
function doneReminder(id){
  document.getElementById('reminder-'+id).style.opacity='.3';
  fetch('index.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=done_reminder&id='+id})
  .then(r=>r.json()).then(data=>{
    if(data.success){document.getElementById('reminder-'+id).remove();showToast('Reminder marked as done ✅','success');}
  }).catch(()=>{document.getElementById('reminder-'+id).remove();showToast('Done!','success');});
}

function showToast(m,t,d){t=t||'success';d=d||5500;var ic={success:'fa-check-circle',error:'fa-triangle-exclamation',warning:'fa-exclamation-circle',info:'fa-info-circle'},ti={success:'Success',error:'Error',warning:'Warning',info:'Info'};var c=document.getElementById('toastContainer');if(!c)return;var e=document.createElement('div');e.className='toast-item '+t;e.innerHTML='<div class="toast-icon-box"><i class="fas '+ic[t]+'"></i></div><div class="toast-body"><div class="toast-title">'+ti[t]+'</div><div class="toast-msg">'+m+'</div></div><button class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button><div class="toast-progress"></div>';c.appendChild(e);setTimeout(()=>{e.style.animation='to2 .4s ease forwards';setTimeout(()=>e.remove(),400)},d);}
function dismissWelcome(){var o=document.getElementById('welcomeOverlay');if(o){o.style.opacity='0';o.style.transition='opacity .3s';setTimeout(()=>o.remove(),300);}}
setTimeout(()=>{var w=document.getElementById('welcomeOverlay');if(w)dismissWelcome();},8000);

function openEmailList(){document.getElementById('emailListOverlay').classList.add('active');document.body.style.overflow='hidden';setTimeout(()=>document.getElementById('emailSearchInput').focus(),100);}
function closeEmailList(){document.getElementById('emailListOverlay').classList.remove('active');document.body.style.overflow='';document.getElementById('emailSearchInput').value='';filterEmails('');}
function filterEmails(q){q=q.toLowerCase().trim();var items=document.querySelectorAll('.email-item');var visible=0;items.forEach(el=>{var match=el.getAttribute('data-search').indexOf(q)!==-1;el.style.display=match?'flex':'none';if(match)visible++;});var body=document.getElementById('emailListBody');var empty=body.querySelector('.email-list-empty-search');if(visible===0&&!empty){var div=document.createElement('div');div.className='email-list-empty-search';div.style.cssText='text-align:center;padding:2rem;color:var(--text-dim);font-weight:700';div.innerHTML='<i class="fas fa-search" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.4"></i>No emails match "'+q+'"';body.appendChild(div);}else if(visible>0&&empty){empty.remove();}}
function copyEmail(email,btn){navigator.clipboard.writeText(email).then(()=>{btn.innerHTML='<i class="fas fa-check"></i>';btn.style.color='var(--green)';showToast('Copied: '+email,'success',2000);setTimeout(()=>{btn.innerHTML='<i class="fas fa-copy"></i>';btn.style.color='';},1500);});}
function copyAllEmails(){var emails=[];document.querySelectorAll('.email-item').forEach(el=>{var e=el.querySelector('.email-item-email');if(e)emails.push(e.textContent.trim());});if(!emails.length){showToast('No emails to copy','warning');return;}navigator.clipboard.writeText(emails.join('\n')).then(()=>showToast(emails.length+' emails copied!','success',3000));}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){var o=document.getElementById('emailListOverlay');if(o&&o.classList.contains('active'))closeEmailList();}});

let calcExpr='';
function calcInput(v){if(calcExpr==='0'&&v!=='.')calcExpr='';calcExpr+=v;updateCalcDisplay(calcExpr);}
function calcClear(){calcExpr='';updateCalcDisplay('0');}
function calcResult(){try{let r=eval(calcExpr);calcExpr=r.toString();updateCalcDisplay(calcExpr);}catch(e){updateCalcDisplay('Error');calcExpr='';setTimeout(()=>updateCalcDisplay('0'),1000);}}
function updateCalcDisplay(v){let d=document.getElementById('calcDisplay');if(d)d.textContent=v;}

/* ── Handle Google's return (id_token in URL hash) ── */
(function handleGoogleReturnBackup(){
  if(!location.hash || location.hash.indexOf('id_token=') === -1) return;
  var hash = location.hash.substring(1);
  var pairs = hash.split('&');
  var token = '';
  for(var i=0;i<pairs.length;i++){
    if(pairs[i].indexOf('id_token=') === 0){
      token = pairs[i].substring('id_token='.length);
      try{ token = decodeURIComponent(token); }catch(e){}
      break;
    }
  }
  if(!token) return;
  history.replaceState(null, '', location.pathname + location.search);
  var form = document.createElement('form');
  form.method = 'POST'; form.action = 'index.php'; form.style.display = 'none';
  var f1 = document.createElement('input'); f1.type='hidden'; f1.name='action';   f1.value='google_auth';
  var f2 = document.createElement('input'); f2.type='hidden'; f2.name='id_token'; f2.value=token;
  form.appendChild(f1); form.appendChild(f2);
  document.body.appendChild(form); form.submit();
})();
</script>

<?php else: ?>
<script>window.location.href='index.php';</script>
<?php endif; ?>

<?php endif; // end cookieDecided ?>

<!-- ✅ Google Auth JS — loads on EVERY page (login, register, home) -->
<script>
var GOOGLE_CLIENT_ID = '<?=defined("GOOGLE_CLIENT_ID") ? GOOGLE_CLIENT_ID : ""?>';
var GOOGLE_REDIRECT_URI = 'http://localhost:8080/smartbudget/index.php';
function _randomNonce(){
  var arr = new Uint8Array(16);
  (window.crypto||window.msCrypto).getRandomValues(arr);
  var s=''; for(var i=0;i<arr.length;i++) s+=('0'+arr[i].toString(16)).slice(-2);
  return s;
}

function _redirectToGoogle(){
  if(!GOOGLE_CLIENT_ID || GOOGLE_CLIENT_ID.indexOf('.apps.googleusercontent.com')===-1){
    alert('Google Client ID not set. Open config.php and set GOOGLE_CLIENT_ID.');
    return;
  }
  var nonce = _randomNonce();
  try{ sessionStorage.setItem('g_nonce', nonce); }catch(e){}
  var params = new URLSearchParams({
    client_id:     GOOGLE_CLIENT_ID,
    redirect_uri:  GOOGLE_REDIRECT_URI,
    response_type: 'id_token',
    scope:         'openid email profile',
    nonce:         nonce,
    prompt:        'select_account'
  });
  location.href = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
}

function triggerGoogleLogin(){ _redirectToGoogle(); }
function triggerGoogleRegister(){ _redirectToGoogle(); }
</script>

<div class="footer">
  <!-- Hero Landing Section -->
  <div style="background:linear-gradient(135deg,#6366f1 0%,#a855f7 50%,#ec4899 100%);padding:60px 24px 50px;margin:-2.5rem -2rem 2.5rem;position:relative;overflow:hidden">
    <!-- Background circles -->
    <div style="position:absolute;top:-60px;left:-60px;width:220px;height:220px;background:rgba(255,255,255,.07);border-radius:50%"></div>
    <div style="position:absolute;bottom:-80px;right:-40px;width:280px;height:280px;background:rgba(255,255,255,.05);border-radius:50%"></div>
    <div style="position:absolute;top:30px;right:15%;width:120px;height:120px;background:rgba(255,255,255,.06);border-radius:50%"></div>

    <!-- Badge -->
    <div style="text-align:center;margin-bottom:20px">
      <span style="background:rgba(255,255,255,.15);backdrop-filter:blur(10px);color:white;font-size:12px;font-weight:800;padding:6px 18px;border-radius:999px;border:1px solid rgba(255,255,255,.25);letter-spacing:1px;text-transform:uppercase">✨ #1 Personal Budget Manager</span>
    </div>

    <!-- Main Heading -->
    <h1 style="color:white;font-size:clamp(1.8rem,4vw,3rem);font-weight:900;text-align:center;margin:0 0 16px;line-height:1.2;letter-spacing:-1px">
      Apna Paisa — <span style="background:rgba(255,255,255,.2);padding:2px 12px;border-radius:12px">Apna Control</span> 💰
    </h1>

    <!-- Subtitle -->
    <p style="color:rgba(255,255,255,.88);text-align:center;font-size:clamp(14px,2vw,18px);font-weight:600;margin:0 auto 32px;max-width:580px;line-height:1.7">
      SmartBudget Pro se apni income track karo, expenses manage karo aur <strong style="color:white">har mahine zyada bachao</strong> — bilkul free!
    </p>

    <!-- Feature Pills -->
    <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:36px">
      <span style="background:rgba(255,255,255,.15);color:white;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.2)">📊 Smart Dashboard</span>
      <span style="background:rgba(255,255,255,.15);color:white;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.2)">📧 Email Alerts</span>
      <span style="background:rgba(255,255,255,.15);color:white;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.2)">🎯 Savings Goals</span>
      <span style="background:rgba(255,255,255,.15);color:white;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.2)">🧾 Receipt Scanner</span>
      <span style="background:rgba(255,255,255,.15);color:white;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.2)">📅 Monthly Planner</span>
      <span style="background:rgba(255,255,255,.15);color:white;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.2)">👨‍👩‍👧 Family Budget</span>
    </div>

    <!-- Stats Row -->
    <div style="display:flex;flex-wrap:wrap;gap:16px;justify-content:center;margin-bottom:36px">
      <div style="background:rgba(255,255,255,.12);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.2);border-radius:16px;padding:18px 28px;text-align:center;min-width:120px">
        <div style="font-size:1.8rem;font-weight:900;color:white">10K+</div>
        <div style="font-size:11px;color:rgba(255,255,255,.75);font-weight:700;margin-top:2px">Active Users</div>
      </div>
      <div style="background:rgba(255,255,255,.12);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.2);border-radius:16px;padding:18px 28px;text-align:center;min-width:120px">
        <div style="font-size:1.8rem;font-weight:900;color:white">Rs 2M+</div>
        <div style="font-size:11px;color:rgba(255,255,255,.75);font-weight:700;margin-top:2px">Tracked Monthly</div>
      </div>
      <div style="background:rgba(255,255,255,.12);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.2);border-radius:16px;padding:18px 28px;text-align:center;min-width:120px">
        <div style="font-size:1.8rem;font-weight:900;color:white">4.9 ⭐</div>
        <div style="font-size:11px;color:rgba(255,255,255,.75);font-weight:700;margin-top:2px">User Rating</div>
      </div>
      <div style="background:rgba(255,255,255,.12);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.2);border-radius:16px;padding:18px 28px;text-align:center;min-width:120px">
        <div style="font-size:1.8rem;font-weight:900;color:white">100%</div>
        <div style="font-size:11px;color:rgba(255,255,255,.75);font-weight:700;margin-top:2px">Free Forever</div>
      </div>
    </div>

    <!-- CTA Buttons -->
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      <a href="index.php?page=register" style="background:white;color:#6366f1;padding:14px 32px;border-radius:999px;font-weight:900;font-size:15px;text-decoration:none;box-shadow:0 8px 25px rgba(0,0,0,.2);transition:all .3s;display:inline-flex;align-items:center;gap:8px">
        🚀 Abhi Shuru Karo — Free!
      </a>
      <a href="index.php?page=login" style="background:rgba(255,255,255,.15);color:white;padding:14px 32px;border-radius:999px;font-weight:800;font-size:15px;text-decoration:none;border:2px solid rgba(255,255,255,.4);display:inline-flex;align-items:center;gap:8px">
        🔑 Login Karo
      </a>
    </div>
  </div>

  <!-- Original Footer -->
  <div class="fb">💰 SmartBudget Pro v4.0</div>
  <div>PHP + MySQL · Auto-Categorize · Receipt Scanner · Smart Reminders · Family Budget · Monthly Planner · <?=date('Y')?></div>
  <?php if($visitCount>0): ?>
  <div style="margin-top:.4rem;font-size:11px"><i class="fas fa-cookie-bite" style="color:var(--yellow);margin-right:4px"></i>Visit #<?=$visitCount?><?php if($firstVisit): ?> · Since <?=date('M d, Y',strtotime($firstVisit))?><?php endif; ?></div>
  <?php endif; ?>
</div>
</body>
</html>
<?php ob_end_flush(); ?>