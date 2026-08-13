<?php
declare(strict_types=1);

namespace BinanceBot\Strategy;

class ChartVL
{
    /**
     * Análisis de gráfico con NVIDIA Vision-Language API.
     *
     * @param string $imagePath Ruta al archivo PNG del gráfico
     * @param string $apiKey    API key de NVIDIA
     * @return array|null  ['direction','confidence','reason','volatility'] o null si falla
     */
    public static function analyze(string $imagePath, string $apiKey): ?array
    {
        if (!file_exists($imagePath)) {
            return null;
        }
        $imageData = base64_encode(file_get_contents($imagePath));
        $prompt = "Analiza este gráfico de velas de ETH/USDT en timeframe 5 minutos. " .
            "Identifica: tendencia principal, niveles de soporte/resistencia visibles, " .
            "patrones de velas. Responde SOLO con un JSON válido: " .
            "{\"direction\":\"UP/DOWN/SIDEWAYS\", \"confidence\":0-100, " .
            "\"reason\":\"breve razón\", \"volatility\":\"low/medium/high\"}";

        $url = "https://integrate.api.nvidia.com/v1/chat/completions";
        $payload = [
            "model" => "nvidia/llama-3.1-nemotron-nano-vl-8b-v1",
            "messages" => [[
                "role" => "user",
                "content" => [
                    ["type" => "text", "text" => $prompt],
                    ["type" => "image_url", "image_url" => ["url" => "data:image/png;base64,{$imageData}"]],
                ],
            ]],
            "temperature" => 0.2,
            "max_tokens" => 300,
            "stream" => false,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json",
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            lE("[VL] Error HTTP $httpCode: $resp");
            return null;
        }
        $data = json_decode($resp, true);
        $content = isset($data['choices'][0]['message']['content'])
            ? $data['choices'][0]['message']['content']
            : '';
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /**
     * Backward-compatible wrapper como función global.
     * Permite que bot.php siga usando analyzeChartWithVL() sin alterar GridManager.
     */
    public static function legacyFunction(): \Closure
    {
        return function ($imagePath, $apiKey) {
            return self::analyze($imagePath, $apiKey);
        };
    }
}
