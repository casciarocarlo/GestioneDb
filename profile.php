<?php
require_once 'config.php';

if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php'); exit;
}

$current_page = 'profile';
$page_title   = __('profile', 'My Profile');
$page_heading = __('profile', 'My Profile');
$page_description = __('profile_desc', 'Manage your account settings');

$user = getCurrentUser();
$uid  = (int)$user['id'];

/* ─── Auth DB helper ─── */
function getAuthPdoProfile(): PDO {
    return new PDO(
        "mysql:host=" . AUTH_DB_HOST . ";dbname=" . AUTH_DB_NAME . ";charset=utf8mb4",
        AUTH_DB_USER, AUTH_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/* ─── Load current user from DB ─── */
$pdo = getAuthPdoProfile();
$stmt = $pdo->prepare("SELECT id, username, email, role, created_at, last_login FROM users WHERE id = ?");
$stmt->execute([$uid]);
$db_user = $stmt->fetch();

$errors   = [];
$success  = [];

/* ─── POST handlers ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Update profile (username + email) ── */
    if ($action === 'update_profile') {
        $new_username = trim($_POST['username'] ?? '');
        $new_email    = trim($_POST['email'] ?? '');

        if (!$new_username || !$new_email) {
            $errors[] = __('fields_required', 'All fields are required.');
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('invalid_email', 'Please enter a valid email address.');
        } else {
            // Check uniqueness (exclude self)
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username=? OR email=?) AND id != ?");
            $chk->execute([$new_username, $new_email, $uid]);
            if ($chk->fetchColumn() > 0) {
                $errors[] = __('username_email_taken', 'Username or email already in use.');
            } else {
                $pdo->prepare("UPDATE users SET username=?, email=? WHERE id=?")
                    ->execute([$new_username, $new_email, $uid]);
                $_SESSION['username'] = $new_username;
                $_SESSION['email']    = $new_email;
                $db_user['username']  = $new_username;
                $db_user['email']     = $new_email;
                $success[] = __('profile_updated', 'Profile updated successfully!');
            }
        }
    }

    /* ── Change password ── */
    if ($action === 'change_password') {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_new_password'] ?? '';

        if (!$current_pass || !$new_pass || !$confirm_pass) {
            $errors[] = __('fields_required', 'All fields are required.');
        } elseif ($new_pass !== $confirm_pass) {
            $errors[] = __('passwords_no_match', 'New passwords do not match.');
        } elseif (strlen($new_pass) < 8) {
            $errors[] = __('password_too_short', 'Password must be at least 8 characters.');
        } else {
            // Verify current password
            $stmt2 = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
            $stmt2->execute([$uid]);
            $row = $stmt2->fetch();

            if (!$row || !password_verify($current_pass, $row['password_hash'])) {
                $errors[] = __('wrong_current_password', 'Current password is incorrect.');
            } else {
                $new_hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$new_hash, $uid]);

                // Invalidate all other sessions for security
                $pdo->prepare("DELETE FROM user_sessions WHERE user_id=? AND session_token != ?")
                    ->execute([$uid, $_SESSION['session_token']]);

                $success[] = __('password_changed', 'Password changed successfully! All other sessions have been terminated.');
            }
        }
    }
}

include 'includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-info">
        <h1 class="page-title">👤 <?= __('profile', 'My Profile') ?></h1>
        <p class="page-description"><?= __('profile_desc', 'Manage your account settings and security') ?></p>
    </div>
</div>

<!-- Alerts -->
<?php foreach ($errors as $e): ?>
<div class="alert alert-danger alert-dismissible" role="alert">
    <span>❌</span><span><?= htmlspecialchars($e) ?></span>
    <button class="btn-close" onclick="this.parentElement.remove()">✕</button>
</div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
<div class="alert alert-success alert-dismissible" role="alert">
    <span>✅</span><span><?= htmlspecialchars($s) ?></span>
    <button class="btn-close" onclick="this.parentElement.remove()">✕</button>
