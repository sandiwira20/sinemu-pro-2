<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ChatbotService
 *
 * Mengirim pesan ke chatbot AI dengan Groq sebagai provider utama
 * dan Gemini sebagai fallback. Menggunakan format OpenAI-compatible
 * untuk Groq dan format generateContent untuk Gemini.
 */
class ChatbotService
{
    private string $groqApiKey;

    private string $groqModel;

    private int $groqTimeout;

    private string $geminiApiKey;

    private string $geminiModel;

    private int $geminiTimeout;

    private string $primaryProvider;

    private const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    private const GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const MAX_HISTORY = 20;

    private const MAX_RETRIES = 2;

    private const FALLBACK_MESSAGE = 'Maaf, terjadi gangguan pada sistem kami. Coba lagi nanti ya! 🙏';

    private const SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah "Sinu", asisten virtual eksklusif milik Sinemu Indonesia — platform komunitas untuk pencarian barang hilang dan temuan.

IDENTITAS:
- Nama: Sinu
- Peran: Asisten virtual KHUSUS untuk platform Sinemu Indonesia
- Kamu HANYA boleh menjawab pertanyaan yang berkaitan dengan Sinemu dan layanannya

TUGAS KAMU (hanya seputar Sinemu):
1. Bantu user navigasi fitur Sinemu (cara lapor barang hilang, cara klaim barang temuan, cara mendaftar, dll)
2. Jawab FAQ tentang layanan Sinemu
3. Berikan tips pencarian barang hilang yang efektif di platform Sinemu
4. Bantu user memahami proses klaim dan verifikasi barang di Sinemu
5. Jelaskan fitur-fitur Sinemu kepada pengguna

Informasi tentang Sinemu:
- Sinemu adalah platform komunitas untuk melaporkan dan menemukan barang hilang
- User bisa membuat laporan barang hilang dengan detail (nama barang, lokasi hilang, tanggal, deskripsi, foto)
- Admin/Manager di setiap wilayah mengelola barang temuan yang ditemukan
- User bisa mengklaim barang temuan dengan menyertakan bukti kepemilikan
- Proses klaim memerlukan verifikasi dari admin sebelum barang diserahkan
- Platform ini gratis dan terbuka untuk seluruh masyarakat Indonesia

ATURAN KETAT — WAJIB DIIKUTI:
- Jawab HANYA pertanyaan yang berkaitan dengan Sinemu, barang hilang/temuan, atau penggunaan platform ini
- Jika user bertanya di luar topik Sinemu (contoh: coding, resep masakan, cuaca, berita, matematika, sejarah, teknologi umum, hal pribadi, dll), TOLAK dengan sopan dan arahkan kembali ke topik Sinemu
- JANGAN pernah menjawab pertanyaan umum yang tidak ada hubungannya dengan Sinemu, meskipun user memaksa
- JANGAN berperan sebagai asisten umum, ChatGPT, atau AI lain
- JANGAN memberikan informasi sensitif (password, data pribadi user lain, konfigurasi sistem, dll)
- Jawab dalam Bahasa Indonesia yang santai tapi sopan
- Gunakan emoji sesekali untuk kesan friendly 😊
- Jawaban singkat dan to the point (maksimal 3 paragraf)
- Jika tidak tahu jawaban spesifik tentang Sinemu, arahkan ke halaman bantuan atau hubungi support@sinemu.id

CARA MENOLAK PERTANYAAN DI LUAR SINEMU:
Gunakan respons seperti:
"Maaf, Sinu hanya bisa membantu hal-hal seputar Sinemu Indonesia 😊 Ada yang ingin kamu tanyakan tentang layanan kami, seperti cara lapor barang hilang atau klaim barang temuan?"
PROMPT;

