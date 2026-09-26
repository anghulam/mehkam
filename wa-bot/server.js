/**
 * مِحكام — بوت واتساب مجاني للتنبيهات
 * ─────────────────────────────────────────────────────────────
 *  يعتمد Baileys (اتصال واتساب عبر مسح QR مثل واتساب ويب). مجاني بالكامل.
 *  يفتح خدمة HTTP صغيرة:
 *     GET  /            → حالة الاتصال
 *     GET  /qr          → صفحة فيها رمز QR للربط
 *     POST /send        → إرسال رسالة { secret, phone, text }
 *
 *  التشغيل:
 *     npm install
 *     WA_SECRET=مفتاح-سري-طويل  PORT=3000  npm start
 *     افتح http://SERVER_IP:3000/qr  وامسح الرمز من:
 *        واتساب ← الإعدادات ← الأجهزة المرتبطة ← ربط جهاز
 *
 *  ثم في مِحكام: لوحة الإدارة ← إعدادات المنصة ← تنبيهات واتساب ←
 *     المزوّد = «بوت خاص» ، الرابط = http://SERVER_IP:3000 ، المفتاح = WA_SECRET
 *
 *  ⚠️ استخدم رقم واتساب مخصّصاً للتنبيهات، وابقِ حجم الرسائل منخفضاً
 *     (تنبيهات فقط) لتفادي إيقاف الرقم من واتساب.
 * ─────────────────────────────────────────────────────────────
 */
'use strict';

const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
} = require('@whiskeysockets/baileys');
const P = require('pino');
const QR = require('qrcode');
const qrTerminal = require('qrcode-terminal');
const express = require('express');

const PORT = process.env.PORT || 3000;
const SECRET = process.env.WA_SECRET || 'change-this-secret';
const AUTH_DIR = process.env.AUTH_DIR || './auth';

let sock = null;
let lastQR = null;
let connected = false;
let starting = false;

async function start() {
  if (starting) return;
  starting = true;
  try {
    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version } = await fetchLatestBaileysVersion();
    sock = makeWASocket({
      version,
      auth: state,
      logger: P({ level: 'silent' }),
      printQRInTerminal: false,
      markOnlineOnConnect: false,
      browser: ['Mehkam Alerts', 'Chrome', '1.0'],
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (u) => {
      const { connection, lastDisconnect, qr } = u;
      if (qr) {
        lastQR = qr;
        console.log('\n── امسح رمز QR التالي من واتساب (الأجهزة المرتبطة) ──\n');
        qrTerminal.generate(qr, { small: true });
        console.log('\nأو افتح في المتصفح:  http://<عنوان-الخادم>:' + PORT + '/qr\n');
      }
      if (connection === 'open') {
        connected = true;
        lastQR = null;
        console.log('✅ واتساب متصل — البوت جاهز');
      }
      if (connection === 'close') {
        connected = false;
        starting = false;
        const code = lastDisconnect && lastDisconnect.error && lastDisconnect.error.output
          ? lastDisconnect.error.output.statusCode : 0;
        if (code === DisconnectReason.loggedOut) {
          console.log('⚠️ تم تسجيل الخروج. احذف مجلد auth وأعد تشغيل البوت لإعادة المسح.');
        } else {
          console.log('انقطع الاتصال — إعادة المحاولة خلال 3 ثوانٍ…');
          setTimeout(start, 3000);
        }
      }
    });
  } catch (e) {
    starting = false;
    console.error('فشل بدء البوت:', e);
    setTimeout(start, 5000);
  } finally {
    starting = false;
  }
}

/** توحيد الرقم إلى JID دولي */
function toJid(phone) {
  let p = String(phone || '').replace(/\D/g, '');
  if (p.startsWith('00')) p = p.slice(2);
  if (p.startsWith('0')) p = '966' + p.slice(1);
  if (p.length === 9) p = '966' + p;
  return p + '@s.whatsapp.net';
}

const app = express();
app.use(express.json({ limit: '256kb' }));

app.get('/', (_req, res) => res.json({ ok: true, connected }));

app.get('/qr', async (_req, res) => {
  res.set('Content-Type', 'text/html; charset=utf-8');
  if (connected) return res.send('<div style="font-family:sans-serif;text-align:center;margin-top:60px"><h2>✅ متصل</h2><p>البوت جاهز لإرسال التنبيهات.</p></div>');
  if (!lastQR) return res.send('<meta http-equiv="refresh" content="3"><div style="font-family:sans-serif;text-align:center;margin-top:60px"><h3>جارٍ توليد الرمز… ستُحدَّث الصفحة تلقائياً</h3></div>');
  try {
    const dataUrl = await QR.toDataURL(lastQR, { width: 320, margin: 1 });
    res.send(
      '<meta http-equiv="refresh" content="20">' +
      '<div style="font-family:sans-serif;text-align:center;margin-top:40px">' +
      '<h3>افتح واتساب ← الإعدادات ← الأجهزة المرتبطة ← ربط جهاز</h3>' +
      '<img src="' + dataUrl + '" alt="QR">' +
      '<p style="color:#888">الرمز يتغيّر كل ~20 ثانية</p></div>'
    );
  } catch (e) {
    res.status(500).send('خطأ في توليد الرمز');
  }
});

app.post('/send', async (req, res) => {
  const secret = (req.body && req.body.secret) || req.query.secret;
  if (secret !== SECRET) return res.status(403).json({ ok: false, error: 'forbidden' });
  if (!connected || !sock) return res.status(503).json({ ok: false, error: 'not connected' });
  const phone = req.body && req.body.phone;
  const text = req.body && req.body.text;
  if (!phone || !text) return res.status(400).json({ ok: false, error: 'phone and text required' });
  try {
    await sock.sendMessage(toJid(phone), { text: String(text) });
    return res.json({ ok: true });
  } catch (e) {
    return res.status(500).json({ ok: false, error: String(e && e.message ? e.message : e) });
  }
});

app.listen(PORT, () => console.log('بوت مِحكام واتساب يعمل على المنفذ ' + PORT));
start();
