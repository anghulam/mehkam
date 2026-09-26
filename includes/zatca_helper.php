<?php
/**
 * zatca_helper.php
 * دوال التوافق مع متطلبات هيئة الزكاة والضريبة والجمارك
 * الفوترة الإلكترونية — المرحلة الأولى (التوليد)
 * VAT rate: 15% as per ZATCA regulations
 */

/** توليد UUID v4 — معرف فريد للفاتورة */
if (!function_exists('zatca_uuid')) {
    function zatca_uuid(): string {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}

/** تشفير TLV — الوحدة الأساسية للـ QR Code */
if (!function_exists('zatca_tlv')) {
    function zatca_tlv(int $tag, string $value): string {
        return chr($tag) . chr(strlen($value)) . $value;
    }
}

/**
 * توليد بيانات QR Code وفق مواصفات ZATCA (Base64 TLV)
 *  Tag 1: اسم البائع
 *  Tag 2: الرقم الضريبي للبائع (15 رقماً)
 *  Tag 3: طابع التوقيت (ISO 8601)
 *  Tag 4: إجمالي الفاتورة شامل الضريبة
 *  Tag 5: إجمالي ضريبة القيمة المضافة
 */
if (!function_exists('zatca_qr')) {
    function zatca_qr(
        string $seller_name,
        string $vat_number,
        float  $total_with_vat,
        float  $vat_amount,
        string $issue_datetime = ''
    ): string {
        if (!$issue_datetime) {
            $issue_datetime = date('Y-m-d\TH:i:s\Z');
        }
        $tlv  = zatca_tlv(1, $seller_name);
        $tlv .= zatca_tlv(2, $vat_number);
        $tlv .= zatca_tlv(3, $issue_datetime);
        $tlv .= zatca_tlv(4, number_format($total_with_vat, 2, '.', ''));
        $tlv .= zatca_tlv(5, number_format($vat_amount,     2, '.', ''));
        return base64_encode($tlv);
    }
}

/**
 * توليد رقم فاتورة تسلسلي للمكتب
 * صيغة: {PREFIX}-{YYYY}-{NNNN}  مثال: INV-2026-0001
 */
if (!function_exists('next_invoice_num')) {
    function next_invoice_num($conn, int $office_id, string $prefix = 'INV'): string {
        $year = (int)date('Y');
        $res  = $conn->query(
            "SELECT IFNULL(MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)),0)+1 n
             FROM invoices WHERE office_id=$office_id AND YEAR(created_at)=$year"
        );
        $n = $res ? (int)$res->fetch_assoc()['n'] : 1;
        return strtoupper($prefix) . '-' . $year . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
    }
}

/**
 * توليد رقم فاتورة اشتراك للمنصة
 * صيغة: SUB-{YYYY}-{NNNN}  مثال: SUB-2026-0001
 */
if (!function_exists('next_sub_invoice_num')) {
    function next_sub_invoice_num($conn): string {
        $year = (int)date('Y');
        $res  = $conn->query(
            "SELECT IFNULL(MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)),0)+1 n
             FROM subscription_invoices WHERE YEAR(created_at)=$year"
        );
        $n = $res ? (int)$res->fetch_assoc()['n'] : 1;
        return 'SUB-' . $year . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
    }
}

/**
 * تهيئة جداول ZATCA وإضافة الأعمدة المطلوبة (آمن للتشغيل المتعدد)
 */
if (!function_exists('zatca_migrate')) {
    function zatca_migrate($conn): void {
        // أعمدة ZATCA في جدول invoices
        $cols = [
            'uuid'          => "VARCHAR(36)  DEFAULT NULL",
            'invoice_type'  => "ENUM('simplified','standard') DEFAULT 'simplified'",
            'issue_date'    => "DATE         DEFAULT NULL",
            'supply_date'   => "DATE         DEFAULT NULL",
            'buyer_vat'     => "VARCHAR(20)  DEFAULT NULL",
            'seller_vat'    => "VARCHAR(20)  DEFAULT NULL",
            'qr_data'       => "TEXT         DEFAULT NULL",
            'zatca_status'  => "ENUM('draft','valid') DEFAULT 'draft'",
        ];
        foreach ($cols as $col => $def) {
            try { $conn->query("ALTER TABLE invoices ADD COLUMN $col $def"); } catch (\Exception $e) {}
        }

        // رقم السجل التجاري في إعدادات المكتب
        try { $conn->query("ALTER TABLE office_settings ADD COLUMN cr_number VARCHAR(20) DEFAULT NULL"); } catch (\Exception $e) {}

        // الرقم الضريبي للعملاء
        try { $conn->query("ALTER TABLE clients ADD COLUMN vat_number VARCHAR(20) DEFAULT NULL"); } catch (\Exception $e) {}
        try { $conn->query("ALTER TABLE clients ADD COLUMN cr_number  VARCHAR(20) DEFAULT NULL"); } catch (\Exception $e) {}

        // جدول فواتير الاشتراك (المنصة → المكاتب)
        $conn->query("CREATE TABLE IF NOT EXISTS subscription_invoices (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(30)  NOT NULL UNIQUE,
            uuid           VARCHAR(36)  NOT NULL,
            office_id      INT          NOT NULL,
            revenue_id     INT          DEFAULT NULL,
            invoice_type   ENUM('simplified','standard') DEFAULT 'standard',
            issue_date     DATE         NOT NULL,
            supply_date    DATE         NOT NULL,
            seller_name    VARCHAR(200) DEFAULT NULL,
            seller_vat     VARCHAR(20)  DEFAULT NULL,
            seller_cr      VARCHAR(20)  DEFAULT NULL,
            seller_address TEXT         DEFAULT NULL,
            buyer_name     VARCHAR(200) DEFAULT NULL,
            buyer_vat      VARCHAR(20)  DEFAULT NULL,
            buyer_cr       VARCHAR(20)  DEFAULT NULL,
            buyer_address  TEXT         DEFAULT NULL,
            package_name   VARCHAR(100) DEFAULT NULL,
            billing_period ENUM('monthly','yearly') DEFAULT 'monthly',
            subtotal       DECIMAL(10,2) DEFAULT 0,
            discount       DECIMAL(10,2) DEFAULT 0,
            tax_rate       DECIMAL(5,2)  DEFAULT 15.00,
            tax_amount     DECIMAL(10,2) DEFAULT 0,
            total          DECIMAL(10,2) DEFAULT 0,
            qr_data        TEXT         DEFAULT NULL,
            notes          TEXT         DEFAULT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (office_id), INDEX (revenue_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
