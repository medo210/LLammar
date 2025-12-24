<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Generator</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:980px;margin:30px auto;padding:0 16px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    input,select,button,textarea{padding:10px;font-size:14px}
    button{cursor:pointer}
    #thumbs img{height:90px;border:1px solid #ddd;border-radius:8px}
    #result{max-width:100%;border:1px solid #ddd;border-radius:12px;margin-top:10px;display:none}
    .box{border:1px solid #eee;border-radius:12px;padding:14px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    hr{margin:18px 0;border:none;border-top:1px solid #eee}
  </style>
</head>
<body>
  <h2>Landing Page Generator</h2>

  <div class="box">
    <div class="row">
      <input id="file" type="file" accept="image/*" multiple />
      <button id="uploadBtn" type="button">Upload</button>
      <span id="uploadStatus"></span>
    </div>

    <div id="thumbs" class="row" style="margin-top:12px"></div>

    <hr />

    <div class="row">
      <label>Language:</label>
      <select id="lang">
        <option>Arabic (MSA)</option>
        <option>Arabic (Egyptian Colloquial)</option>
        <option>English</option>
      </select>
    </div>

    <div style="margin-top:10px">
      <label>Features (optional):</label><br/>
      <textarea id="features" rows="3" style="width:100%;max-width:100%;">ضمان 30 يوم، شحن مجاني، عرض لفترة محدودة</textarea>
    </div>

    <hr />

    <div style="margin-top:10px">
      <label><b>Output Mode:</b></label>
      <div class="row" style="margin-top:8px">
        <label><input type="radio" name="mode" value="full" checked /> Full Page</label>
        <label><input type="radio" name="mode" value="sections" /> Sections</label>
      </div>
    </div>

    <div id="sectionsBox" style="margin-top:12px; display:none;">
      <label><b>Predefined Sections</b></label>
      <div class="grid2" style="margin-top:8px;">
        <label><input type="checkbox" class="sec" value="hero" checked /> Hero Section</label>
        <label><input type="checkbox" class="sec" value="before_after" checked /> Before/After Section</label>
        <label><input type="checkbox" class="sec" value="authority" checked /> Authority & Social Validation</label>
        <label><input type="checkbox" class="sec" value="ingredients" checked /> Ingredients/Mechanism</label>
        <label><input type="checkbox" class="sec" value="faq" /> FAQ Section</label>
        <label><input type="checkbox" class="sec" value="reviews" /> Customer Reviews</label>
      </div>

      <div style="margin-top:14px">
        <label><b>Custom Section</b></label><br/>
        <input id="customName" placeholder="Section Name (e.g., Pricing Section)" style="width:100%;max-width:100%;margin-top:6px" />
        <textarea id="customDesc" rows="2" placeholder="Description (Optional)" style="width:100%;max-width:100%;margin-top:6px"></textarea>
      </div>
    </div>

    <hr />

    <div class="row">
      <button id="genBtn" type="button">Generate</button>
      <span id="genStatus"></span>
    </div>

    <img id="result" alt="Generated output" />
  </div>

<script>
let uploadedPaths = [];

const fileEl = document.getElementById("file");
const uploadBtn = document.getElementById("uploadBtn");
const uploadStatus = document.getElementById("uploadStatus");
const thumbs = document.getElementById("thumbs");

const genBtn = document.getElementById("genBtn");
const genStatus = document.getElementById("genStatus");
const resultImg = document.getElementById("result");

const sectionsBox = document.getElementById("sectionsBox");
const modeEls = Array.from(document.querySelectorAll('input[name="mode"]'));

function refreshModeUI(){
  const mode = modeEls.find(x=>x.checked)?.value || "full";
  sectionsBox.style.display = (mode === "sections") ? "block" : "none";
}
modeEls.forEach(el => el.addEventListener("change", refreshModeUI));
refreshModeUI();

fileEl.addEventListener("change", () => {
  thumbs.innerHTML = "";
  const files = Array.from(fileEl.files || []);
  for (const f of files) {
    const img = document.createElement("img");
    img.src = URL.createObjectURL(f);
    thumbs.appendChild(img);
  }
});

uploadBtn.addEventListener("click", async (e) => {
  e.preventDefault();

  const files = Array.from(fileEl.files || []);
  if (files.length === 0) {
    uploadStatus.textContent = "اختار صور الأول.";
    return;
  }

  uploadBtn.disabled = true;
  uploadStatus.textContent = "Uploading...";

  const fd = new FormData();
  for (const f of files) fd.append("images[]", f);

  try {
    const res = await fetch("/api/upload", {
      method: "POST",
      headers: { "Accept": "application/json" },
      body: fd
    });

    const data = await res.json();
    if (!res.ok || !data.ok) {
      uploadStatus.textContent = "Upload error: " + (data.message || "unknown");
      return;
    }

    uploadedPaths = (data.items || []).map(x => x.path);
    uploadStatus.textContent = "Uploaded ✅ (" + uploadedPaths.length + " images)";
  } catch (err) {
    uploadStatus.textContent = "Upload failed: " + err;
  } finally {
    uploadBtn.disabled = false;
  }
});

genBtn.addEventListener("click", async (e) => {
  e.preventDefault();

  if (!uploadedPaths || uploadedPaths.length === 0) {
    genStatus.textContent = "ارفع صور الأول.";
    return;
  }

  const mode = modeEls.find(x=>x.checked)?.value || "full";
  const selected = Array.from(document.querySelectorAll(".sec:checked")).map(x => x.value);

  const payload = {
    paths: uploadedPaths,
    language: document.getElementById("lang").value,
    features: document.getElementById("features").value,
    mode: mode,
    sections: selected,
    custom_section: {
      name: document.getElementById("customName")?.value || "",
      description: document.getElementById("customDesc")?.value || ""
    }
  };

  genBtn.disabled = true;
  genStatus.textContent = "Generating... (may take up to ~2 minutes)";
  resultImg.style.display = "none";

  try {
    const res = await fetch("/api/generate-image", {
      method: "POST",
      headers: {"Accept":"application/json","Content-Type":"application/json"},
      body: JSON.stringify(payload)
    });

    const data = await res.json();
    if (!res.ok || !data.ok) {
      genStatus.textContent = "Generate error: " + (data.message || "unknown");
      return;
    }

    genStatus.innerHTML = 'Generated ✅ <a href="' + data.output_url + '" download>Download</a>';
    resultImg.src = data.output_url;
    resultImg.style.display = "block";
  } catch (err) {
    genStatus.textContent = "Generate failed: " + err;
  } finally {
    genBtn.disabled = false;
  }
});
</script>
</body>
</html>