</div>
<?php endforeach; ?>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem;">

    <!-- ── Left column: Stats + Profile form ─────────────────────── -->
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">

        <!-- Account info card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">🪪 <?= __('account_info', 'Account Info') ?></div>
            </div>
            <div style="display: flex; align-items: center; gap: 1.5rem; padding: 0.5rem 0 1rem;">
                <!-- Avatar -->
                <div style="
                    width: 72px; height: 72px; border-radius: 50%;
                    background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
                    display: flex; align-items: center; justify-content: center;
                    font-size: 1.8rem; font-weight: 700; color: #fff; flex-shrink: 0;
                ">
                    <?= strtoupper(substr($db_user['username'], 0, 2)) ?>
                </div>
                <div>
                    <div style="font-size: 1.1rem; font-weight: 600;"><?= htmlspecialchars($db_user['username']) ?></div>
                    <div style="font-size: 0.85rem; opacity: .7;"><?= htmlspecialchars($db_user['email']) ?></div>
                    <div style="margin-top: 0.4rem;">
                        <span class="badge <?= $db_user['role'] === 'admin' ? 'badge-warning' : 'badge-primary' ?>">
                            <?= $db_user['role'] === 'admin' ? '👑 Admin' : '👤 User' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="separator"></div>

            <div class="grid grid-2" style="gap: 0.75rem; margin-top: 0.75rem;">
                <div class="stat-card info" style="padding: 0.75rem;">
                    <span class="stat-label"><?= __('member_since', 'Member Since') ?></span>
                    <span style="font-size: 0.85rem; font-weight: 600;">
                        <?= date('d M Y', strtotime($db_user['created_at'])) ?>
                    </span>
                </div>
                <div class="stat-card success" style="padding: 0.75rem;">
                    <span class="stat-label"><?= __('last_login', 'Last Login') ?></span>
                    <span style="font-size: 0.85rem; font-weight: 600;">
                        <?= $db_user['last_login'] ? date('d M Y H:i', strtotime($db_user['last_login'])) : 'N/A' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Update profile form -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">✏️ <?= __('update_profile', 'Update Profile') ?></div>
                    <div class="card-subtitle"><?= __('update_profile_hint', 'Change your display name and email') ?></div>
                </div>
            </div>

            <form method="POST" novalidate>
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label" for="prof-username"><?= __('username', 'Username') ?></label>
                    <input type="text" name="username" id="prof-username" class="form-input"
                           value="<?= htmlspecialchars($db_user['username']) ?>"
                           required autocomplete="username">
                </div>

                <div class="form-group">
                    <label class="form-label" for="prof-email"><?= __('email', 'Email') ?></label>
                    <input type="email" name="email" id="prof-email" class="form-input"
                           value="<?= htmlspecialchars($db_user['email']) ?>"
                           required autocomplete="email">
                </div>

                <button type="submit" class="btn btn-primary">
                    💾 <?= __('save_changes', 'Save Changes') ?>
                </button>
            </form>
        </div>

    </div>

    <!-- ── Right column: Change password + Sessions ───────────────── -->
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">

        <!-- Change password form -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">🔑 <?= __('change_password', 'Change Password') ?></div>
                    <div class="card-subtitle"><?= __('change_password_hint', 'Use a strong password of at least 8 characters') ?></div>
                </div>
            </div>

            <form method="POST" novalidate id="pass-form">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label" for="current-pass"><?= __('current_password', 'Current Password') ?></label>
                    <div style="position: relative;">
                        <input type="password" name="current_password" id="current-pass" class="form-input"
                               placeholder="••••••••" required autocomplete="current-password">
                        <button type="button" onclick="togglePass('current-pass', this)"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;opacity:.6;">👁</button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new-pass"><?= __('new_password', 'New Password') ?></label>
                    <div style="position: relative;">
                        <input type="password" name="new_password" id="new-pass" class="form-input"
                               placeholder="••••••••" required minlength="8" autocomplete="new-password"
                               oninput="checkStrength(this.value)">
                        <button type="button" onclick="togglePass('new-pass', this)"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;opacity:.6;">👁</button>
                    </div>
                    <!-- Strength meter -->
                    <div style="margin-top: 0.4rem;">
                        <div style="height: 4px; background: var(--border); border-radius: 2px; overflow: hidden;">
                            <div id="strength-bar" style="height:100%;width:0%;transition:all .3s;border-radius:2px;"></div>
                        </div>
                        <span id="strength-label" style="font-size:0.7rem; opacity:.7;"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm-pass"><?= __('confirm_new_password', 'Confirm New Password') ?></label>
                    <div style="position: relative;">
                        <input type="password" name="confirm_new_password" id="confirm-pass" class="form-input"
                               placeholder="••••••••" required autocomplete="new-password"
                               oninput="checkMatch()">
                        <button type="button" onclick="togglePass('confirm-pass', this)"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;opacity:.6;">👁</button>
                    </div>
                    <span id="match-label" style="font-size:0.7rem;"></span>
                </div>

                <button type="submit" class="btn btn-warning" id="btn-change-pass">
                    🔑 <?= __('change_password', 'Change Password') ?>
                </button>
            </form>
        </div>

        <!-- Security info -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">🛡️ <?= __('security_info', 'Security') ?></div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.85rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                    <span>🔐 <?= __('session_token', 'Active Session') ?></span>
                    <span class="badge badge-success">✓ Valid</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                    <span>🌐 <?= __('current_ip', 'Your IP') ?></span>
                    <code style="font-size:0.8rem; opacity:.8;"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'N/A') ?></code>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;">
                    <span>🔄 <?= __('csrf_protection', 'CSRF Protection') ?></span>
                    <span class="badge badge-success">✓ Active</span>
                </div>
            </div>

            <!-- Danger zone -->
            <div style="margin-top: 1.25rem; padding: 1rem; border: 1px solid rgba(239,68,68,.3); border-radius: var(--radius); background: rgba(239,68,68,.05);">
                <div style="font-weight: 600; color: var(--danger); margin-bottom: 0.5rem; font-size: 0.85rem;">
                    ⚠️ <?= __('danger_zone', 'Danger Zone') ?>
                </div>
                <p style="font-size: 0.8rem; opacity: .75; margin-bottom: 0.75rem;">
                    <?= __('logout_all_desc', 'Sign out from all devices and terminate all active sessions.') ?>
                </p>
                <a href="logout.php" class="btn btn-danger btn-sm">
                    ⏻ <?= __('logout_all', 'Sign Out Everywhere') ?>
                </a>
            </div>
        </div>

    </div>
