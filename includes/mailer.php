<?php
/**
 * mailer.php — مرسل SMTP بدون مكتبات خارجية
 */

/**
 * إرسال إيميل عبر SMTP
 * @return array ['ok'=>bool, 'error'=>string]
 */
/**
 * @param array $attachments كل عنصر: ['name'=>'x.pdf', 'data'=>'<bytes>', 'mime'=>'application/pdf']
 */
function sendMail($conn, string $to_email, string $to_name, string $subject, string $html_body, array $attachments = []): array
{
    $host       = sc($conn, 'smtp_host', '');
    $port       = (int)sc($conn, 'smtp_port', 587);
    $enc        = strtolower(sc($conn, 'smtp_enc', 'tls'));
    $user       = sc($conn, 'smtp_user', '');
    $pass       = sc($conn, 'smtp_pass', '');
    $from_email = sc($conn, 'smtp_from_email', sc($conn, 'contact_email', ''));
    $from_name  = sc($conn, 'smtp_from_name', sc($conn, 'site_name', 'مِحكام'));

    if (sc($conn, 'smtp_enabled', '0') !== '1') {
        return ['ok' => false, 'error' => 'SMTP معطّل — فعّله من الإعدادات'];
    }
    if (!$host || !$user || !$pass || !$from_email) {
        $missing = array_filter(['smtp_host'=>$host,'smtp_user'=>$user,'smtp_pass'=>$pass,'smtp_from_email'=>$from_email], fn($v)=>!$v);
        return ['ok' => false, 'error' => 'إعدادات SMTP ناقصة: ' . implode('، ', array_keys($missing))];
    }

    try {
        // اتصال TCP أو SSL
        $ctx    = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $target = ($enc === 'ssl') ? "ssl://$host:$port" : "tcp://$host:$port";
        $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);

        if (!$socket) {
            return ['ok' => false, 'error' => "فشل الاتصال بـ $host:$port — $errstr ($errno)"];
        }
        stream_set_timeout($socket, 15);

        // قراءة استجابة SMTP
        $read = function () use ($socket): string {
            $resp = '';
            while (!feof($socket)) {
                $line  = fgets($socket, 1024);
                $resp .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') break;
            }
            return $resp;
        };

        // إرسال أمر وقراءة الرد
        $cmd = function (string $c) use ($socket, $read): string {
            fputs($socket, $c . "\r\n");
            return $read();
        };

        $read(); // greeting
        $cmd('EHLO localhost');

        // STARTTLS
        if ($enc === 'tls') {
            $r = $cmd('STARTTLS');
            if (!str_starts_with(trim($r), '220')) {
                fclose($socket);
                return ['ok' => false, 'error' => 'STARTTLS رُفض: ' . trim($r)];
            }
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO localhost');
        }

        // AUTH — try LOGIN first, fall back to PLAIN
        $auth = '';
        $ehlo_resp = '';
        // re-read EHLO capabilities after TLS upgrade (already done above, but we need the text)
        // try AUTH LOGIN
        $r1 = $cmd('AUTH LOGIN');
        if (str_starts_with(trim($r1), '334')) {
            $cmd(base64_encode($user));
            $auth = $cmd(base64_encode($pass));
        } else {
            // server doesn't support AUTH LOGIN or rejected it — try AUTH PLAIN
            $plain = base64_encode("\0$user\0$pass");
            $auth  = $cmd("AUTH PLAIN $plain");
        }
        if (!str_starts_with(trim($auth), '235')) {
            fclose($socket);
            return ['ok' => false, 'error' => 'فشل تسجيل الدخول للـ SMTP: ' . trim($auth)];
        }

        // الرسالة
        $cmd("MAIL FROM:<$from_email>");
        $r = $cmd("RCPT TO:<$to_email>");
        if (!str_starts_with(trim($r), '250')) {
            fclose($socket);
            return ['ok' => false, 'error' => 'بريد المستلم مرفوض: ' . trim($r)];
        }

        $cmd('DATA');

        $fn  = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
        $tn  = $to_name ? '=?UTF-8?B?' . base64_encode($to_name) . '?= ' : '';
        $sub = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers  = "From: $fn <$from_email>\r\n";
        $headers .= "To: {$tn}<$to_email>\r\n";
        $headers .= "Reply-To: $from_email\r\n";
        $headers .= "Subject: $sub\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Mailer: Mehkam-Mailer\r\n";

        $valid_att = array_filter($attachments, fn($a) => !empty($a['data']) && !empty($a['name']));

        if ($valid_att) {
            $bnd = 'mehkam_' . md5(uniqid('', true));
            $headers .= "Content-Type: multipart/mixed; boundary=\"$bnd\"\r\n";
            $body  = "--$bnd\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($html_body)) . "\r\n";
            foreach ($valid_att as $a) {
                $mime = $a['mime'] ?? 'application/octet-stream';
                $nm   = '=?UTF-8?B?' . base64_encode($a['name']) . '?=';
                $body .= "--$bnd\r\n";
                $body .= "Content-Type: $mime; name=\"$nm\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= "Content-Disposition: attachment; filename=\"$nm\"\r\n\r\n";
                $body .= chunk_split(base64_encode($a['data'])) . "\r\n";
            }
            $body .= "--$bnd--\r\n";
        } else {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $body = chunk_split(base64_encode($html_body));
        }

        fputs($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $done = $read();

        $cmd('QUIT');
        fclose($socket);

        return str_starts_with(trim($done), '250')
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'خطأ في الإرسال: ' . trim($done)];

    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * قالب HTML للإيميل
 */
/** رابط شعار المنصّة بصيغة مطلقة للاستخدام في البريد (أو '' للرجوع للنص). */
function mail_logo_abs($conn): string
{
    $lg = sc($conn, 'site_logo', '');
    if ($lg === '') return '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';
    $sch = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $sch . '://' . $host . '/' . ltrim($lg, '/');
}

function mailHtml($site_name, string $title, string $content, string $footer_note = '', string $logo_url = ''): string
{
    $year = date('Y');
    $brand = $logo_url !== ''
        ? '<img src="' . htmlspecialchars($logo_url, ENT_QUOTES) . '" alt="' . htmlspecialchars($site_name, ENT_QUOTES) . '" style="max-height:38px;max-width:190px">'
        : '<div class="hdr-logo" style="font-size:28px;font-weight:900;color:#c9a227;letter-spacing:-1px">مِحكام</div>';
    return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<style>
  body{margin:0;padding:0;background:#f3f4f6;font-family:'Segoe UI',Tahoma,Arial,sans-serif;direction:rtl;text-align:right}
  table{border-collapse:collapse;width:100%}
  .wrap{max-width:560px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .hdr{background:linear-gradient(135deg,#0a1628,#1a3a6e);padding:28px 32px;text-align:center}
  .hdr-logo{font-size:28px;font-weight:900;color:#c9a227;letter-spacing:-1px}
  .hdr-sub{font-size:13px;color:rgba(255,255,255,.55);margin-top:4px}
  .body{padding:36px 32px;direction:rtl;text-align:right}
  .body h2{color:#0a1628;font-size:20px;font-weight:800;margin:0 0 16px;direction:rtl;text-align:right}
  .body p{color:#374151;font-size:14px;line-height:1.8;margin:0 0 14px;direction:rtl;text-align:right}
  .otp-box{background:#f0f4ff;border:2px dashed #1a3a6e;border-radius:12px;text-align:center;padding:22px;margin:20px 0}
  .otp-code{font-size:40px;font-weight:900;color:#0a1628;letter-spacing:10px;direction:ltr;display:block}
  .otp-note{font-size:12px;color:#6b7280;margin-top:6px}
  .btn{display:inline-block;background:linear-gradient(135deg,#0a1628,#1a3a6e);color:#fff !important;text-decoration:none;padding:14px 32px;border-radius:10px;font-size:14px;font-weight:700;margin:16px 0}
  .warn-box{background:#fef3c7;border-right:4px solid #f59e0b;padding:12px 16px;border-radius:8px;font-size:13px;color:#78350f;margin:16px 0;direction:rtl;text-align:right}
  .ftr{background:#f9fafb;border-top:1px solid #f3f4f6;padding:18px 32px;text-align:center;font-size:12px;color:#9ca3af;direction:rtl}
</style>
</head>
<body dir="rtl" style="margin:0;padding:0;background:#f3f4f6;font-family:'Segoe UI',Tahoma,Arial,sans-serif;direction:rtl;text-align:right">
<div class="wrap" dir="rtl" style="max-width:560px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden">
  <div class="hdr" style="background:linear-gradient(135deg,#0a1628,#1a3a6e);padding:28px 32px;text-align:center">
    {$brand}
    <div class="hdr-sub" style="font-size:13px;color:rgba(255,255,255,.55);margin-top:4px">{$site_name}</div>
  </div>
  <div class="body" dir="rtl" style="padding:36px 32px;direction:rtl;text-align:right">
    <h2 style="color:#0a1628;font-size:20px;font-weight:800;margin:0 0 16px;direction:rtl;text-align:right">{$title}</h2>
    {$content}
  </div>
  <div class="ftr" style="background:#f9fafb;border-top:1px solid #f3f4f6;padding:18px 32px;text-align:center;font-size:12px;color:#9ca3af;direction:rtl">
    {$footer_note}
    <br>© {$year} {$site_name} — جميع الحقوق محفوظة<br>
    هذا البريد أُرسل تلقائياً، يرجى عدم الرد عليه
  </div>
</div>
</body>
</html>
HTML;
}

/**
 * إرسال كود OTP للتحقق الثنائي
 */
function sendOtpEmail($conn, string $to_email, string $to_name, string $otp): array
{
    $site = sc($conn, 'site_name', 'مِحكام');
    $content = "
<p>مرحباً <strong>" . htmlspecialchars($to_name) . "</strong>،</p>
<p>تم طلب الدخول إلى حسابك على منصة <strong>{$site}</strong>. استخدم الرمز التالي لإتمام تسجيل الدخول:</p>
<div class='otp-box'>
  <div class='otp-code'>{$otp}</div>
  <div class='otp-note'>صالح لمدة 10 دقائق فقط</div>
</div>
<div class='warn-box'>إذا لم تطلب الدخول، يرجى تأمين حسابك فوراً والتواصل مع الدعم.</div>
";
    $html = mailHtml($site, 'رمز التحقق الثنائي', $content, 'رسالة أمنية — لا تشاركها مع أحد', mail_logo_abs($conn));
    return sendMail($conn, $to_email, $to_name, "رمز التحقق — {$site}", $html);
}

/**
 * إرسال رابط تأكيد البريد الإلكتروني
 */
function sendVerifyEmail($conn, string $to_email, string $to_name, string $verify_url): array
{
    $site = sc($conn, 'site_name', 'مِحكام');
    $content = "
<p>مرحباً <strong>" . htmlspecialchars($to_name) . "</strong>،</p>
<p>شكراً لتسجيلك في منصة <strong>{$site}</strong>. يرجى تأكيد بريدك الإلكتروني بالنقر على الزر أدناه:</p>
<p style='text-align:center'>
  <a href='" . htmlspecialchars($verify_url) . "' class='btn'>تأكيد البريد الإلكتروني</a>
</p>
<p style='font-size:13px;color:#6b7280'>أو انسخ الرابط التالي وافتحه في المتصفح:</p>
<p style='font-size:12px;color:#6b7280;word-break:break-all'>" . htmlspecialchars($verify_url) . "</p>
<div class='warn-box'>الرابط صالح لمدة 24 ساعة. إذا لم تسجّل في المنصة، تجاهل هذا البريد.</div>
";
    $html = mailHtml($site, 'تأكيد البريد الإلكتروني', $content, '', mail_logo_abs($conn));
    return sendMail($conn, $to_email, $to_name, "تأكيد بريدك الإلكتروني — {$site}", $html);
}

/**
 * إرسال إيميل ترحيب بعد التسجيل
 */
function sendWelcomeEmail($conn, string $to_email, string $to_name, string $office_name, string $pkg_name): array
{
    $site = sc($conn, 'site_name', 'مِحكام');
    $login_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/mehkam/login.php';
    $content = "
<p>مرحباً <strong>" . htmlspecialchars($to_name) . "</strong>،</p>
<p>تم استلام طلب تسجيل مكتب <strong>" . htmlspecialchars($office_name) . "</strong> بنجاح.</p>
<p>سيتم مراجعة طلبكم وتفعيل الحساب خلال <strong>24–48 ساعة</strong> من تأكيد الدفع. ستصلك رسالة بريد إلكتروني عند التفعيل.</p>
<p><strong>الباقة المختارة:</strong> {$pkg_name}</p>
<p style='text-align:center'>
  <a href='" . htmlspecialchars($login_url) . "' class='btn'>الذهاب لصفحة الدخول</a>
</p>
";
    $html = mailHtml($site, "مرحباً بك في {$site}", $content, '', mail_logo_abs($conn));
    return sendMail($conn, $to_email, $to_name, "مرحباً بك في {$site}", $html);
}
