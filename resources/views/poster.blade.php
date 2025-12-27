<!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<title>Landing Poster Generator</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui;max-width:1100px;margin:30px auto;padding:0 16px}
.card{border:1px solid #eee;border-radius:12px;padding:14px;margin-top:12px}
input,button,select,textarea{width:100%;padding:12px;font-size:14px;margin-top:10px}
button{cursor:pointer}
small{color:#666}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
.img{width:100%;border:1px solid #eee;border-radius:12px}
.err{background:#2a0f10;color:#ffd7d9;padding:10px;border-radius:10px;white-space:pre-wrap}
pre{background:#0b1020;color:#eee;padding:12px;border-radius:10px;white-space:pre-wrap}
.row{display:flex;gap:10px;flex-wrap:wrap}
.row > *{flex:1;min-width:220px}
.pickerRow{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.colorBox{display:flex;gap:8px;align-items:center}
.colorBox input[type="color"]{width:70px;height:42px;padding:0;border:1px solid #ddd;border-radius:10px}
.badge{display:inline-block;background:#f3f4f6;border:1px solid #e5e7eb;padding:6px 10px;border-radius:999px;font-size:12px;margin:6px 6px 0 0}
</style>
</head>
<body>

<h2>توليد بوستر لاند (1080×1920)</h2>
<small>ارفع صور المنتج + اكتب وصف → اختار اللهجة/الجمهور/ألوان الخلفية → Generate</small>

<div class="card">
  <label><b>صور المنتج (ارفع كذا صورة)</b></label>
  <input type="file" id="images" multiple accept="image/*">

  <label><b>وصف المنتج</b></label>
  <textarea id="description" rows="6" placeholder="اكتب وصف مختصر + أي تفاصيل (مقاس/خامة/مميزات)"></textarea>

  <div class="row">
    <div>
      <label><b>اللغة / اللهجة</b></label>
      <select id="dialect">
        <option value="egyptian_colloquial" selected>مصري عامي</option>
        <option value="msa">عربي فصحى</option>
        <option value="gulf">خليجي (خفيف)</option>
      </select>
      <small>هيتطبق على كل النص اللي جوه الصورة.</small>
    </div>
    <div>
      <label><b>بنخاطب مين؟</b></label>
      <select id="audience">
        <option value="general" selected>عام</option>
        <option value="women">بنات / سيدات</option>
        <option value="men">رجالة</option>
      </select>
      <small>بيغيّر أسلوب الكلام وزوايا الإقناع.</small>
    </div>
    <div>
      <label><b>عدد البوسترات</b></label>
      <select id="qty">
        <option>1</option><option>2</option><option selected>3</option><option>4</option><option>5</option><option>6</option>
      </select>
    </div>
  </div>

  <hr style="margin:14px 0;border:none;border-top:1px solid #eee">

  <label><b>ألوان خلفية البوستر (اختياري)</b></label>
  <small>اختار لون أو أكتر. لو سيبتها فاضية، النظام يختار ألوان متناسقة لوحده.</small>

  <div class="pickerRow">
    <div class="colorBox"><input type="color" id="c1" value="#0ea5e9"><small>لون 1</small></div>
    <div class="colorBox"><input type="color" id="c2" value="#a78bfa"><small>لون 2</small></div>
    <div class="colorBox"><input type="color" id="c3" value="#22c55e"><small>لون 3</small></div>
    <div class="colorBox"><input type="color" id="c4" value="#f97316"><small>لون 4</small></div>
  </div>

  <div class="row">
    <div>
      <label><b>شدة التدرّج (%)</b></label>
      <select id="gradStrength">
        <option value="15">15%</option>
        <option value="25" selected>25%</option>
        <option value="35">35%</option>
        <option value="50">50%</option>
        <option value="65">65%</option>
      </select>
      <small>كل ما تزيد النسبة التدرّج يبقى أوضح.</small>
    </div>
    <div>
      <label><b>اتجاه التدرّج</b></label>
      <select id="gradDir">
        <option value="top_to_bottom" selected>من فوق لتحت</option>
        <option value="left_to_right">من الشمال لليمين</option>
        <option value="diagonal">قطري</option>
        <option value="radial">دائري</option>
      </select>
    </div>
  </div>

  <div id="chosen" style="margin-top:8px"></div>

  <button type="button" onclick="gen()">Generate</button>
  <small id="status"></small>
  <div id="errorBox" class="err" style="display:none;margin-top:10px"></div>
</div>

<div class="card">
  <h3>المحتوى اللي اتستخدم داخل التصميم (للمراجعة)</h3>
  <pre id="brief"></pre>
</div>

<div class="card">
  <h3>النتائج</h3>
  <div class="grid" id="out"></div>
</div>

<script>
function getBgColors(){
  // لو المستخدم اختار ألوان افتراضيًا هنعتبرها مختارة طالما مختلفة
  const colors = [c1.value, c2.value, c3.value, c4.value]
    .map(v => (v||"").trim())
    .filter(Boolean);

  // شيل التكرار
  const uniq = [...new Set(colors.map(c => c.toLowerCase()))];
  return uniq;
}

function renderChosen(){
  const colors = getBgColors();
  chosen.innerHTML = "";
  if(colors.length){
    chosen.innerHTML = `<span class="badge">ألوان الخلفية: ${colors.join(" , ")}</span>
                        <span class="badge">شدة التدرّج: ${gradStrength.value}%</span>
                        <span class="badge">اتجاه: ${gradDir.options[gradDir.selectedIndex].text}</span>`;
  }else{
    chosen.innerHTML = `<span class="badge">ألوان الخلفية: Auto</span>`;
  }
}
[c1,c2,c3,c4,gradStrength,gradDir].forEach(el => el.addEventListener("input", renderChosen));
renderChosen();

async function gen(){
  errorBox.style.display="none";
  errorBox.textContent="";
  out.innerHTML="";
  brief.textContent="";
  status.textContent="";

  if(!images.files.length){ status.textContent="ارفع صور المنتج الأول"; return; }
  if(!description.value.trim()){ status.textContent="اكتب وصف المنتج"; return; }

  status.textContent="Generating...";

  const fd = new FormData();
  for(const f of images.files) fd.append("images[]", f);

  fd.append("description", description.value.trim());
  fd.append("qty", qty.value);
  fd.append("dialect", dialect.value);
  fd.append("audience", audience.value);

  const bg = getBgColors();
  fd.append("bg_colors", JSON.stringify(bg));
  fd.append("gradient_strength", gradStrength.value);
  fd.append("gradient_direction", gradDir.value);

  const res = await fetch("/poster/generate", {
    method:"POST",
    headers:{ "X-CSRF-TOKEN": "{{ csrf_token() }}" },
    body: fd
  });

  const data = await res.json().catch(()=> ({}));
  if(!res.ok || !data.success){
    status.textContent="Error ❌";
    errorBox.style.display="block";
    errorBox.textContent = data.message || ("HTTP "+res.status);
    return;
  }

  status.textContent="Done ✅";
  brief.textContent = data.brief || "";

  (data.images||[]).forEach((src)=>{
    const img = document.createElement("img");
    img.src = src;
    img.className = "img";
    out.appendChild(img);
  });
}
</script>

</body>
</html>
