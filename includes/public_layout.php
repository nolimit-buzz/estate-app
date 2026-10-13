<?php
// includes/public_layout.php - shared chrome for public SaaS pages (marketing, sign-up, verification, invitations, payments).
function pl_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function pl_company() { return PlatformSettings::get('site_company_name', 'Estate Platform'); }

function public_head($title, $extra = '') {
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo pl_e($title); ?></title>
    <meta name="description" content="<?php echo pl_e(PlatformSettings::get('site_tagline', '')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/saas.css?v=<?php echo @filemtime(__DIR__ . '/../css/saas.css'); ?>">
    <link rel="stylesheet" href="css/dashboard-theme-public.css?v=<?php echo @filemtime(__DIR__ . '/../css/dashboard-theme-public.css'); ?>">
    <?php echo $extra; ?>
</head>
<body>
<?php
}

function public_nav($mode = 'full') {
    $co = pl_company();
    ?>
<header class="nav">
    <div class="wrap">
        <a class="brand" href="index"><span class="brand-mark"></span><?php echo pl_e($co); ?></a>
        <?php if ($mode === 'full'): ?>
        <nav class="nav-links" id="navLinks">
            <a href="#features">Features</a>
            <a href="#modules">Modules</a>
            <a href="#pricing">Pricing</a>
            <a href="#security">Security</a>
            <a href="#about">About</a>
            <a href="#support">Support</a>
        </nav>
        <?php endif; ?>
        <div class="nav-cta">
            <a class="btn btn-ghost btn-sm" href="login">Log in</a>
            <a class="btn btn-primary btn-sm" href="signup">Sign up</a>
            <?php if ($mode === 'full'): ?><button class="menu-btn" type="button" onclick="document.getElementById('navLinks').classList.toggle('open')" aria-label="Menu">Menu</button><?php endif; ?>
        </div>
    </div>
</header>
<?php
}

function public_footer() {
    $co = pl_company();
    $links = ['website' => 'Website', 'facebook' => 'Facebook', 'x' => 'X', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn'];
    ?>
<footer>
    <div class="wrap">
        <div class="cols">
            <div>
                <a class="brand" href="index"><span class="brand-mark"></span><?php echo pl_e($co); ?></a>
                <p class="muted" style="margin-top:12px;max-width:26em"><?php echo pl_e(PlatformSettings::get('site_tagline', '')); ?></p>
            </div>
            <div><h4>Product</h4><ul><li><a href="index#modules">Modules</a></li><li><a href="index#pricing">Pricing</a></li><li><a href="index#security">Security</a></li></ul></div>
            <div><h4>Get started</h4><ul><li><a href="signup">Register your estate</a></li><li><a href="login">Log in</a></li><li><a href="index#support">Support</a></li></ul></div>
            <div><h4>Follow</h4><ul>
                <?php foreach ($links as $k => $label): $u = PlatformSettings::get('site_' . $k, ''); if ($u): ?><li><a href="<?php echo pl_e($u); ?>" rel="noopener" target="_blank"><?php echo $label; ?></a></li><?php endif; endforeach; ?>
            </ul></div>
        </div>
        <div class="copy">&copy; <?php echo date('Y'); ?> <?php echo pl_e($co); ?>. All rights reserved.</div>
    </div>
</footer>
</body>
</html>
<?php
}
