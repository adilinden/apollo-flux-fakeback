<?php
// Request / proxy diagnostic page for the staging backend. Served at /info.
// Deliberately not phpinfo(): this host is internet-reachable through the
// proxy, and phpinfo() would expose configuration and environment.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function rows(array $data) {
    foreach ($data as $k => $v) {
        echo '<tr><th>' . h($k) . '</th><td><code>' . h($v) . '</code></td></tr>';
    }
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
ksort($headers, SORT_NATURAL | SORT_FLAG_CASE);

// Do not echo credentials back, only show that they were sent.
$masked = ['authorization', 'proxy-authorization', 'cookie'];
$shown = [];
foreach ($headers as $name => $value) {
    $shown[$name] = in_array(strtolower($name), $masked, true)
        ? '(present, ' . strlen($value) . ' bytes, value hidden)'
        : $value;
}

$lc = array_change_key_case($headers, CASE_LOWER);
$xff = $lc['x-forwarded-for'] ?? '';
$chain = array_values(array_filter(array_map('trim', explode(',', $xff))));

$conn = [
    'REMOTE_ADDR (TCP peer Apache sees)' => $_SERVER['REMOTE_ADDR'] ?? '',
    'REMOTE_PORT'                        => $_SERVER['REMOTE_PORT'] ?? '',
    'SERVER_ADDR'                        => $_SERVER['SERVER_ADDR'] ?? '',
    'SERVER_PORT'                        => $_SERVER['SERVER_PORT'] ?? '',
    'HTTP_HOST'                          => $_SERVER['HTTP_HOST'] ?? '',
    'REQUEST_SCHEME (hop to Apache)'     => $_SERVER['REQUEST_SCHEME'] ?? '',
    'HTTPS env'                          => $_SERVER['HTTPS'] ?? '(unset)',
    'SERVER_PROTOCOL'                    => $_SERVER['SERVER_PROTOCOL'] ?? '',
    'REQUEST_METHOD'                     => $_SERVER['REQUEST_METHOD'] ?? '',
    'REQUEST_URI'                        => $_SERVER['REQUEST_URI'] ?? '',
];

$proxy = [
    'X-Forwarded-For'   => $lc['x-forwarded-for']   ?? '(absent)',
    'X-Forwarded-Proto' => $lc['x-forwarded-proto'] ?? '(absent)',
    'X-Forwarded-Host'  => $lc['x-forwarded-host']  ?? '(absent)',
    'Forwarded'         => $lc['forwarded']         ?? '(absent)',
    'Via'               => $lc['via']               ?? '(absent)',
    'Client IP (first X-Forwarded-For entry)' => $chain[0] ?? '(none, request was not proxied)',
    'X-Forwarded-For hops' => count($chain),
];

$server = [
    'Server time (UTC)' => gmdate('Y-m-d H:i:s') . ' UTC',
    'Container hostname' => gethostname(),
    'Server software'    => $_SERVER['SERVER_SOFTWARE'] ?? '',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>info</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: system-ui, sans-serif; max-width: 60rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.4; }
  h1 { font-size: 1.3rem; } h2 { font-size: 1.05rem; margin-top: 1.6rem; }
  table { border-collapse: collapse; width: 100%; }
  th, td { text-align: left; padding: .25rem .6rem; border-bottom: 1px solid #8884; vertical-align: top; }
  th { width: 22rem; font-weight: 600; }
  code { font-family: ui-monospace, monospace; word-break: break-all; }
</style>
</head>
<body>
<h1>apollo-flux request info</h1>

<h2>Connection as seen by Apache</h2>
<table><?php rows($conn); ?></table>

<h2>Proxy headers</h2>
<table><?php rows($proxy); ?></table>

<h2>All request headers</h2>
<table><?php rows($shown); ?></table>

<h2>Server</h2>
<table><?php rows($server); ?></table>
</body>
</html>
