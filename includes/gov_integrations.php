<?php
/**
 * includes/gov_integrations.php
 * ════════════════════════════════════════════════════════════
 *  طبقة الربط مع الجهات الحكومية السعودية
 *
 *    • نفاذ الوطني الموحّد        Nafath   — التحقق من هوية الأفراد
 *    • وثيق / وزارة التجارة       Wathiq   — بيانات السجل التجاري / الرقم الموحّد
 *    • هيئة الزكاة — بوابة فاتورة  ZATCA    — إبلاغ/تصفية الفواتير (المرحلة الثانية)
 *    • اعتماد / وزارة المالية     Etimad   — المنافسات والمشتريات (اختياري)
 *
 *  ⚠️  كل جهة تتطلب اتفاقية ربط رسمية ومفاتيح API تُصدرها الجهة نفسها.
 *      تُدخل المفاتيح من: لوحة الإدارة ← إعدادات المنصة ← «التكامل الحكومي».
 *      بدون مفاتيح صالحة تُعيد الدوال حالة "غير مُفعّل" بهدوء دون تعطيل شيء.
 *
 *  مفاتيح الإعداد في جدول site_content:
 *    nafath_enabled  nafath_base_url  nafath_app_id  nafath_app_key
 *    wathiq_enabled  wathiq_base_url  wathiq_api_key
 *    zatca_enabled   zatca_env        zatca_base_url  zatca_binary_token  zatca_secret
 *    etimad_enabled  etimad_base_url  etimad_api_key
 * ════════════════════════════════════════════════════════════
 */

if (!function_exists('gov_get')):

/** قراءة مفتاح إعداد حكومي (مع تخزين مؤقت لكل الطلب) */
function gov_get($conn, $key, $default = '') {
    static $c = null;
    if ($c === null) {
        $c = [];
        $keys = "'" . implode("','", [
            'nafath_enabled','nafath_base_url','nafath_app_id','nafath_app_key',
            'wathiq_enabled','wathiq_base_url','wathiq_api_key',
            'zatca_enabled','zatca_env','zatca_base_url','zatca_binary_token','zatca_secret',
            'etimad_enabled','etimad_base_url','etimad_api_key',
        ]) . "'";
        try {
            $r = $conn->query("SELECT setting_key, setting_value FROM site_content WHERE setting_key IN ($keys)");
            if ($r) while ($row = $r->fetch_assoc()) $c[$row['setting_key']] = $row['setting_value'];
        } catch (\Throwable $e) {}
    }
    return isset($c[$key]) && $c[$key] !== '' ? $c[$key] : $default;
}

/** هل الخدمة مُفعّلة (checkbox في الإعدادات)؟ */
function gov_enabled($conn, $service) {
    return gov_get($conn, $service . '_enabled', '0') === '1';
}

/**
 * استدعاء HTTP عام.
 * يُعيد ['ok'=>bool, 'status'=>int, 'data'=>array|string|null, 'error'=>string]
 */
function gov_http($method, $url, array $opts = []) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'cURL غير متاح على الخادم'];
    }
    $headers = $opts['headers'] ?? [];
    $body    = $opts['json'] ?? null;
    $ch = curl_init($url);
    $set = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => $opts['timeout'] ?? 25,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ];
    if ($body !== null) {
        $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        $set[CURLOPT_POSTFIELDS] = $payload;
        $set[CURLOPT_HTTPHEADER] = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
    }
    curl_setopt_array($ch, $set);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return ['ok' => false, 'status' => $st, 'data' => null, 'error' => $err];
    $j = json_decode((string) $res, true);
    return [
        'ok'     => ($st >= 200 && $st < 300),
        'status' => $st,
        'data'   => ($j !== null ? $j : $res),
        'error'  => ($st >= 400 ? "HTTP $st" : ''),
    ];
}

/* ═══════════════════════ نفاذ الوطني الموحّد ═══════════════════════ */

function nafath_ready($conn) {
    return gov_enabled($conn, 'nafath')
        && gov_get($conn, 'nafath_base_url') !== ''
        && gov_get($conn, 'nafath_app_id')  !== '';
}

function nafath_headers($conn) {
    $h = ['APP-ID: ' . gov_get($conn, 'nafath_app_id')];
    if (gov_get($conn, 'nafath_app_key') !== '') $h[] = 'APP-KEY: ' . gov_get($conn, 'nafath_app_key');
    return $h;
}

/**
 * بدء طلب تحقق نفاذ — يصل إشعار لتطبيق «نفاذ» على جوال الشخص.
 * يُعيد ['ok'=>bool, 'trans_id'=>string, 'random'=>string, 'msg'=>string]
 */