</div>

<script>
function togglePass(id, btn) {
    const input = document.getElementById(id);
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    btn.style.opacity = isText ? '0.6' : '1';
}

function checkStrength(val) {
    const bar   = document.getElementById('strength-bar');
    const label = document.getElementById('strength-label');
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '20%', color: '#ef4444', text: '<?= __("strength_very_weak","Very Weak") ?>' },
        { pct: '40%', color: '#f97316', text: '<?= __("strength_weak","Weak") ?>' },
        { pct: '60%', color: '#eab308', text: '<?= __("strength_fair","Fair") ?>' },
        { pct: '80%', color: '#22c55e', text: '<?= __("strength_strong","Strong") ?>' },
        { pct: '100%',color: '#10b981', text: '<?= __("strength_very_strong","Very Strong") ?>' },
    ];
    const lvl = levels[Math.min(score, 4)];
    bar.style.width     = lvl.pct;
    bar.style.background= lvl.color;
    label.textContent   = val.length ? lvl.text : '';
    label.style.color   = lvl.color;
}

function checkMatch() {
    const np = document.getElementById('new-pass').value;
    const cp = document.getElementById('confirm-pass').value;
    const lbl = document.getElementById('match-label');
    if (!cp) { lbl.textContent = ''; return; }
    if (np === cp) {
        lbl.textContent = '✓ <?= __("passwords_match","Passwords match") ?>';
        lbl.style.color = 'var(--success)';
    } else {
        lbl.textContent = '✗ <?= __("passwords_no_match","Passwords do not match") ?>';
        lbl.style.color = 'var(--danger)';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
