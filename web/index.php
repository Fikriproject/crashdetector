<?php
require_once 'config.php';

$error = '';

// Jika sudah login, langsung lempar ke dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$csrf_token = generate_csrf_token();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, password, full_name FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['password'])) {
            session_regenerate_id(true); // Prevent session fixation
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            $login_success = true;
        } else {
            $error = "Email atau Password salah.";
        }
    } else {
        $error = "Email atau Password salah.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRASH DETEKTOR - Login</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <!-- PWA Setup -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#3b82f6">
    <link rel="apple-touch-icon" href="assets/img/icon-192.png">
    <script>
      if ("serviceWorker" in navigator) {
        window.addEventListener("load", () => {
          navigator.serviceWorker.register("sw.js").then(registration => {
            console.log("SW registered:", registration);
          }).catch(error => {
            console.log("SW registration failed:", error);
          });
        });
      }
    </script>
</head>
<body class="auth-page">
    <div class="auth-card-modern">
        <div class="auth-brand-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <path d="M8.5 11.5L11 14l4-4"/>
            </svg>
        </div>
        <h2 class="text-center" style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">CRASH DETEKTOR</h2>
        <p class="text-center" style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 2rem;">Selamat datang, silakan masuk ke akun Anda.</p>
            
            <?php if(!empty($error)): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group" style="margin-bottom: 1rem;">
                    <input type="email" name="email" class="form-control-modern" placeholder="Alamat Email" required>
                </div>
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <input type="password" name="password" class="form-control-modern" placeholder="Password" required>
                </div>
                
                <button type="submit" class="btn-auth">Masuk Sekarang</button>
            </form>
            
            <div class="text-center" style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.75rem;">
                <a href="register.php" style="color: var(--primary); font-size: 0.9rem; text-decoration: none; font-weight: 500;">Belum punya akun? Buat Akun Baru</a>
                <a href="#" style="color: var(--primary); font-size: 0.85rem; text-decoration: none;">Lupa password Anda?</a>
            </div>
            
            <div class="text-center" style="font-size: 0.75rem; color: #cbd5e1; margin-top: 2rem; font-weight: 500; letter-spacing: 0.5px;">
                VERSI 1.0.0
            </div>
    </div>  

    <?php if(isset($login_success) && $login_success): ?>
    <div class="emergency-modal-overlay" style="display: flex; opacity: 1;">
        <div class="emergency-modal" style="transform: scale(1);">
            <div class="emergency-icon-pulse" style="background: rgba(16, 185, 129, 0.1); color: var(--success); animation: none;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h2 style="color: var(--success); font-size: 1.5rem; margin-bottom: 0.5rem; font-weight: 700;">Berhasil Login!</h2>
            <p style="color: var(--text-main); font-size: 0.95rem;">Mengarahkan ke Dashboard...</p>
        </div>
    </div>
    <script>
        setTimeout(function() {
            window.location.href = 'dashboard.php';
        }, 1500);
    </script>
    <?php endif; ?>
</body>
</html>