function nafath_request($conn, $national_id) {
    $nid = preg_replace('/\D/', '', (string) $national_id);
    if (strlen($nid) !== 10) return ['ok' => false, 'msg' => 'رقم الهوية يجب أن يكون 10 أرقام'];
    if (!nafath_ready($conn)) {
        return ['ok' => false, 'msg' => 'خدمة «نفاذ» غير مُفعّلة — تتطلب اتفاقية ربط ومفاتيح من مركز المعلومات الوطني (NIC).'];
    }
    $base = rtrim(gov_get($conn, 'nafath_base_url'), '/');
    $r = gov_http('POST', $base . '/api/v1/mfa/request', [
        'headers' => nafath_headers($conn),
        'json'    => ['nationalId' => $nid, 'service' => 'Verification'],
    ]);
    if (!$r['ok']) return ['ok' => false, 'msg' => 'تعذّر الاتصال بنفاذ: ' . ($r['error'] ?: $r['status'])];
    $d = is_array($r['data']) ? $r['data'] : [];
    return [
        'ok'       => true,
        'trans_id' => $d['transId'] ?? ($d['transactionId'] ?? ''),
        'random'   => $d['random']  ?? '',
        'msg'      => 'تم إرسال الطلب — يرجى الموافقة من تطبيق نفاذ',
    ];
}

/** التحقق من حالة طلب نفاذ: WAITING | COMPLETED | REJECTED | EXPIRED */
function nafath_status($conn, $trans_id, $national_id) {
    if (!nafath_ready($conn)) return ['ok' => false, 'status' => 'disabled'];
    $base = rtrim(gov_get($conn, 'nafath_base_url'), '/');
    $r = gov_http('POST', $base . '/api/v1/mfa/status', [
        'headers' => nafath_headers($conn),
        'json'    => ['transId' => $trans_id, 'nationalId' => preg_replace('/\D/', '', (string) $national_id)],
    ]);
    $d = is_array($r['data']) ? $r['data'] : [];
    return ['ok' => $r['ok'], 'status' => strtoupper($d['status'] ?? 'UNKNOWN'), 'data' => $d];
}

/* ═══════════════════════ وثيق — السجل التجاري ═══════════════════════ */

function wathiq_ready($conn) {
    return gov_enabled($conn, 'wathiq')
        && gov_get($conn, 'wathiq_base_url') !== ''
        && gov_get($conn, 'wathiq_api_key')  !== '';
}

/**
 * جلب بيانات منشأة برقم السجل التجاري أو الرقم الموحّد (700...).
 * يُعيد مصفوفة موحّدة:
 *   ['ok'=>bool,'name'=>..,'status'=>..,'cr_number'=>..,'unified_number'=>..,
 *    'issue_date'=>..,'expiry_date'=>..,'city'=>..,'raw'=>array,'msg'=>string]
 */
function wathiq_lookup($conn, $number) {
    $num = preg_replace('/\D/', '', (string) $number);
    if ($num === '') return ['ok' => false, 'msg' => 'أدخل رقم السجل التجاري أو الرقم الموحّد'];
    if (!wathiq_ready($conn)) {
        return ['ok' => false, 'msg' => 'خدمة «وثيق» غير مُفعّلة — تتطلب اشتراك API من وزارة التجارة.'];
    }
    $base = rtrim(gov_get($conn, 'wathiq_base_url'), '/');
    $r = gov_http('GET', $base . '/api/v1/commercial-registration/' . rawurlencode($num), [
        'headers' => ['apiKey: ' . gov_get($conn, 'wathiq_api_key')],
    ]);
    if (!$r['ok']) return ['ok' => false, 'msg' => 'تعذّر جلب البيانات: ' . ($r['error'] ?: 'HTTP ' . $r['status'])];
    $d = is_array($r['data']) ? $r['data'] : [];
    // بعض الاستجابات تُغلّف البيانات داخل مفتاح
    if (isset($d['data']) && is_array($d['data'])) $d = $d['data'];
    return [
        'ok'             => true,
        'name'           => $d['crName']        ?? ($d['name']         ?? ''),
        'status'         => $d['crStatus']      ?? ($d['status']       ?? ''),
        'cr_number'      => $d['crNumber']      ?? $num,
        'unified_number' => $d['unifiedNumber'] ?? ($d['unifiedNationalNumber'] ?? ($d['entityNumber'] ?? '')),
        'issue_date'     => $d['issueDate']     ?? ($d['registrationDate'] ?? ''),
        'expiry_date'    => $d['expiryDate']    ?? ($d['cancellationDate'] ?? ''),
        'city'           => $d['city']          ?? ($d['location'] ?? ''),
        'raw'            => $d,
        'msg'            => 'تم جلب البيانات',
    ];
}

