<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
if (session_status()===PHP_SESSION_NONE) session_start();
$has_key = ($_GET['key'] ?? '') === 'mehkam_dev_2024';
$is_admin = isset($_SESSION['role']) && $_SESSION['role']==='admin';
if (!$has_key && !$is_admin) die('أضف ?key=mehkam_dev_2024');
header('Content-Type: text/html; charset=utf-8');

$host = sc($conn,'smtp_host','');
$port = (int)sc($conn,'smtp_port',465);
$enc  = sc($conn,'smtp_enc','ssl');
$user = sc($conn,'smtp_user','');
$pass = sc($conn,'smtp_pass','');

$log = [];
$ok  = false;

if ($host && $user && $pass) {
    $ctx    = stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $target = ($enc==='ssl') ? "ssl://$host:$port" : "tcp://$host:$port";
    $log[]  = "الاتصال بـ $target ...";
    $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);

    if (!$socket) {
        $log[] = "❌ فشل الاتصال: $errstr ($errno)";
    } else {
        $log[] = "✅ اتصال ناجح";
        stream_set_timeout($socket,15);

        $read = function() use ($socket) {
            $r='';
            while(!feof($socket)){
                $l=fgets($socket,1024); $r.=$l;
                if(strlen($l)>=4&&$l[3]===' ') break;
            }
            return trim($r);
        };
        $cmd = function($c) use ($socket,$read,&$log) {
            fputs($socket,$c."\r\n");
            $r=$read();
            $log[] = "→ ".htmlspecialchars($c)." | ← ".htmlspecialchars($r);
            return $r;
        };

        $greeting = $read();
        $log[] = "← (greeting) ".htmlspecialchars($greeting);

        $cmd('EHLO localhost');

        if ($enc==='tls') {
            $r=$cmd('STARTTLS');
            if(str_starts_with($r,'220')){
                stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd('EHLO localhost');
            } else {
                $log[]="❌ STARTTLS رُفض";
            }
        }

        // AUTH LOGIN
        $r1 = $cmd('AUTH LOGIN');
        if (str_starts_with($r1,'334')) {
            $cmd(base64_encode($user));
            $auth = $cmd(base64_encode($pass));
        } else {
            // AUTH PLAIN
            $plain = base64_encode("\0$user\0$pass");
            $auth  = $cmd("AUTH PLAIN $plain");
        }

        if (str_starts_with($auth,'235')) {
            $log[] = "✅ تسجيل الدخول نجح!";
            $ok = true;
        } else {
            $log[] = "❌ فشل المصادقة";
        }

        fputs($socket,"QUIT\r\n");
        fclose($socket);
    }
} else {
    $log[] = "❌ يرجى ملء smtp_host وsmtp_user وsmtp_pass في الإعدادات أولاً";
}
?>
<!DOCTYPE html><html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><title>اختبار SMTP</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
</head>
<body style="background:#f1f5f9;padding:30px">
<div style="max-width:750px;margin:0 auto">
<h5 class="mb-3">🔧 تشخيص SMTP</h5>
<div class="mb-3 p-3 rounded" style="background:#1e293b;color:#e2e8f0;font-family:monospace;font-size:13px;line-height:1.9">
  <div><strong style="color:#94a3b8">Host:</strong> <?= e($host) ?>:<?= $port ?> (<?= $enc ?>)</div>
  <div><strong style="color:#94a3b8">User:</strong> <?= e($user) ?></div>
  <div><strong style="color:#94a3b8">Pass:</strong> <?= $pass ? str_repeat('*',strlen($pass)) : '(فارغ)' ?></div>
  <hr style="border-color:#334155;margin:10px 0">
  <?php foreach($log as $l): ?>
  <div><?= $l ?></div>
  <?php endforeach; ?>
</div>
<?php if($ok): ?>
<div class="alert alert-success">✅ الاتصال والمصادقة نجحا — إعدادات SMTP صحيحة</div>
<?php else: ?>
<div class="alert alert-danger">❌ فشل — راجع السجل أعلاه للسبب</div>
<?php endif; ?>
<a href="settings.php" class="btn btn-outline-secondary btn-sm">← العودة للإعدادات</a>
</div>
</body></html>
