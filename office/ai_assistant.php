<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
$page_title = 'المساعد الذكي القانوني';
$oid = (int)$_SESSION['office_id'];

// التحقق من ميزة الذكاء الاصطناعي
if (!hasFeature($conn, $oid, 'has_ai')) {
    header("Location: profile.php?tab=upgrade&feature=ai"); exit;
}

// Get recent cases for context
$cases_res = $conn->query("SELECT case_number, title, case_type, status FROM cases WHERE office_id=$oid AND status='active' ORDER BY id DESC LIMIT 10");
$cases_ctx = [];
while($c = $cases_res->fetch_assoc()) $cases_ctx[] = $c;

include '../includes/office_header.php';
?>

<style>
.ai-wrap{display:flex;gap:20px;height:calc(100vh - 180px);min-height:500px}
.ai-sidebar{width:260px;flex-shrink:0;display:flex;flex-direction:column;gap:12px}
.ai-main{flex:1;display:flex;flex-direction:column;background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);overflow:hidden}
.chat-header{background:linear-gradient(135deg,#0a1628,#1a3a6e);padding:16px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0}
.ai-avatar{width:40px;height:40px;border-radius:50%;background:rgba(201,162,39,.2);border:2px solid rgba(201,162,39,.4);display:flex;align-items:center;justify-content:center;font-size:18px;color:#c9a227;flex-shrink:0}
.chat-messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:16px;background:#f8fafc}
.msg{display:flex;gap:10px;max-width:85%}
.msg.user{align-self:flex-start;flex-direction:row-reverse}
.msg.ai{align-self:flex-start}
.msg-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;margin-top:2px}
.msg.ai .msg-avatar{background:linear-gradient(135deg,#0a1628,#1a3a6e);color:#c9a227}
.msg.user .msg-avatar{background:linear-gradient(135deg,#c9a227,#e8c040);color:#0a1628;font-weight:700}
.msg-bubble{padding:12px 16px;border-radius:14px;font-size:14px;line-height:1.7}
.msg.ai   .msg-bubble{background:#fff;border:1px solid #e8ecf0;border-radius:4px 14px 14px 14px;color:#1a202c}
.msg.user .msg-bubble{background:linear-gradient(135deg,#0a1628,#1a3a6e);color:#fff;border-radius:14px 4px 14px 14px}
.msg-time{font-size:10px;color:#9ca3af;margin-top:5px;text-align:center}
.chat-input-area{padding:16px;background:#fff;border-top:1px solid #f0f0f0;flex-shrink:0}
.chat-input-row{display:flex;gap:10px;align-items:flex-end}
.chat-textarea{flex:1;border:1.5px solid #e5e7eb;border-radius:12px;padding:12px 14px;font-size:14px;font-family:'Tajawal',sans-serif;resize:none;max-height:120px;min-height:48px;line-height:1.5;transition:border-color .2s}
.chat-textarea:focus{outline:none;border-color:#1a3a6e;box-shadow:0 0 0 3px rgba(26,58,110,.08)}
.btn-send{width:48px;height:48px;border-radius:12px;border:none;background:linear-gradient(135deg,#0a1628,#1a3a6e);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
.btn-send:hover{opacity:.9;transform:translateY(-1px)}
.btn-send:disabled{opacity:.4;cursor:not-allowed;transform:none}
.quick-chip{display:inline-flex;align-items:center;gap:6px;background:#f0f4ff;border:1px solid #dde5ff;color:#1a3a6e;font-size:12px;font-weight:600;padding:6px 12px;border-radius:50px;cursor:pointer;transition:all .2s}
.quick-chip:hover{background:#1a3a6e;color:#fff;border-color:#1a3a6e}
.sidebar-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);overflow:hidden}
.sidebar-card-header{padding:12px 16px;font-size:13px;font-weight:700;color:#fff;background:linear-gradient(135deg,#0a1628,#1a3a6e)}
.sidebar-card-body{padding:12px;max-height:180px;overflow-y:scroll;scrollbar-width:thin;scrollbar-color:#94a3b8 #f1f5f9}
.sidebar-card-body::-webkit-scrollbar{width:5px}
.sidebar-card-body::-webkit-scrollbar-track{background:#f1f5f9;border-radius:4px}
.sidebar-card-body::-webkit-scrollbar-thumb{background:#94a3b8;border-radius:4px}
.sidebar-card-body::-webkit-scrollbar-thumb:hover{background:#64748b}
.ctx-case{padding:8px 10px;border-radius:8px;font-size:12px;cursor:pointer;transition:background .2s;border:1px solid transparent;margin-bottom:4px}
.ctx-case:hover{background:#f0f4ff;border-color:#dde5ff}
.ctx-case .cn{font-weight:700;color:#0a1628}
.ctx-case .ct{color:#9ca3af;font-size:11px}
.typing-indicator{display:flex;align-items:center;gap:8px;padding:12px 16px;background:#fff;border:1px solid #e8ecf0;border-radius:4px 14px 14px 14px;font-size:13px;color:#9ca3af}
.typing-dots span{display:inline-block;width:7px;height:7px;border-radius:50%;background:#1a3a6e;animation:bounce .9s infinite}
.typing-dots span:nth-child(2){animation-delay:.2s}
.typing-dots span:nth-child(3){animation-delay:.4s}
@keyframes bounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}}
.ai-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#16a34a;font-size:10px;font-weight:700;padding:2px 8px;border-radius:50px}
@media(max-width:768px){.ai-sidebar{display:none}.ai-wrap{height:calc(100vh-160px)}}
</style>

<div class="ai-wrap">
  <!-- Sidebar -->
  <div class="ai-sidebar">
    <div class="sidebar-card">
      <div class="sidebar-card-header"><i class="fas fa-robot me-2"></i>المساعد الذكي</div>
      <div class="sidebar-card-body" style="overflow:visible;max-height:none">
        <div style="font-size:12px;color:#6b7280;line-height:1.7;margin-bottom:12px">
          مساعد قانوني ذكي متخصص في الأنظمة السعودية، يساعدك في صياغة المذكرات، البحث القانوني، وتحليل القضايا.
        </div>
        <div class="ai-badge"><i class="fas fa-circle" style="font-size:7px"></i>Gemini متاح الآن</div>
      </div>
    </div>

    <?php if(!empty($cases_ctx)): ?>
    <div class="sidebar-card">
      <div class="sidebar-card-header"><i class="fas fa-gavel me-2"></i>قضاياك النشطة</div>
      <div class="sidebar-card-body">
        <?php foreach($cases_ctx as $c): ?>
        <div class="ctx-case" onclick="insertCaseContext(<?=json_encode($c['case_number'],JSON_UNESCAPED_UNICODE)?>,<?=json_encode($c['title'],JSON_UNESCAPED_UNICODE)?>)">
          <div class="cn"><?=e($c['case_number'])?></div>
          <div class="ct"><?=e(mb_substr($c['title'],0,35))?>...</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="sidebar-card">
      <div class="sidebar-card-header"><i class="fas fa-lightbulb me-2"></i>اقتراحات</div>
      <div class="sidebar-card-body" style="display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:scroll;scrollbar-width:thin;scrollbar-color:#94a3b8 #f1f5f9">
        <?php
        $suggestions = [
          ['gavel','اشرح نظام العمل السعودي'],
          ['file-alt','اكتب مذكرة دفاعية'],
          ['balance-scale','ما هي أركان الدعوى التجارية؟'],
          ['users','حقوق العامل عند الفصل'],
          ['home','نزاعات عقود الإيجار'],
        ];
        foreach($suggestions as [$ic,$txt]):
        ?>
        <div class="quick-chip" onclick="sendQuick(<?=json_encode($txt,JSON_UNESCAPED_UNICODE)?>)">
          <i class="fas fa-<?=$ic?>"></i><?=$txt?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Main Chat -->
  <div class="ai-main">
    <div class="chat-header">
      <div class="ai-avatar"><i class="fas fa-robot"></i></div>
      <div style="flex:1">
        <div style="font-size:15px;font-weight:700;color:#fff">المساعد القانوني الذكي</div>
        <div style="font-size:11px;color:rgba(255,255,255,.55)">مدعوم بالذكاء الاصطناعي • متخصص في الأنظمة السعودية</div>
      </div>
      <div>
        <button onclick="clearChat()" class="btn btn-sm" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.2);border-radius:8px;font-size:12px;font-family:'Tajawal',sans-serif">
          <i class="fas fa-trash-alt me-1"></i>مسح
        </button>
      </div>
    </div>

    <div class="chat-messages" id="chatMessages">
      <!-- Welcome message -->
      <div class="msg ai">
        <div class="msg-avatar"><i class="fas fa-robot"></i></div>
        <div>
          <div class="msg-bubble">
            <strong>مرحباً <?=e($_SESSION['full_name']?:'') ?> 👋</strong><br><br>
            أنا مساعدك القانوني الذكي. يمكنني مساعدتك في:
            <ul style="margin:10px 0 0;padding-right:16px">
              <li>البحث في الأنظمة والقرارات السعودية</li>
              <li>صياغة المذكرات القانونية والعقود</li>
              <li>تحليل القضايا وتقديم الاستشارات</li>
              <li>شرح المفاهيم والمصطلحات القانونية</li>
              <li>الإجابة عن أسئلتك القانونية</li>
            </ul>
            <br>كيف يمكنني مساعدتك اليوم؟
          </div>
          <div class="msg-time"><?=date('H:i')?></div>
        </div>
      </div>
    </div>

    <div class="chat-input-area">
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px" id="quickChips">
        <span class="quick-chip" onclick="sendQuick('ما هي إجراءات رفع دعوى عمالية؟')"><i class="fas fa-briefcase"></i>دعوى عمالية</span>
        <span class="quick-chip" onclick="sendQuick('اشرح إجراءات التقاضي أمام المحكمة التجارية')"><i class="fas fa-store"></i>المحكمة التجارية</span>
        <span class="quick-chip" onclick="sendQuick('ما هي مدة الاستئناف في القضايا المدنية؟')"><i class="fas fa-clock"></i>مواعيد الاستئناف</span>
        <span class="quick-chip" onclick="sendQuick('صِغ لي بنود عقد استشارات قانونية')"><i class="fas fa-file-signature"></i>عقد استشارات</span>
      </div>
      <div class="chat-input-row">
        <textarea id="chatInput" class="chat-textarea" placeholder="اكتب سؤالك القانوني هنا..." rows="1"
                  onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
        <button class="btn-send" id="sendBtn" onclick="sendMessage()">
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
      <div style="font-size:11px;color:#9ca3af;margin-top:8px;text-align:center">
        <i class="fas fa-exclamation-circle me-1"></i>
        المساعد يقدم معلومات استرشادية وليست استشارة قانونية رسمية
      </div>
    </div>
  </div>
</div>

<script>
const ANTHROPIC_KEY = ''; // Set via server-side proxy - DO NOT expose here
let messages = []; // conversation history — role: 'user' | 'model'
let isLoading = false;

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function sendQuick(text) {
  document.getElementById('chatInput').value = text;
  sendMessage();
}

function insertCaseContext(num, title) {
  document.getElementById('chatInput').value = 'أحتاج مساعدة قانونية بخصوص القضية رقم ' + num + ': ' + title;
  document.getElementById('chatInput').focus();
}

function getTime() {
  return new Date().toLocaleTimeString('ar-SA', {hour:'2-digit', minute:'2-digit'});
}

function appendMsg(role, content) {
  var box  = document.getElementById('chatMessages');
  var div  = document.createElement('div');
  div.className = 'msg ' + role;
  var avatarHtml = role === 'ai'
    ? '<div class="msg-avatar"><i class="fas fa-robot"></i></div>'
    : '<div class="msg-avatar">' + (<?= json_encode(mb_substr($_SESSION['full_name']??'م',0,1)) ?>) + '</div>';
  div.innerHTML = avatarHtml +
    '<div><div class="msg-bubble" id="bubble-' + Date.now() + '">' + content + '</div>' +
    '<div class="msg-time">' + getTime() + '</div></div>';
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
  return div.querySelector('.msg-bubble');
}

function showTyping() {
  var box = document.getElementById('chatMessages');
  var div = document.createElement('div');
  div.className = 'msg ai'; div.id = 'typingIndicator';
  div.innerHTML = '<div class="msg-avatar"><i class="fas fa-robot"></i></div>' +
    '<div class="typing-indicator"><div class="typing-dots"><span></span><span></span><span></span></div>يكتب...</div>';
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

function removeTyping() {
  var el = document.getElementById('typingIndicator');
  if (el) el.remove();
}

function clearChat() {
  if (!confirm('مسح المحادثة؟')) return;
  var box = document.getElementById('chatMessages');
  while (box.children.length > 1) box.removeChild(box.lastChild);
  messages = [];
}

async function sendMessage() {
  if (isLoading) return;
  var input = document.getElementById('chatInput');
  var text  = input.value.trim();
  if (!text) return;

  input.value = ''; input.style.height = 'auto';
  appendMsg('user', text.replace(/\n/g,'<br>'));
  isLoading = true;
  document.getElementById('sendBtn').disabled = true;
  showTyping();

  messages.push({role:'user', content: text});

  // Build system prompt with office context
  var systemPrompt = `أنت مساعد قانوني ذكي متخصص في القانون السعودي والأنظمة المعمول بها في المملكة العربية السعودية.
تعمل لصالح مكتب محاماة وتساعد المحامين في عملهم اليومي.
خصائصك:
- تجيب دائماً باللغة العربية الفصحى بشكل واضح ومنظم
- تستشهد بالأنظمة والمراسيم السعودية ذات الصلة عند الإجابة
- تصيغ المذكرات القانونية والعقود بأسلوب قانوني رسمي
- تُنبّه دائماً بأن إجاباتك استرشادية وليست استشارة قانونية رسمية
- تستخدم الترقيم والعناوين لتنظيم الإجابات الطويلة
- تكون دقيقاً ومختصراً في نفس الوقت`;

  try {
    var response = await fetch('ai_proxy.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        system: systemPrompt,
        messages: messages
      })
    });
    var data = await response.json();
    removeTyping();
    if (data.error) {
      var isQuota = data.error.includes('نفدت') || data.error.includes('quota') || data.error.includes('Quota');
      var icon    = isQuota ? 'fa-clock' : 'fa-exclamation-circle';
      var color   = isQuota ? '#f59e0b' : '#ef4444';
      appendMsg('ai',
        '<span style="color:' + color + '"><i class="fas ' + icon + ' me-1"></i>' + data.error + '</span>' +
        (isQuota ? '<br><small style="color:#9ca3af">يمكنك الانتظار دقيقة وإعادة المحاولة، أو ترقية حساب Google AI Studio</small>' : '')
      );
    } else {
      var aiText = data.content;
      messages.push({role:'model', content: aiText});
      var formatted = aiText
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n\n/g,'<br><br>')
        .replace(/\n/g,'<br>');
      var modelLabel = data.model ? '<div style="font-size:10px;color:#9ca3af;margin-top:6px"><i class="fas fa-microchip me-1"></i>' + data.model + '</div>' : '';
      appendMsg('ai', formatted + modelLabel);
    }
  } catch(e) {
    removeTyping();
    appendMsg('ai', '<span style="color:#ef4444"><i class="fas fa-exclamation-circle me-1"></i>حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.</span>');
  }
  isLoading = false;
  document.getElementById('sendBtn').disabled = false;
  document.getElementById('chatInput').focus();
}
</script>

<?php include '../includes/office_footer.php'; ?>