/* ═══════════════════ هيئة الزكاة — بوابة فاتورة ═══════════════════ */

function zatca_api_ready($conn) {
    return gov_enabled($conn, 'zatca')
        && gov_get($conn, 'zatca_base_url')     !== ''
        && gov_get($conn, 'zatca_binary_token') !== '';
}

function zatca_env($conn) {
    $e = gov_get($conn, 'zatca_env', 'sandbox');
    return in_array($e, ['sandbox', 'simulation', 'production'], true) ? $e : 'sandbox';
}

/**
 * إبلاغ فاتورة مبسّطة (Reporting) أو تصفية فاتورة ضريبية (Clearance).
 * ملاحظة: الإرسال الفعلي يتطلب توليد UBL 2.1 وتوقيعه رقمياً بشهادة CSID.
 * يُعيد ['ok'=>bool,'cleared'=>bool,'msg'=>string,'raw'=>mixed]
 */
function zatca_submit_invoice($conn, $invoice_row, $signed_xml = null) {
    if (!zatca_api_ready($conn)) {
        return ['ok' => false, 'msg' => 'ربط «بوابة فاتورة» غير مُفعّل — يتطلب Onboarding عبر بوابة فاتورة والحصول على شهادة (CSID).'];
    }
    if ($signed_xml === null) {
        return ['ok' => false, 'msg' => 'يجب توليد فاتورة UBL 2.1 موقّعة أولاً (غير مُنفّذ بعد في هذه النسخة).'];
    }
    $standard = ($invoice_row['invoice_type'] ?? 'simplified') === 'standard';
    $path = $standard ? '/invoices/clearance/single' : '/invoices/reporting/single';
    $base = rtrim(gov_get($conn, 'zatca_base_url'), '/');
    $r = gov_http('POST', $base . $path, [
        'headers' => [
            'Authorization: Basic ' . gov_get($conn, 'zatca_binary_token'),
            'Clearance-Status: 1',
            'Accept-Version: V2',
        ],
        'json' => [
            'invoiceHash' => hash('sha256', $signed_xml),
            'uuid'        => $invoice_row['uuid'] ?? '',
            'invoice'     => base64_encode($signed_xml),
        ],
    ]);
    return [
        'ok'      => $r['ok'],
        'cleared' => $r['ok'] && $standard,
        'msg'     => $r['ok'] ? 'تم الإرسال للهيئة' : ($r['error'] ?: 'HTTP ' . $r['status']),
        'raw'     => $r['data'],
    ];
}

/* ═══════════════════════ اعتماد — وزارة المالية ═══════════════════════ */

function etimad_ready($conn) {
    return gov_enabled($conn, 'etimad')
        && gov_get($conn, 'etimad_base_url') !== ''
        && gov_get($conn, 'etimad_api_key')  !== '';
}

function etimad_tenders($conn, array $params = []) {
    if (!etimad_ready($conn)) return ['ok' => false, 'msg' => 'ربط «اعتماد» غير مُفعّل.'];
    $base = rtrim(gov_get($conn, 'etimad_base_url'), '/');
    $r = gov_http('GET', $base . '/api/tenders?' . http_build_query($params), [
        'headers' => ['Authorization: Bearer ' . gov_get($conn, 'etimad_api_key')],
    ]);
    return ['ok' => $r['ok'], 'data' => $r['data'], 'msg' => $r['ok'] ? 'تم' : ($r['error'] ?: 'HTTP ' . $r['status'])];
}

/* ═══════════════════════ ملخّص الحالة ═══════════════════════ */

/** حالة كل خدمة — تُستخدم في لوحة الإدارة */
function gov_status_all($conn) {
    return [
        'nafath' => ['label' => 'نفاذ الوطني الموحّد',        'enabled' => gov_enabled($conn, 'nafath'), 'ready' => nafath_ready($conn)],
        'wathiq' => ['label' => 'وثيق — السجل التجاري',        'enabled' => gov_enabled($conn, 'wathiq'), 'ready' => wathiq_ready($conn)],
        'zatca'  => ['label' => 'هيئة الزكاة — بوابة فاتورة',  'enabled' => gov_enabled($conn, 'zatca'),  'ready' => zatca_api_ready($conn)],
        'etimad' => ['label' => 'اعتماد — وزارة المالية',      'enabled' => gov_enabled($conn, 'etimad'), 'ready' => etimad_ready($conn)],
    ];
}

endif;
