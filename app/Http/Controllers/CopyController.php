<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CopyController extends Controller
{
    public function uploadImages(Request $request)
    {
        $request->validate([
            'images'   => 'required|array|min:1',
            'images.*' => 'required|image|max:5120',
        ]);

        $imageParts = [];
        $previews = [];

        foreach ($request->file('images') as $img) {
            $mime = $img->getMimeType();
            $b64  = base64_encode(file_get_contents($img->getRealPath()));

            $imageParts[] = [
                "inlineData" => [
                    "mimeType" => $mime,
                    "data" => $b64
                ]
            ];

            $previews[] = "data:$mime;base64,$b64";
        }

        session(['copy_product_image_parts' => $imageParts]);

        return response()->json([
            "success" => true,
            "previews" => $previews
        ]);
    }

    private function geminiCall(string $modelId, array $payload)
    {
        $apiKey = env("GEMINI_API_KEY");
        if (!$apiKey) return [false, "Missing GEMINI_API_KEY", null];

        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "x-goog-api-key: {$apiKey}"],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 240,
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return [false, "cURL error: {$err}", null];

        $json = json_decode($resp, true);

        if ($http < 200 || $http >= 300) {
            return [false, "Gemini error HTTP {$http}", $json ?: $resp];
        }

        return [true, $resp, $json];
    }

    private function extractText($json): string
    {
        $parts = $json["candidates"][0]["content"]["parts"] ?? [];
        if (!is_array($parts)) return "";

        $t = "";
        foreach ($parts as $p) {
            if (isset($p["text"])) $t .= $p["text"];
        }
        return trim($t);
    }

    private function extractImageDataUrl($json): ?string
    {
        $parts = $json["candidates"][0]["content"]["parts"] ?? [];
        if (!is_array($parts)) return null;

        foreach ($parts as $p) {
            if (isset($p["inlineData"]["data"])) {
                $mime = $p["inlineData"]["mimeType"] ?? "image/png";
                return "data:{$mime};base64," . $p["inlineData"]["data"];
            }
        }
        return null;
    }

    private function geminiTextOnly(string $prompt, string $modelId = 'gemini-2.0-flash')
    {
        $payload = [
            "contents" => [[ "parts" => [ ["text" => $prompt] ] ]],
            "generationConfig" => [
                "temperature" => 0.85,
                "maxOutputTokens" => 4096
            ]
        ];

        [$ok, $raw, $json] = $this->geminiCall($modelId, $payload);
        if (!$ok) return [false, (string)$raw];

        $text = $this->extractText($json);
        if (!$text) $text = (string)$raw;

        return [true, $text];
    }

    private function geminiTextWithRefs(string $prompt, array $imageParts, string $modelId = 'gemini-2.0-flash')
    {
        $parts = array_merge([["text" => $prompt]], $imageParts);

        $payload = [
            "contents" => [[ "parts" => $parts ]],
            "generationConfig" => [
                "temperature" => 0.75,
                "maxOutputTokens" => 4096
            ]
        ];

        [$ok, $raw, $json] = $this->geminiCall($modelId, $payload);
        if (!$ok) return [false, (string)$raw];

        $text = $this->extractText($json);
        if (!$text) $text = (string)$raw;

        return [true, $text];
    }

    private function geminiImageWithRefs(string $imagePrompt, array $imageParts, string $modelId = 'gemini-3-pro-image-preview')
    {
        $parts = array_merge([["text" => $imagePrompt]], $imageParts);

        $payload = [
            "contents" => [[ "parts" => $parts ]],
            "generationConfig" => [
                "responseModalities" => ["IMAGE"]
            ]
        ];

        [$ok, $raw, $json] = $this->geminiCall($modelId, $payload);
        if (!$ok) return [false, (string)$raw];

        $img = $this->extractImageDataUrl($json);
        if (!$img) {
            $txt = $this->extractText($json);
            return [false, "No image returned. Got:\n" . $txt];
        }

        return [true, $img];
    }

    private function concepts(int $qty): array
    {
        $all = [
            // “فكرة” + “زاوية” + “مكان النص” + “طول النص”
            ["زاوية" => "تخفيف تعب الرقبة/الظهر في المشاوير الطويلة", "تصميم" => "داخل عربية واقعي + تركيز على الوسادة", "النص" => "قصير أعلى", "نبرة" => "مريح"],
            ["زاوية" => "شكل شيك وترقية صالون العربية", "تصميم" => "ستايل بريميوم + خامة واضحة", "النص" => "قصير أسفل", "نبرة" => "فاخر"],
            ["زاوية" => "عرض/قيمة مقابل السعر", "تصميم" => "منتج + عناصر تسعير/باچ بسيط", "النص" => "متوسط يمين", "نبرة" => "عرض"],
            ["زاوية" => "للسواقين الشغل/التوصيل", "تصميم" => "مظهر عملي + يوم طويل", "النص" => "قصير يسار", "نبرة" => "عملي"],
            ["زاوية" => "هدية مفيدة لأي حد بيسوق", "تصميم" => "لمسة هدية/مناسبات بدون مبالغة", "النص" => "قصير أعلى", "نبرة" => "ودود"],
            ["زاوية" => "دعم ثابت ووضعية أحسن", "تصميم" => "إحساس دعم (بدون ادعاءات طبية)", "النص" => "متوسط أسفل", "نبرة" => "مطمّن"],
        ];

        return array_slice($all, 0, max(1, min(6, $qty)));
    }

    public function generate(Request $request)
    {
        $request->validate([
            'task' => 'required|string|in:ad_copy,landing_copy,images',
            'qty' => 'required|integer|min:1|max:6',
            'description' => 'required|string',
        ]);

        $imageParts = session('copy_product_image_parts', []);
        if (!$imageParts) {
            return response()->json(["success"=>false,"message"=>"ارفع صور المنتج الأول (Upload Images)"], 422);
        }

        $task = $request->input('task');
        $qty  = (int)$request->input('qty');
        $desc = trim($request->input('description'));

        // ===== 1) فهم المنتج من الصور + الوصف (Enrichment) =====
        // ملاحظة: ده بديل “البحث على النت” من غير API خارجي: بنطلع مواصفات/فوائد/اعتراضات إضافية من الصور
        $understandPrompt = <<<AR
حلّل صور المنتج مع الوصف التالي واستخرج معلومات تساعد في التسويق.

ممنوع تمامًا استخدام أي كلمات إنجليزية في الناتج.

المطلوب:
- اسم/نوع المنتج المتوقع
- خامات/تفاصيل واضحة من الصور (لو موجودة)
- 10 فوائد عملية (محددة، واقعية)
- 8 مميزات/مواصفات (مقاسات/ملمس/تركيب/استخدام)
- 6 اعتراضات متوقعة من العميل + ردود قصيرة عليها
- 6 استخدامات/مواقف (سيناريوهات)
- 10 كلمات/عبارات بيع مصرية قوية (بدون إنجليزي)
- تحذير: ممنوع ادعاءات طبية/شفاء مضمون

الوصف:
{$desc}
AR;

        [$okU, $insights] = $this->geminiTextWithRefs($understandPrompt, $imageParts, 'gemini-2.0-flash');
        if (!$okU) {
            return response()->json(["success"=>false,"message"=>"فشل فهم المنتج من الصور"], 500);
        }

        $copyText = "";
        $images = [];

        // ===== 2) Ad Copy (عربي فقط + جاهز كوبي بيست + NLP) =====
        if ($task === 'ad_copy') {
            $prompt = <<<AR
أنت كاتب إعلانات محترف وبتستخدم تقنيات الإقناع وNLP (بدون ذكر كلمة NLP).

ممنوع تمامًا استخدام أي كلمات إنجليزية أو عناوين إنجليزية.

اكتب بالعامية المصرية وبشكل جاهز للنسخ واللصق في إعلانات فيسبوك.

اطلع لي عدد {$qty} إعلانات كاملين، كل إعلان بالشكل ده بالضبط:

إعلان رقم #
النص الأساسي:
العنوان:
الوصف:
زر الدعوة لاتخاذ إجراء:

شروط مهمة:
- كل إعلان لازم يكون "زاوية" مختلفة (وجع/راحة – شكل شيك – قيمة – عرض – هدية – سواقين شغل… إلخ)
- خلي الكلام طبيعي ومقنع، فقرات قصيرة، فوائد قبل المميزات، معالجة اعتراض واحد على الأقل
- ممنوع ادعاءات طبية أو شفاء مضمون
- خلي الدعوة لاتخاذ إجراء واضحة

معلومات إضافية مستخرجة من الصور (استخدمها لتحسين النتيجة):
{$insights}

الوصف الأصلي:
{$desc}
AR;

            [$ok, $text] = $this->geminiTextOnly($prompt);
            if (!$ok) return response()->json(["success"=>false,"message"=>$text], 500);
            $copyText = $text;
        }

        // ===== 3) Landing Page Copy (عربي فقط بدون كلمات إنجليزية) =====
        if ($task === 'landing_copy') {
            $prompt = <<<AR
أنت كاتب صفحات هبوط محترف.

ممنوع تمامًا استخدام أي كلمات إنجليزية (مثل: هيرو، هيدلاين، سي تي اي… إلخ). اكتب عربي فقط.

اكتب صفحة هبوط كاملة بالعامية المصرية، مقسمة بعناوين عربية واضحة فقط، بالشكل التالي:

- العنوان الرئيسي
- جملة توضيح قصيرة
- نقاط سريعة (3-6 نقاط)
- المشكلة (فقرة قصيرة)
- ليه ده بيحصل (فقرة قصيرة)
- الحل (فقرة قصيرة)
- أهم الفوائد (نقاط)
- أهم المميزات/المواصفات (نقاط)
- إزاي تستخدمه (خطوات)
- آراء عملاء (3 آراء قصيرة واقعية)
- العرض (لو مفيش سعر اختار عرض منطقي بدون مبالغة)
- ضمان/اطمئنان (بدون وعود مستحيلة)
- أسئلة شائعة (6 أسئلة)
- دعوة واضحة لاتخاذ إجراء

معلومات إضافية مستخرجة من الصور (استخدمها لتحسين النتيجة):
{$insights}

الوصف الأصلي:
{$desc}
AR;

            [$ok, $text] = $this->geminiTextOnly($prompt);
            if (!$ok) return response()->json(["success"=>false,"message"=>$text], 500);
            $copyText = $text;
        }

        // ===== 4) Images (1080x1080) Variants قوية + نصوص مختلفة + أماكن مختلفة =====
        if ($task === 'images') {
            $concepts = $this->concepts($qty);

            // نطلع "أفكار كتابة قصيرة" من insights عشان تكون متنوعة
            $overlayPrompt = <<<AR
من المعلومات التالية، اطلع 12 جملة قصيرة جدًا تصلح ككتابة على صورة إعلان (من 2 إلى 5 كلمات فقط).
ممنوع أي إنجليزي.
الجمل تكون متنوعة: قصيرة/متوسطة، عرض/راحة/شكل/قيمة.
اكتبهم كل جملة في سطر لوحدها.

المعلومات:
{$insights}
AR;

            [$okO, $overlayList] = $this->geminiTextOnly($overlayPrompt);
            $overlays = [];
            if ($okO) {
                foreach (preg_split("/\r\n|\n|\r/", $overlayList) as $line) {
                    $line = trim($line);
                    if ($line !== "") $overlays[] = $line;
                }
            }
            if (count($overlays) < 3) {
                $overlays = ["راحة في السواقة", "فرق في المشوار", "دعم وراحة", "شكل شيك", "عرض لفترة محدودة", "راحة طول اليوم"];
            }

            foreach ($concepts as $i => $c) {
                $overlay = $overlays[$i % count($overlays)];

                $where = $c["النص"]; // قصير أعلى/أسفل/يمين/يسار/متوسط
                $angle = $c["زاوية"];
                $layout = $c["تصميم"];

                $imagePrompt = <<<AR
استخدم نفس المنتج الموجود بالضبط في الصور المرجعية.
ممنوع تغيير شكل المنتج أو لونه أو شعاره أو تصميمه.
ممنوع اختراع منتج جديد.

اعمل صورة إعلان قوية لفيسبوك (تبيع فعلاً):
- مقاس مربع 1:1 الهدف 1080×1080
- صورة واقعية ستايل تجارة إلكترونية
- تباين قوي وترتيب بصري واضح
- عناصر التصميم تختلف عن باقي النسخ (اختلاف حقيقي في الفكرة والزاوية)

الزاوية:
{$angle}

الستايل/الترتيب:
{$layout}

اكتب على الصورة جملة قصيرة وواضحة بالعربي فقط:
"{$overlay}"

مكان النص:
{$where}
(يعني: لو "قصير أعلى" يبقى أعلى، لو "قصير أسفل" يبقى أسفل… وهكذا)

خلي الخط مقروء جدًا، وخلّي المنتج واضح ومركزي.
AR;

                [$okImg, $imgOrErr] = $this->geminiImageWithRefs($imagePrompt, $imageParts, 'gemini-3-pro-image-preview');
                if ($okImg) $images[] = $imgOrErr;
            }
        }

        return response()->json([
            "success" => true,
            "copy_text" => $copyText,
            "images" => $images
        ]);
    }
}
