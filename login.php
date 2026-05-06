<?php
require_once __DIR__ . '/includes/auth.php';

$baseUrl = authBaseUrl();

// Si ya hay sesión, ir directo al panel.
if (isLoggedIn()) {
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

// Si todavía no hay ningún usuario, redirigir al registro inicial.
if (!userExists()) {
    header('Location: ' . $baseUrl . '/register.php');
    exit;
}

$error = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $error = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        $emailValue = trim($_POST['email'] ?? '');
        $password   = (string)($_POST['password'] ?? '');

        if (loginUser($emailValue, $password)) {
            header('Location: ' . $baseUrl . '/index.php');
            exit;
        }
        $error = 'Email o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión · Talent Filter</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/auth.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="auth-body">
    <main class="auth-card" role="main">
        <div class="auth-header">
            <div class="auth-logo"><i class="fas fa-filter"></i></div>
            <h1>Talent Filter</h1>
            <p>Inicia sesión para acceder al panel</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="auth-error" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <form method="post" class="auth-form" autocomplete="on" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <label>
                <span>Email</span>
                <input
                    type="email"
                    name="email"
                    required
                    autofocus
                    autocomplete="username"
                    value="<?= htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="tu@correo.com"
                >
            </label>

            <label>
                <span>Contraseña</span>
                <input
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                >
            </label>

            <button type="submit" class="auth-btn">
                <i class="fas fa-sign-in-alt"></i>
                Entrar
            </button>
        </form>

        <div class="auth-footer">
            &copy; <?= date('Y') ?> Talent Filter
        </div>
    </main>
</body>
</html>
