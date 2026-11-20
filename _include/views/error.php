<?php

declare(strict_types = 1);

use Register\Core\Asset\PublicAssetUrl;
use Register\Core\Http\EmergencyPage;
use Register\Core\Monitoring\FailureDiagnostics;

$parameters = $GLOBALS['registerBaseStaticParameters'] ?? [];
$basePath = (string)($parameters['base_path'] ?? '');
$text = (new EmergencyPage(dirname(__DIR__, 2), (string)($parameters['cache_dir'] ?? '')))->text();
$stylesheetPath = $basePath . '/_assets/register/standalone.css';
try {
    $stylesheetPath = (new PublicAssetUrl(dirname(__DIR__, 2), $basePath))->versioned('/_assets/register/standalone.css');
} catch (\Throwable) {
    // An unavailable asset manifest must not prevent the error response.
}
$homePath = $basePath . '/';
$diagnostics = $GLOBALS['registerFailureDiagnostics'] ?? null;
$incidentId = $diagnostics instanceof FailureDiagnostics ? $diagnostics->incidentId() : null;
// Retry only navigates to a URL. It cannot repeat a failed POST such as a comment.
$retryPath = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    ? (string)($_SERVER['REQUEST_URI'] ?? $homePath) : $homePath;
if (!str_starts_with($retryPath, '/') || str_starts_with($retryPath, '//')
    || preg_match('/[\x00-\x20\x7f\\\\]/', $retryPath) === 1
) {
    $retryPath = $homePath;
}
if (!headers_sent()) {
    header('Cache-Control: no-store, private');
    if ($incidentId !== null) {
        header('X-Register-Incident: ' . $incidentId);
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo register_htmlencode($text['locale']); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="Generator" content="Register">
    <title><?php echo register_htmlencode($text['Emergency title']); ?></title>
    <link rel="stylesheet" href="<?php echo register_htmlencode($stylesheetPath); ?>">
</head>
<body class="register-standalone">
<main class="standalone-card error-container">
    <h1><?php echo register_htmlencode($text['Emergency title']); ?></h1>
    <p><?php echo register_htmlencode($text['Emergency message']); ?></p>
    <p class="install-action emergency-actions">
        <a class="link-button" href="<?php echo register_htmlencode($retryPath); ?>"><?php echo register_htmlencode($text['Emergency reload']); ?></a>
        <a href="<?php echo register_htmlencode($homePath); ?>"><?php echo register_htmlencode($text['Emergency home']); ?></a>
    </p>
    <?php if ($incidentId !== null): ?>
    <p class="help"><?php echo register_htmlencode($text['Emergency reference']); ?> <code><?php echo register_htmlencode($incidentId); ?></code></p>
    <?php endif; ?>
</main>
</body>
</html>
