<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/_private/views/application-shell.php';
$checks = 0;
function shell_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$_SESSION = ['customer'=>'separate', 'vendor'=>'separate', 'staff_until'=>123];
$session = $_SESSION;
$sessionId = session_id();
$form = '<form method="post"><input type="hidden" name="csrf" value="retained"><button name="action" value="logout">Sign Out</button></form>';
$links = [
    'customer'=>['/account.php', '/account.php?view=profile', '/account.php?view=new'],
    'vendor'=>['/vendor.php'],
    'staff'=>['/staff-bookings.php', '/staff-production.php', '/staff-vendors.php'],
];
foreach ($links as $role=>$expected) {
    foreach ([false, true] as $authenticated) {
        $html = site_application_shell($role, 'Title <script>&"', $form, $authenticated, $role === 'staff' ? 'body{margin:0}' : '');
        shell_check(substr_count($html, '<main id="content">') === 1, $role.' has one content target');
        shell_check(substr_count($html, '<h1>') === 1, $role.' has one page heading');
        shell_check(str_contains($html, '#content'), $role.' retains keyboard skip target');
        shell_check(str_contains($html, '<h1>Title &lt;script&gt;&amp;&quot;</h1>'), $role.' escapes title');
        shell_check(str_contains($html, $form) && substr_count($html, 'value="logout"') === 1, $role.' retains body/form bytes once');
        shell_check(str_contains($html, 'noindex,nofollow,noarchive'), $role.' excludes private pages from indexing');
        shell_check(str_contains($html, 'TEST ACCESS') && str_contains($html, 'payments remain in test mode'), $role.' retains TEST label');
        shell_check(str_contains($html, '/portal-assets/application.css?v=unified-shell-r1'), $role.' shares shell asset');
        shell_check(str_contains($html, 'portal.js') === ($role === 'customer'), $role.' keeps customer script confined');
        preg_match('~<nav\b[^>]*>(.*?)</nav>~s', $html, $nav);
        shell_check(isset($nav[1]) === $authenticated, $role.' navigation requires caller authentication');
        if ($authenticated) {
            preg_match_all('~href="([^"]+)"~', $nav[1], $actual);
            shell_check($actual[1] === $expected, $role.' keeps only its own navigation');
        }
    }
}
shell_check($_SESSION === $session && session_id() === $sessionId, 'Rendering does not start, merge or mutate sessions');
foreach ([['unknown',''], ['vendor','body{}'], ['customer','body{}']] as [$role,$style]) {
    try { site_application_shell($role, '', '', false, $style); throw new RuntimeException('Unexpected role/style acceptance'); }
    catch (InvalidArgumentException) { $checks++; }
}

// Compare preserved controls to original reviewed source, independently of layout output.
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/documents/unified/shell-source.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $path=>$item) {
    $before = base64_decode($item['before'], true);
    $after = file_get_contents($root.'/'.$path);
    shell_check(hash('sha256', $before) === $item['before_sha256'], $path.' original source integrity');
    shell_check(hash('sha256', $after) === $item['after_sha256'], $path.' reviewed source integrity');
    if ($path === '_private/views/portal.php') {
        $marker = 'function portal_sign_in(';
    } elseif ($path === '_private/server/vendor-app.php') {
        $marker = 'function vendor_redirect(';
    } else {
        $marker = 'function staff_csrf(';
        $oldNav = '$body.=\'<nav aria-label="Staff Navigation"><a href="staff-bookings.php">Bookings</a> · <a href="staff-production.php">Production</a> · <a href="staff-vendors.php">Vendors</a></nav>\';'."\n";
        shell_check(substr_count($before, $oldNav) === 1, 'Original staff navigation source located exactly');
        $before = str_replace($oldNav, '', $before);
    }
    shell_check(strpos($before, $marker) !== false && strpos($after, $marker) !== false, $path.' original control boundary located');
    shell_check(substr($before, strpos($before, $marker)) === substr($after, strpos($after, $marker)), $path.' authentication, actions, forms and calculations are byte-identical');
}
echo 'Application shell: '.$checks." layout, role isolation and preserved-control checks passed.\n";
