/* ฟังก์ชันใช้ร่วมกันของระบบคิว */
(function () {
  const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const BASE = meta('base').replace(/\/$/, '') + '/';
  const TH_DAYS = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
  const TH_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

  // ส่วนต่างเวลาเครื่องลูกข่ายกับเซิร์ฟเวอร์ (ms) — ปรับทุกครั้งที่เรียก API
  let skew = 0;
  const now = () => Date.now() + skew;

  async function api(path, data) {
    const opt = { headers: { 'Accept': 'application/json' }, cache: 'no-store' };
    if (data) {
      opt.method = 'POST';
      opt.headers['X-CSRF'] = meta('csrf');
      opt.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      opt.body = new URLSearchParams(data);
    }
    const res = await fetch(BASE + path, opt);
    let json;
    try { json = await res.json(); } catch { throw new Error('เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง'); }
    if (json.server_time) skew = json.server_time - Date.now();
    if (!res.ok || json.ok === false) {
      if (res.status === 401) { location.reload(); }
      throw new Error(json.error || 'เกิดข้อผิดพลาด');
    }
    return json;
  }

  function toast(msg, type = 'ok', ms = 3500) {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.setAttribute('role', 'status');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), ms);
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function startClock(timeEl, dateEl) {
    const tick = () => {
      const d = new Date(now());
      if (timeEl) timeEl.textContent = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
      if (dateEl) dateEl.textContent = `วัน${TH_DAYS[d.getDay()]}ที่ ${d.getDate()} ${TH_MONTHS[d.getMonth()]} ${d.getFullYear() + 543}`;
    };
    tick();
    setInterval(tick, 1000);
  }

  function fmtDuration(ms) {
    const neg = ms < 0;
    let s = Math.floor(Math.abs(ms) / 1000);
    const h = Math.floor(s / 3600); s %= 3600;
    const m = Math.floor(s / 60); s %= 60;
    return (neg ? '+' : '') + (h ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`);
  }

  let audioCtx;
  function beep(times = 2) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      for (let i = 0; i < times; i++) {
        const o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine'; o.frequency.value = i % 2 ? 660 : 880;
        o.connect(g); g.connect(audioCtx.destination);
        const t = audioCtx.currentTime + i * 0.35;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.4, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
        o.start(t); o.stop(t + 0.32);
      }
    } catch (e) { /* ไม่มีเสียงก็ไม่เป็นไร */ }
  }

  function notify(title, body) {
    if (navigator.vibrate) navigator.vibrate([300, 150, 300]);
    if ('Notification' in window && Notification.permission === 'granted') {
      try { new Notification(title, { body, tag: 'hpq', renotify: true }); } catch (e) { /* บาง browser ต้องใช้ service worker */ }
    }
  }

  // เดาเพศของเสียงไทยจากชื่อเสียง (Windows/Edge/Chrome/Apple)
  const MALE_VOICE = /pattara|niwat|male|ชาย/i;
  const FEMALE_VOICE = /premwadee|achara|kanya|narisa|google|female|หญิง/i;
  const voiceGender = (v) => !v ? null : (/female|หญิง/i.test(v.name) ? 'f' : MALE_VOICE.test(v.name) ? 'm' : FEMALE_VOICE.test(v.name) ? 'f' : null);

  /** เลือกเสียงไทยตามเพศที่ต้องการ ('m'/'f'/null) — คืน {voice, gender} ของเสียงที่ได้จริง */
  function pickVoice(want) {
    if (!('speechSynthesis' in window)) return { voice: null, gender: null };
    const th = speechSynthesis.getVoices().filter(v => v.lang && v.lang.toLowerCase().startsWith('th'));
    const voice = (want && th.find(v => voiceGender(v) === want)) || th[0] || null;
    return { voice, gender: voiceGender(voice) };
  }

  function speak(text, voice) {
    if (!('speechSynthesis' in window)) return;
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'th-TH'; u.rate = 0.9;
    const v = voice || pickVoice(null).voice;
    if (v) u.voice = v;
    speechSynthesis.speak(u);
  }

  /** อ่านหมายเลขคิวทีละตัว เช่น A012 -> "เอ ศูนย์ หนึ่ง สอง" */
  function spellQueue(code) {
    const digits = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    return code.split('').map(c => /\d/.test(c) ? digits[+c] : c).join(' ');
  }

  function poll(fn, ms) {
    let timer, stopped = false;
    const run = async () => {
      if (stopped) return;
      try { await fn(); } catch (e) { console.warn(e); }
      timer = setTimeout(run, document.hidden ? ms * 3 : ms);
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { clearTimeout(timer); run(); } });
    run();
    return () => { stopped = true; clearTimeout(timer); };
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // ปิด toast ที่มาจาก server
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toast').forEach(t => setTimeout(() => t.remove(), 4000));
    document.querySelectorAll('[data-confirm]').forEach(el => el.addEventListener('click', (e) => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    }));
  });

  window.HPQ = { api, toast, startClock, fmtDuration, beep, notify, speak, pickVoice, spellQueue, poll, esc, now, BASE };
})();
