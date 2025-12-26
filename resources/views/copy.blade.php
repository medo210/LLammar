<!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<title>Copy / Images Generator</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui;max-width:1200px;margin:30px auto;padding:0 16px}
textarea,input,select,button{width:100%;padding:12px;font-size:14px;margin-top:10px}
button{cursor:pointer}
.card{border:1px solid #eee;border-radius:12px;padding:14px;margin-top:12px}
pre{background:#0b1020;color:#eee;padding:15px;border-radius:10px;white-space:pre-wrap;overflow:auto;min-height:220px}
.row{display:flex;gap:10px;flex-wrap:wrap}
.row > *{flex:1;min-width:220px}
.thumb{width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #ddd}
.gridImg{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
@media(max-width:1000px){.gridImg{grid-template-columns:repeat(2,1fr)}}
@media(max-width:650px){.gridImg{grid-template-columns:1fr}}
.outImg{width:100%;border-radius:12px;border:1px solid #eee}
small{color:#666}
.hidden{display:none}
</style>
</head>
<body>

<h2>Copy / Images Generator</h2>
<small>Upload صور المنتج الأول → بعدين اختار تعمل Copy أو Images + عدد النتائج</small>

<div class="card">
  <h3>1) Upload صور المنتج (ممكن كذا صورة)</h3>
  <input type="file" id="images" multiple accept="image/*">
  <button type="button" onclick="uploadImages()">Upload Images</button>
  <div id="thumbs" class="row" style="margin-top:10px"></div>
  <small id="uploadStatus"></small>
</div>

<div class="card">
  <h3>2) المطلوب</h3>

  <div class="row">
    <select id="task" onchange="syncUI()">
      <option value="ad_copy" selected>عمل كوبي للإعلان (3 إعلانات كاملة)</option>
      <option value="landing_copy">كوبي للاند بيدج</option>
      <option value="images">عمل صور إعلانية</option>
    </select>

    <select id="qty">
      <option value="1">1</option>
      <option value="2">2</option>
      <option value="3" selected>3</option>
      <option value="4">4</option>
      <option value="5">5</option>
      <option value="6">6</option>
    </select>
  </div>

  <textarea id="description" rows="6" placeholder="وصف بسيط للمنتج + خامات/مقاسات/سعر/عرض لو موجود"></textarea>

  <button type="button" onclick="generate()">Generate</button>
  <small id="genStatus"></small>
</div>

<div class="card" id="copyCard">
  <h3 id="copyTitle">النتيجة</h3>
  <pre id="copyOut"></pre>
</div>

<div class="card hidden" id="imgCard">
  <h3>صور إعلانية 1080×1080 (Variations)</h3>
  <small>كل صورة بزاوية/فكرة مختلفة لاختبار A/B</small>
  <div class="gridImg" id="imgGrid" style="margin-top:10px"></div>
</div>

<script>
let uploaded = false;

function syncUI(){
  const isImages = task.value === "images";
  imgCard.classList.toggle("hidden", !isImages);
  copyCard.classList.toggle("hidden", isImages);
  copyTitle.textContent = task.value === "ad_copy" ? "Ad Copy (جاهز كوبي بيست)" :
                          task.value === "landing_copy" ? "Landing Page Copy" :
                          "—";
}
syncUI();

function uploadImages(){
  const files = images.files;
  if(!files.length){
    uploadStatus.textContent = "اختار صور الأول";
    return;
  }
  const fd = new FormData();
  for(const f of files) fd.append("images[]", f);

  uploadStatus.textContent = "Uploading...";
  thumbs.innerHTML = "";

  fetch("/copy/upload-images", {
    method: "POST",
    headers: {"X-CSRF-TOKEN": "{{ csrf_token() }}"},
    body: fd
  })
  .then(r=>r.json())
  .then(d=>{
    if(!d.success){
      uploadStatus.textContent = d.message || "Upload Error";
      uploaded = false;
      return;
    }
    uploaded = true;
    uploadStatus.textContent = "تم رفع الصور ✅";
    d.previews.forEach(src=>{
      const im = document.createElement("img");
      im.src = src;
      im.className="thumb";
      thumbs.appendChild(im);
    });
  })
  .catch(()=>{ uploadStatus.textContent="Upload Error"; uploaded=false; });
}

function generate(){
  if(!uploaded){
    genStatus.textContent = "ارفع الصور الأول";
    return;
  }

  genStatus.textContent = "Generating...";
  copyOut.textContent = "";
  imgGrid.innerHTML = "";

  fetch("/copy/generate", {
    method:"POST",
    headers:{
      "Content-Type":"application/json",
      "X-CSRF-TOKEN":"{{ csrf_token() }}"
    },
    body: JSON.stringify({
      task: task.value,
      qty: parseInt(qty.value,10),
      description: description.value
    })
  })
  .then(r=>r.json())
  .then(d=>{
    if(!d.success){
      genStatus.textContent = d.message || "Error";
      return;
    }
    genStatus.textContent = "Done ✅";

    if(d.copy_text){
      copyOut.textContent = d.copy_text;
    }

    if(d.images && d.images.length){
      imgCard.classList.remove("hidden");
      d.images.forEach(src=>{
        const img = document.createElement("img");
        img.src = src;
        img.className="outImg";
        imgGrid.appendChild(img);
      });
    }
  })
  .catch(()=>{ genStatus.textContent="Error"; });
}
</script>

</body>
</html>
