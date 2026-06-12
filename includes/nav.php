<?php
/** Layout — Navigation mobile bas de page */
if (!isset($basePath)) $basePath = '..';
if (!isset($activeNav)) $activeNav = '';
$navItems = [
    'dashboard'  => ['href' => 'dashboard.php',  'icon' => 'bi-house',         'iconActive' => 'bi-house-fill',         'label' => 'Accueil'],
    'properties' => ['href' => 'properties.php', 'icon' => 'bi-building',      'iconActive' => 'bi-building-fill',      'label' => 'Biens'],
    'history'    => ['href' => 'history.php',    'icon' => 'bi-credit-card',   'iconActive' => 'bi-credit-card-fill',   'label' => 'Paiements'],
    'wallet'     => ['href' => 'wallet.php',     'icon' => 'bi-wallet2',       'iconActive' => 'bi-wallet2-fill',       'label' => 'Wallet'],
    'settings'   => ['href' => 'settings.php',   'icon' => 'bi-gear',          'iconActive' => 'bi-gear-fill',          'label' => 'Réglages'],
];
?>
<nav class="mobile-nav" aria-label="Navigation principale">
    <?php foreach ($navItems as $key => $item): ?>
        <?php $isActive = ($activeNav === $key); ?>
        <a href="<?php echo $item['href']; ?>" class="nav-item<?php echo $isActive ? ' active' : ''; ?>">
            <span class="nav-icon-wrap">
                <i class="bi <?php echo $isActive ? $item['iconActive'] : $item['icon']; ?>"></i>
            </span>
            <span class="nav-label"><?php echo $item['label']; ?></span>
        </a>
    <?php endforeach; ?>
</nav>
