<?php
// TEMPORARY diagnostic route: GET /api/diag
// Upload to public_html/mjadc-api/routes/ — DELETE after use.
$router->get('/api/diag', function () {
    ob_start();
    echo "PHP " . PHP_VERSION . "\n\n== index.php require list ==\n";
    $root = dirname(__DIR__, 2);
    $src = @file_get_contents($root . '/index.php');
    if ($src === false) { echo "CANNOT READ index.php at $root\n"; }
    else {
        preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*\'([^\']+)\'/', $src, $m);
        $missing = 0;
        foreach ($m[1] as $rel) {
            $ok = file_exists($root . '/' . $rel);
            if (!$ok) $missing++;
            echo ($ok ? 'OK      ' : 'MISSING ') . $rel . "\n";
        }
        echo $missing === 0 ? "\nAll required files present.\n" : "\n*** $missing MISSING ***\n";
    }

    echo "\n== DB tables existence ==\n";
    $tables = ['custom_entry_fields', 'exam_routines', 'students', 'subject_papers'];
    foreach ($tables as $t) {
        try {
            $r = Database::fetch("SHOW TABLES LIKE ?", [$t]);
            echo ($r ? 'OK      ' : 'MISSING ') . $t . "\n";
        } catch (Throwable $e) {
            echo 'ERROR   ' . $t . ': ' . $e->getMessage() . "\n";
        }
    }

    echo "\n== class/method availability ==\n";
    echo 'Response::created: ' . (method_exists('Response', 'created') ? 'YES' : 'NO') . "\n";
    echo 'Auth::requireAnyRole: ' . (method_exists('Auth', 'requireAnyRole') ? 'YES' : 'NO') . "\n";
    echo 'Database::delete: ' . (method_exists('Database', 'delete') ? 'YES' : 'NO') . "\n";
    echo 'function validate: ' . (function_exists('validate') ? 'YES' : 'NO') . "\n";

    $out = ob_get_clean();
    Response::success(['report' => $out]);
});
