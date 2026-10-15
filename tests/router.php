<?php
// Local test server only; never included in the distributable ZIP.
$root=rtrim(getenv('VISITAS_WP_ROOT'),'/');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path!=='/' && (is_file($root.$path) || is_dir($root.$path))) { return false; }
$_SERVER['SCRIPT_FILENAME']=$root.'/index.php';
require $root.'/index.php';
