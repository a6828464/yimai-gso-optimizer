<?php
define('ABSPATH', '/tmp/');
define('YIMAI_GSO_PLUGIN_DIR', '/tmp/fake-plugin/');
define('YIMAI_GSO_VERSION', '2.14.6');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
if (!is_dir('/tmp/fake-plugin')) { mkdir('/tmp/fake-plugin', 0777, true); }

$GLOBALS['tr'] = array();
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['tr'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); return true; }
function current_time($f) { return date('Y-m-d H:i:s'); }
function get_temp_dir() { return '/tmp/'; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_delete_file($f) { @unlink($f); }
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_json_encode($d, $f = 0) { return json_encode($d); }
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } }

function wp_remote_get($url, $args = array()) {
    $timeout = $args['timeout'] ?? 5;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => $args['user-agent'] ?? 'harness',
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (false === $body) { return new WP_Error('http_fail', 'curl failed'); }
    return array('response' => array('code' => $code), 'body' => $body);
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }

require 'includes/class-updater.php';

// 1) 真实清单（走 GitHub 通道，模拟服务器端）
$manifestSrc = null;
if (getenv('FORCE_SOURCE')) { set_transient('yimai_gso_update_manifest_source', getenv('FORCE_SOURCE'), 3600); }
$m = YIMAI_GSO_Updater::check($manifestSrc);
echo "check ok=" . ($m['ok'] ? 'yes' : 'no') . " source=" . $manifestSrc . " remote=" . ($m['remote']['version'] ?? '?') . "\n";
$manifest = $m['remote'] ?? null;

// 2) 下载（含 sha256 逐通道校验）
$errors = array();
$r = new ReflectionMethod('YIMAI_GSO_Updater', 'download_package');
$r->setAccessible(true);
try {
    list($zip, $src) = $r->invoke(null, '/tmp/harness-pkg.zip', $errors, $manifest);
    echo "download OK: source=$src size=" . filesize($zip) . "\n";
} catch (Throwable $e) {
    echo "download FAIL: " . $e->getMessage() . "\n";
    print_r($errors);
    exit(1);
}

// 3) 解压 + 版本识别
$e2 = new ReflectionMethod('YIMAI_GSO_Updater', 'extract');
$e2->setAccessible(true);
try {
    $root = $e2->invoke(null, $zip, '/tmp/harness-extract');
    $rv = new ReflectionMethod('YIMAI_GSO_Updater', 'read_package_version');
    $rv->setAccessible(true);
    $v = $rv->invoke(null, $root . '/yimai-gso-optimizer.php');
    echo "extract OK: root=$root version=$v\n";
    echo "has tools/: " . (is_dir($root . '/tools') ? 'yes (BAD)' : 'no (OK)') . "\n";
} catch (Throwable $e) {
    echo "extract FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
