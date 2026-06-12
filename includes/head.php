<?php
/** Layout — En-tête HTML partagé (frontend uniquement) */
if (!isset($pageTitle)) $pageTitle = 'LokaGest';
if (!isset($basePath)) $basePath = '..';
if (!isset($bodyClass)) $bodyClass = 'lokagest-app';
if (!isset($extraHead)) $extraHead = '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($pageTitle); ?> - LokaGest</title>
    <meta name="theme-color" content="#16A34A">
    <link rel="manifest" href="<?php echo $basePath; ?>/manifest.json">
    <link rel="apple-touch-icon" href="<?php echo $basePath; ?>/icons/icon-192x192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css">
    <?php echo $extraHead; ?>
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>">
