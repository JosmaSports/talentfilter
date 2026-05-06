<?php
require_once __DIR__ . '/includes/auth.php';

$baseUrl = authBaseUrl();

// Si ya hay sesión activa, ir al panel.
if (isLoggedIn()) {
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

// Una vez registrado el usuario inicial, esta página queda inutilizable.
$alreadyRegistered = userExists();

$error   = '';
$success = '';
$nameValue  = '';
$emailValue = ALLOWED_REGISTRATION_EMAIL;

if (!$alreadyRegistered && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $error = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        $emailValue   = trim($_POST['email'] ?? '');
        $nameValue    = trim($_POST['name']  ?? '');
        $password     = (string)($_POST['password']  ?? '');
        $passwordConf = (string)($_POST['password2'] ?? '');

        if ($password !== $passwordConf) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            $result = registerInitialUser($emailValue, $password, $nameValue);
            if ($result['ok']) {
                $success = 'Usuario creado correctamente. Redirigiendo al login…';
                header('Refresh: 2; url=' . $baseUrl . '/login.php');
            } else {
                $error = $result['msg'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro inicial · Talent Filter</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/auth.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="auth-body">
    <main class="auth-card" role="main">
        <div class="auth-header">
            <div class="auth-logo"><i class="fas fa-user-plus"></i></div>
            <h1>Registro inicial</h1>
            <p>Crea la contraseña del usuario principal</p>
        </div>

        <?php if ($alreadyRegistered): ?>
            <div class="auth-error" role="alert">
                <i class="fas fa-lock"></i>
                <span>
                    Ya existe un usuario registrado. Esta página está cerrada.
                    Puedes <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/login.php">iniciar sesión</a>.
                </span>
            </div>
        <?php else: ?>

            <?php if ($error !== ''): ?>
                <div class="auth-error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="auth-success" role="status">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
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
                        autocomplete="username"
                        value="<?= htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8') ?>"
                        readonly
                    >
                    <small class="auth-hint">Solo se permite registrar este email.</small>
                </label>

                <label>
                    <span>Nombre <small style="color:var(--gray-500);font-weight:400">(opcional)</small></span>
                    <input
                        type="text"
                        name="name"
                        autocomplete="name"
                        value="<?= htmlspecialchars($nameValue, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Susana Rivero"
                    >
                </label>

                <label>
                    <span>Contraseña</span>
                    <input
                        type="password"
                        name="password"
                        required
                        minlength="<?= MIN_PASSWORD_LENGTH ?>"
                        autocomplete="new-password"
                        placeholder="Mínimo <?= MIN_PASSWORD_LENGTH ?> caracteres"
                    >
                </label>

                <label>
                    <span>Confirmar contraseña</span>
                    <input
                        type="password"
                        name="password2"
                        required
                        minlength="<?= MIN_PASSWORD_LENGTH ?>"
                        autocomplete="new-password"
                        placeholder="Repite la contraseña"
                    >
                </label>

                <button type="submit" class="auth-btn"<?= $success !== '' ? ' disabled' : '' ?>>
                    <i class="fas fa-user-plus"></i>
                    Crear usuario
                </button>
            </form>

        <?php endif; ?>

        <div class="auth-footer">
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/login.php">Volver al login</a>
        </div>
    </main>
</body>
</html>