    public function __construct()
    {
        $this->groqApiKey    = config('services.groq.api_key', '');
        $this->groqModel     = config('services.groq.model', 'llama-3.3-70b-versatile');
        $this->groqTimeout   = (int) config('services.groq.timeout', 15);
        $this->geminiApiKey  = config('services.gemini.api_key', '');
        $this->geminiModel   = config('services.gemini.model', 'gemini-2.0-flash');
        $this->geminiTimeout = (int) config('services.gemini.timeout', 15);
        $this->primaryProvider = config('services.chatbot.provider', 'groq');
    }

    /**
     * Kirim pesan ke chatbot AI dan dapatkan respons.
     *
     * @param  string  $userMessage  Pesan baru dari user
     * @param  array<int, array{role: string, content: string}>  $history  Riwayat percakapan sebelumnya
     * @return array{success: bool, message: string}
     */
    public function chat(string $userMessage, array $history = []): array
    {
        $recentHistory = array_slice($history, -self::MAX_HISTORY);

        // Tentukan urutan provider: primary dulu, lalu fallback
        $providers = $this->primaryProvider === 'groq'
            ? ['groq', 'gemini']
            : ['gemini', 'groq'];

        foreach ($providers as $provider) {
            $result = match ($provider) {
                'groq'   => $this->callGroq($userMessage, $recentHistory),
                'gemini' => $this->callGemini($userMessage, $recentHistory),
                default  => null,
            };

            if ($result !== null) {
                return $result;
            }
        }

        Log::error('ChatbotService: semua provider gagal merespons.');

        return [
            'success' => false,
            'message' => self::FALLBACK_MESSAGE,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Groq provider
    // ──────────────────────────────────────────────────────────────

    /**
     * Panggil Groq API (OpenAI-compatible).
     * Return null jika gagal agar caller bisa mencoba provider berikutnya.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function callGroq(string $userMessage, array $history): ?array
    {
        if (empty($this->groqApiKey)) {
            Log::warning('ChatbotService: Groq API key tidak dikonfigurasi.');

            return null;
        }

        $messages = $this->buildGroqMessages($history, $userMessage);

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::withHeaders(['Authorization' => 'Bearer '.$this->groqApiKey])
                    ->timeout($this->groqTimeout)
                    ->post(self::GROQ_ENDPOINT, [
                        'model'       => $this->groqModel,
                        'messages'    => $messages,
                        'temperature' => 0.7,
                        'max_tokens'  => 1024,
                    ]);

                $status = $response->status();

                // Non-retryable: auth / client errors
                if (in_array($status, [400, 401, 403, 404], true)) {
                    Log::warning('ChatbotService: Groq non-retryable error', [
                        'provider' => 'groq',
                        'model'    => $this->groqModel,
                        'status'   => $status,
                    ]);

                    return null;
                }

                // Retryable: rate limit atau server error
                if ($status === 429 || $status >= 500) {
                    if ($attempt < self::MAX_RETRIES) {
                        sleep(1 + $attempt);

                        continue;
                    }

                    Log::warning('ChatbotService: Groq retryable error setelah max retry', [
                        'provider' => 'groq',
                        'model'    => $this->groqModel,
                        'status'   => $status,
                    ]);

                    return null;
                }

                if ($response->failed()) {
                    Log::error('ChatbotService: Groq API error', [
                        'provider' => 'groq',
                        'model'    => $this->groqModel,
                        'status'   => $status,
                    ]);

                    return null;
                }

                $text = $response->json('choices.0.message.content');

                if (empty($text)) {
                    return [
                        'success' => false,
                        'message' => 'Maaf, saya tidak bisa menjawab pertanyaan itu. Coba tanya yang lain ya! 😊',
                    ];
                }

                return [
                    'success' => true,
                    'message' => trim($text),
                ];

            } catch (ConnectionException $e) {
                if ($attempt < self::MAX_RETRIES) {
                    sleep(1 + $attempt);

                    continue;
                }

                Log::error('ChatbotService: Groq connection error', [
                    'provider' => 'groq',
                    'model'    => $this->groqModel,
                    'error'    => $e->getMessage(),
                ]);

                return null;

            } catch (\Throwable $e) {
                Log::error('ChatbotService: Groq exception', [
                    'provider' => 'groq',
                    'model'    => $this->groqModel,
                    'error'    => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * Bangun array messages untuk format OpenAI-compatible (Groq).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildGroqMessages(array $history, string $newMessage): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
        ];

        foreach ($history as $msg) {
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $newMessage];

        return $messages;
    }

    // ──────────────────────────────────────────────────────────────
    // Gemini provider
    // ──────────────────────────────────────────────────────────────

    /**
     * Panggil Gemini API.
     * Return null jika gagal agar caller bisa mengembalikan pesan fallback.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function callGemini(string $userMessage, array $history): ?array
    {
        if (empty($this->geminiApiKey)) {
            Log::warning('ChatbotService: Gemini API key tidak dikonfigurasi.');

            return null;
        }

        $contents = $this->buildGeminiContents($history, $userMessage);
        $endpoint = self::GEMINI_BASE_URL."/models/{$this->geminiModel}:generateContent";

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => $this->geminiApiKey])
                    ->timeout($this->geminiTimeout)
                    ->post($endpoint, [
                        'system_instruction' => [
                            'parts' => [['text' => self::SYSTEM_PROMPT]],
                        ],
                        'contents'         => $contents,
                        'generationConfig' => [
                            'temperature'     => 0.7,
                            'topP'            => 0.95,
                            'topK'            => 40,
                            'maxOutputTokens' => 1024,
                        ],
                        'safetySettings' => [
                            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                        ],
                    ]);

                $status = $response->status();

                // Non-retryable: auth / client errors
                if (in_array($status, [400, 401, 403, 404], true)) {
                    Log::warning('ChatbotService: Gemini non-retryable error', [
                        'provider' => 'gemini',
                        'model'    => $this->geminiModel,
                        'status'   => $status,
                    ]);

                    return null;
                }

                // Retryable: rate limit atau server error
                if ($status === 429 || $status >= 500) {
                    if ($attempt < self::MAX_RETRIES) {
                        sleep(1 + $attempt);

                        continue;
                    }

                    Log::warning('ChatbotService: Gemini retryable error setelah max retry', [
                        'provider' => 'gemini',
                        'model'    => $this->geminiModel,
                        'status'   => $status,
                    ]);

                    return null;
                }

                if ($response->failed()) {
                    Log::error('ChatbotService: Gemini API error', [
                        'provider' => 'gemini',
                        'model'    => $this->geminiModel,
                        'status'   => $status,
                    ]);

                    return null;
                }

                $text = $response->json('candidates.0.content.parts.0.text');

                if (empty($text)) {
                    return [
                        'success' => false,
                        'message' => 'Maaf, saya tidak bisa menjawab pertanyaan itu. Coba tanya yang lain ya! 😊',
                    ];
                }

                return [
                    'success' => true,
                    'message' => trim($text),
                ];

            } catch (ConnectionException $e) {
                if ($attempt < self::MAX_RETRIES) {
                    sleep(1 + $attempt);

                    continue;
                }

                Log::error('ChatbotService: Gemini connection error', [
                    'provider' => 'gemini',
                    'model'    => $this->geminiModel,
                    'error'    => $e->getMessage(),
                ]);

                return null;

            } catch (\Throwable $e) {
                Log::error('ChatbotService: Gemini exception', [
                    'provider' => 'gemini',
                    'model'    => $this->geminiModel,
                    'error'    => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * Bangun contents array untuk Gemini API dari riwayat percakapan.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private function buildGeminiContents(array $history, string $newMessage): array
    {
        $contents = [];

        foreach ($history as $msg) {
            $contents[] = [
                'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $newMessage]],
        ];

        return $contents;
    }
}
