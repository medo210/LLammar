<!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<title>Landing Sections Composer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui;max-width:1100px;margin:30px auto;padding:0 16px}
.card{border:1px solid #eee;border-radius:12px;padding:14px;margin-top:12px}
input,button{width:100%;padding:12px;font-size:14px;margin-top:10px}
button{cursor:pointer}
small{color:#666}
.page{max-width:520px;border-radius:14px;border:1px solid #eee;display:block;margin:10px 0}
.err{background:#2a0f10;color:#ffd7d9;padding:10px;border-radius:10px;white-space:pre-wrap}
</style>
</head>
<body>

<h2>ترتيب صور متناسق للاند بيدج (1080×1920)</h2>
<small>الصور هتطلع تحت بعض Full-Width، وبينهم مسافة فاضية لزر الشراء. مفيش خلفية من عندنا.</small>

<div class="card">
  <h3>ارفع صور المنتج</h3>
  <input type="file" id="images" multiple accept="image/*">
  <button type="button" onclick="generate()">Generate</button>
  <small id="status"></small>
  <div id="errorBox" class="err" style="display:none;margin-top:10px"></div>
</div>

<div class="card">
  <h3>النتيجة</h3>
  <div id="out"></div>
</div>

<script>
async function generate(){
  errorBox.style.display="none";
  errorBox.textContent="";
  out.innerHTML="";

  const files = images.files;
  if(!files.length){ status.textContent="اختار صور الأول"; return; }

  status.textContent="Generating...";

  const fd = new FormData();
  for(const f of files) fd.append("images[]", f);

  try{
    const res = await fetch("/collage/generate", {
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

    status.textContent = `Done ✅ (Pages: ${data.total_pages})`;

    (data.pages||[]).forEach((src,i)=>{
      const img = document.createElement("img");
      img.src = src;
      img.className="page";
      img.title="Page "+(i+1);
      out.appendChild(img);
    });

  }catch(e){
    status.textContent="Error ❌";
    errorBox.style.display="block";
    errorBox.textContent = String(e);
  }
}
</script>

</body>
</html>
