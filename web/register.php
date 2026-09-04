<?php
require_once 'config.php';

$message = '';
$messageType = '';
$csrf_token = generate_csrf_token();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    $email = $_POST['email'];
    // Generate username from email
    $username = explode('@', $email)[0] . rand(100, 999);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = $_POST['full_name'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];

    // Check if email exists
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $message = "Email already registered.";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, phone, address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $username, $email, $password, $full_name, $phone, $address);
        
        if ($stmt->execute()) {
            $message = "Registration successful! You can now login.";
            $messageType = "success";
        } else {
            $message = "Error: " . $conn->error;
            $messageType = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRASH DETEKTOR - Register</title>
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
        <div class="auth-brand-icon" style="margin-bottom: 1rem;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <polyline points="16 11 18 13 22 9"/>
            </svg>
        </div>
        <h2 class="text-center" style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">Daftar Akun</h2>
        <p class="text-center" style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 2rem;">Lengkapi data diri Anda untuk bergabung.</p>
            
        <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group" style="margin-bottom: 1rem;">
                <input type="text" name="full_name" class="form-control-modern" placeholder="Nama Lengkap" required>
            </div>
            <div class="form-group" style="margin-bottom: 1rem;">
                <input type="email" name="email" class="form-control-modern" placeholder="Alamat Email" required>
            </div>
            <div class="form-group" style="margin-bottom: 1rem;">
                <input type="text" name="phone" class="form-control-modern" placeholder="Nomor Handphone" required>
            </div>
            <div class="form-group" style="margin-bottom: 1rem;">
                <textarea name="address" class="form-control-modern" placeholder="Alamat Lengkap" rows="2" style="resize: vertical; min-height: 80px;" required></textarea>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <input type="password" name="password" class="form-control-modern" placeholder="Buat Password" required>
            </div>
            
            <button type="submit" class="btn-auth">Daftar Sekarang</button>
        </form>
        
        <div class="text-center" style="margin-top: 1.5rem;">
            <a href="index.php" style="color: var(--primary); font-size: 0.9rem; text-decoration: none; font-weight: 500;">Sudah punya akun? Masuk di sini</a>
        </div>
        
        <div class="text-center" style="font-size: 0.75rem; color: #cbd5e1; margin-top: 2rem; font-weight: 500; letter-spacing: 0.5px;">
            VERSI 1.0.0
        </div>
    </div>
</body>
</html>
