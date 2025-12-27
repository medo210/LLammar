<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PosterController extends Controller
{
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
        if ($http < 200 || $http >= 300) return [false, "Gemini error HTTP {$http}", $json ?: $resp];

        return [true, $resp, $json];
    }

    private function extractText($json): string
    {
        $parts = $json["candidates"][0]["content"]["parts"] ?? [];
        $t = "";
        foreach ($parts as $p) if (isset($p["text"])) $t .= $p["text"];
        return trim($t);
    }

    private function extractImageDataUrl($json): ?string
    {
        $parts = $json["candidates"][0]["content"]["parts"] ?? [];
        foreach ($parts as $p) {
            if (isset($p["inlineData"]["data"])) {
                $mime = $p["inlineData"]["mimeType"] ?? "image/png";
                return "data:{$mime};base64,".$p["inlineData"]["data"];
            }
        }
        return null;
    }

    private function toInlineParts(array $files): array
    {
        $parts = [];
        foreach ($files as $img) {
            $mime = $img->getMimeType();
            $b64  = base64_encode(file_get_contents($img->getRealPath()));
            $parts[] = ["inlineData" => ["mimeType"=>$mime, "data"=>$b64]];
        }
        return $parts;
    }

    private function geminiText(string $prompt, string $modelId='gemini-2.0-flash')
    {
        $payload = [
            "contents" => [[ "parts" => [ ["text"=>$prompt] ] ]],
            "generationConfig" => ["temperature"=>0.9, "maxOutputTokens"=>2048]
        ];
        [$ok, $raw, $json] = $this->geminiCall($modelId, $payload);
        if (!$ok) return [false, (string)$raw];
        $txt = $this->extractText($json);
        return [true, $txt ?: (string)$raw];
    }

    private function geminiImage(string $prompt, array $imageParts, string $modelId='gemini-3-pro-image-preview')
    {
        $payload = [
            "contents" => [[ "parts" => array_merge([["text"=>$prompt]], $imageParts) ]],
            "generationConfig" => [
                "responseModalities" => ["IMAGE"],
            ]
        ];

        [$ok, $raw, $json] = $this->geminiCall($modelId, $payload);
        if (!$ok) return [false, (string)$raw];

        $img = $this->extractImageDataUrl($json);
        if (!$img) {
            $txt = $this->extractText($json);
            return [false, "No image returned. ".$txt];
        }
        return [true, $img];
    }

    // ✅ enforce exact 1080x1920 output
    private function enforceSize1080x1920(string $dataUrl): string
    {
        if (!extension_loaded('gd')) return $dataUrl;

        if (!preg_match('/^data:(.*?);base64,(.*)$/', $dataUrl, $m)) return $dataUrl;
        $bin = base64_decode($m[2]);
        if (!$bin) return $dataUrl;

        $src = @imagecreatefromstring($bin);
        if (!$src) return $dataUrl;

        $tw = 1080; $th = 1920;
        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0) { imagedestroy($src); return $dataUrl; }

        $scale = max($tw / $sw, $th / $sh);
        $rw = (int)ceil($sw * $scale);
        $rh = (int)ceil($sh * $scale);

        $resized = imagecreatetruecolor($rw, $rh);
        imagealphablending($resized, true);
        imagesavealpha($resized, true);
        $white = imagecolorallocate($resized, 255,255,255);
        imagefill($resized, 0,0, $white);
        imagecopyresampled($resized, $src, 0,0, 0,0, $rw,$rh, $sw,$sh);

        $cropX = (int)max(0, floor(($rw - $tw)/2));
        $cropY = (int)max(0, floor(($rh - $th)/2));

        $out = imagecreatetruecolor($tw, $th);
        imagealphablending($out, true);
        imagesavealpha($out, true);
        $white2 = imagecolorallocate($out, 255,255,255);
        imagefill($out, 0,0, $white2);
        imagecopy($out, $resized, 0,0, $cropX,$cropY, $tw,$th);

        ob_start();
        imagepng($out, null, 8);
        $png = ob_get_clean();

        imagedestroy($src);
        imagedestroy($resized);
        imagedestroy($out);

        return "data:image/png;base64,".base64_encode($png);
    }

    private function dialectLabel(string $dialect): string
    {
        return match ($dialect) {
            "msa" => "العربية الفصحى",
            "gulf" => "لهجة خليجية خفيفة",
            default => "لهجة مصرية عامية",
        };
    }

    private function audienceLabel(string $aud): string
    {
        return match ($aud) {
            "women" => "خاطب البنات/السيدات بصياغة تناسبهم",
            "men" => "خاطب الرجالة بصياغة تناسبهم",
            default => "خاطب الجمهور بشكل عام",
        };
    }

    private function bgInstruction(array $colors, int $strength, string $dir): string
    {
        if (!count($colors)) {
            return "الخلفية: اختار أنت ألوان متناسقة مع المنتج (تدرّج ناعم وبسيط).";
        }

        $dirText = match ($dir) {
            "left_to_right" => "من الشمال لليمين",
            "diagonal" => "قطري",
            "radial" => "دائري",
            default => "من فوق لتحت",
        };

        $colorsText = implode(" , ", $colors);
        $strength = max(5, min(80, $strength));

        return "الخلفية: استخدم تدرّج بالألوان التالية: {$colorsText}. اتجاه التدرّج: {$dirText}. شدة التدرّج تقريبًا {$strength}% (واضح حسب النسبة).";
    }

    public function generate(Request $request)
    {
        $request->validate([
            'description' => 'required|string',
            'qty' => 'required|integer|min:1|max:6',
            'images' => 'required|array|min:1',
            'images.*' => 'required|image|max:6144',
            'dialect' => 'nullable|string',
            'audience' => 'nullable|string',
            'bg_colors' => 'nullable|string',
            'gradient_strength' => 'nullable|integer|min:5|max:80',
            'gradient_direction' => 'nullable|string',
        ]);

        $desc = trim($request->input('description'));
        $qty  = (int)$request->input('qty');
        $dialect = (string)$request->input('dialect', 'egyptian_colloquial');
        $audience = (string)$request->input('audience', 'general');

        $bgColorsRaw = (string)$request->input('bg_colors', '[]');
        $bgColors = json_decode($bgColorsRaw, true);
        if (!is_array($bgColors)) $bgColors = [];
        $bgColors = array_values(array_unique(array_map(fn($c)=>strtolower(trim((string)$c)), $bgColors)));
        $bgColors = array_filter($bgColors, fn($c)=>preg_match('/^#[0-9a-f]{6}$/i', $c));

        $gradStrength = (int)$request->input('gradient_strength', 25);
        $gradDir = (string)$request->input('gradient_direction', 'top_to_bottom');

        $dialectLabel = $this->dialectLabel($dialect);
        $audLabel = $this->audienceLabel($audience);
        $bgInst = $this->bgInstruction($bgColors, $gradStrength, $gradDir);

        $imageParts = $this->toInlineParts($request->file('images'));

        // ✅ brief حسب اللهجة والجمهور + بدون إنجليزي + بدون CTA
        $briefPrompt = <<<AR
ممنوع تمامًا استخدام أي كلمة إنجليزية.

اللغة/اللهجة المطلوبة: {$dialectLabel}.
التوجيه: {$audLabel}.

اكتب محتوى “بوستر لاند” بنفس تقسيمة لاندات الإعلانات:
- شريط علوي صغير فيه 3 عناصر قصار (شحن/دفع/ضمان)
- عنوان قوي جدًا (سطر واحد)
- سطر توضيح صغير
- 3 دواير فوائد (كل واحدة 2-3 كلمات)
- سيكشن قبل/بعد: عنوان قصير + كلمة “قبل” و “بعد”
- سيكشن ثقة: جملة ثقة قصيرة + سطر ضمان/استرجاع مختصر بدون مبالغة
- سيكشن مكونات/مميزات أسفل: 3 أيقونات وكل واحدة 2-3 كلمات

ممنوع وضع زر شراء أو CTA أو (اطلب الآن) أو أي دعوة شراء.

وصف المنتج:
{$desc}
AR;

        [$okB, $brief] = $this->geminiText($briefPrompt);
        if (!$okB) return response()->json(["success"=>false,"message"=>$brief], 500);

        $angles = [
            "تركيز على النتيجة + قبل/بعد",
            "تركيز على الثقة والضمان",
            "تركيز على المكونات/المميزات",
            "تركيز على حل المشكلة الأساسية",
            "تركيز على الجودة والشكل",
            "تركيز على سهولة الاستخدام",
        ];

        $out = [];
        for ($i=0; $i<$qty; $i++) {
            $angle = $angles[$i % count($angles)];

            $imgPrompt = <<<AR
استخدم صور المنتج المرجعية فقط: نفس شكل المنتج الحقيقي تمامًا.
ممنوع أي كتابة إنجليزية داخل الصورة.

مهم: صمّم بوستر لاند إعلان بنفس تقسيمة أمثلة اللاندات (سيكشنات واضحة).
ممنوع وضع زر شراء أو CTA أو (اطلب الآن).

المقاس النهائي: 1080×1920 (Portrait 9:16).
{$bgInst}

التخطيط المطلوب:
- شريط علوي صغير 3 عناصر (شحن سريع / دفع آمن / ضمان استرجاع)
- هيرو: المنتج واضح كبير + عنوان كبير
- 3 دواير فوائد بجانب المنتج أو تحت العنوان
- قبل/بعد: مربعين واضحين بعنوان قبل/بعد وسهم أو فصل بصري
- ثقة: جزء فيه صورة شخص/مجموعة/طبيب بشكل رمزي محترم (بدون ادعاءات طبية)
- أسفل: 3 أيقونات للمكونات/المميزات مع نص قصير

اللغة/اللهجة: {$dialectLabel}.
التوجيه: {$audLabel}.

زاوية هذه النسخة:
{$angle}

النص العربي المستخدم داخل التصميم:
{$brief}
AR;

            [$okI, $img] = $this->geminiImage($imgPrompt, $imageParts);
            if ($okI) {
                $out[] = $this->enforceSize1080x1920($img);
            }
        }

        return response()->json([
            "success" => true,
            "images" => $out,
            "brief" => $brief
        ]);
    }
}
