<?php

declare(strict_types = 1);

use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Application;

if (isset($GLOBALS['app']) && $GLOBALS['app'] instanceof Application) {
    $container      = $GLOBALS['app']->container;
    $siteName       = $container->get(DynamicConfigProvider::class)->getStringProxy('REGISTER_SITE_NAME')->get();
    $stylesheetPath = $container->getStringParameter('base_path') . '/_assets/register/standalone.css';
} else {
    return;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="Generator" content="Register">
    <title>Error - <?php echo register_htmlencode($siteName); ?></title>
    <link rel="stylesheet" href="<?php echo register_htmlencode($stylesheetPath); ?>">
</head>
<body class="register-standalone">
<main class="standalone-card error-container">
    <h1>An error was encountered</h1>

    <p>
        Please refer to logs to find out the cause.
    </p>

    <p>
        <strong>Note:</strong> For detailed error information (necessary for troubleshooting), enable "DEBUG mode".
        To enable "DEBUG mode", open up the file config.php in a text editor, add a line that looks like
        <code>define('REGISTER_DEBUG', 1);</code> before "return" statement and re-upload the file.
        Once you've solved the problem, it is recommended that "DEBUG mode" be turned off again
        (just remove the line from the file and re-upload it).
    </p>
</main>
</body>
</html>
