<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CollageController extends Controller
{
    public function generate(Request $request)
    {
        try {
            $request->validate([
                'images'   => 'required|array|min:1',
                'images.*' => 'required|image|max:8192',
            ]);

            if (!extension_loaded('gd')) {
                return response()->json(["success"=>false,"message"=>"GD extension مش مفعلة على السيرفر."], 500);
            }

            $W = 1080;
            $H = 1920;

            // ثابتين — تقدر تغيرهم من هنا لاحقًا لو عايز
            $gap = 220;       // مساحة زر الشراء بين السيكشنات
            $topBottomPad = 0;

            $files = $request->file('images');

            // 1) جهّز كل صورة: resize لعرض 1080 وحساب ارتفاعها
            $prepared = [];
            foreach ($files as $f) {
                $raw = file_get_contents($f->getRealPath());
                $src = @imagecreatefromstring($raw);
                if (!$src) continue;

                $sw = imagesx($src);
                $sh = imagesy($src);

                if ($sw <= 0 || $sh <= 0) { imagedestroy($src); continue; }

                $scale = $W / $sw;
                $tw = $W;
                $th = (int)round($sh * $scale);

                // resize
                $resized = imagecreatetruecolor($tw, $th);
                imagealphablending($resized, true);
                imagesavealpha($resized, true);

                // خلفية بيضا للصورة نفسها (لو فيها شفافية)
                $white = imagecolorallocate($resized, 255,255,255);
                imagefill($resized, 0,0, $white);

                imagecopyresampled($resized, $src, 0,0, 0,0, $tw,$th, $sw,$sh);
                imagedestroy($src);

                $prepared[] = ["im"=>$resized, "w"=>$tw, "h"=>$th];
            }

            if (!count($prepared)) {
                return response()->json(["success"=>false,"message"=>"مقدرتش أقرأ الصور. جرّب صور JPG/PNG واضحة."], 422);
            }

            // 2) رص الصور في صفحات 1080x1920 (Full bleed)
            $pages = [];
            $canvas = $this->newCanvas($W, $H);
            $y = $topBottomPad;

            $totalImages = count($prepared);
            $placedCount = 0;

            foreach ($prepared as $idx => $p) {
                $img = $p["im"];
                $ih  = $p["h"];

                // لو الصورة أطول من الصفحة، نقصها على أجزاء
                $srcY = 0;
                while ($srcY < $ih) {

                    $remainingPage = $H - $y;
                    if ($remainingPage <= 0) {
                        $pages[] = $this->exportPng($canvas);
                        imagedestroy($canvas);
                        $canvas = $this->newCanvas($W, $H);
                        $y = $topBottomPad;
                        $remainingPage = $H - $y;
                    }

                    // الجزء اللي هنحطه دلوقتي
                    $chunkH = min($ih - $srcY, $remainingPage);

                    // لصق الجزء
                    imagecopy($canvas, $img, 0, $y, 0, $srcY, $W, $chunkH);
                    $y += $chunkH;
                    $srcY += $chunkH;

                    // لو خلصنا الصورة بالكامل، سيب gap (مكان زر الشراء) قبل الصورة اللي بعدها
                    if ($srcY >= $ih) {
                        $placedCount++;

                        // gap بعد الصورة إلا لو دي آخر صورة
                        if ($idx < $totalImages - 1) {
                            // لو الـ gap مش هيدخل، افتح صفحة جديدة
                            if ($y + $gap > $H) {
                                $pages[] = $this->exportPng($canvas);
                                imagedestroy($canvas);
                                $canvas = $this->newCanvas($W, $H);
                                $y = $topBottomPad;
                            } else {
                                // اترك مساحة بيضاء فقط
                                $y += $gap;
                            }
                        }
                    }

                    // لو الصفحة اتملت
                    if ($y >= $H) {
                        $pages[] = $this->exportPng($canvas);
                        imagedestroy($canvas);
                        $canvas = $this->newCanvas($W, $H);
                        $y = $topBottomPad;
                    }
                }

                imagedestroy($img);
            }

            // آخر صفحة
            $pages[] = $this->exportPng($canvas);
            imagedestroy($canvas);

            return response()->json([
                "success" => true,
                "pages" => array_map(fn($png)=>"data:image/png;base64,".base64_encode($png), $pages),
                "total_pages" => count($pages),
                "gap" => $gap,
                "images_processed" => $placedCount
            ]);

        } catch (\Throwable $e) {
            return response()->json(["success"=>false,"message"=>"Server error: ".$e->getMessage()], 500);
        }
    }

    private function newCanvas(int $W, int $H)
    {
        $canvas = imagecreatetruecolor($W, $H);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        // Canvas أبيض بالكامل (مفيش خلفية تصميمية)
        $white = imagecolorallocate($canvas, 255,255,255);
        imagefill($canvas, 0,0, $white);

        return $canvas;
    }

    private function exportPng($im): string
    {
        ob_start();
        imagepng($im, null, 8);
        return ob_get_clean();
    }
}
