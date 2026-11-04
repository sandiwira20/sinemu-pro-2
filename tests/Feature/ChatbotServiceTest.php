<?php

namespace Tests\Feature;

use App\Services\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class]);

        config([
            'services.groq.api_key'      => 'fake-groq-key',
            'services.groq.model'        => 'llama-3.3-70b-versatile',
            'services.groq.timeout'      => 15,
            'services.gemini.api_key'    => 'fake-gemini-key',
            'services.gemini.model'      => 'gemini-2.0-flash',
            'services.gemini.timeout'    => 15,
            'services.chatbot.provider'  => 'groq',
        ]);
    }

    /** @test */
    public function groq_sukses_mengembalikan_jawaban(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => 'Halo! Saya Sinu, asisten Sinemu Indonesia 😊',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ChatbotService();
        $result  = $service->chat('Halo', []);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Sinu', $result['message']);
    }

    /** @test */
    public function groq_gagal_lalu_gemini_sukses(): void
    {
        Http::fake([
            // 401 = non-retryable, Groq langsung null tanpa sleep
            'https://api.groq.com/*' => Http::response(['error' => 'Unauthorized'], 401),

            // Gemini sukses
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Untuk lapor barang hilang, buka menu Laporan di Sinemu 😊'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ChatbotService();
        $result  = $service->chat('Bagaimana cara lapor barang hilang?', []);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Sinemu', $result['message']);
    }

    /** @test */
    public function semua_provider_gagal_mengembalikan_pesan_fallback(): void
    {
        Http::fake([
            // 401 = non-retryable, tidak ada sleep
            'https://api.groq.com/*'                      => Http::response(['error' => 'Unauthorized'], 401),
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $service = new ChatbotService();
        $result  = $service->chat('Tes pesan', []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('gangguan', $result['message']);
    }

    /** @test */
    public function api_key_tidak_tercatat_di_log(): void
    {
        Http::fake([
            // 401 = non-retryable untuk keduanya, tidak ada sleep
            'https://api.groq.com/*'                      => Http::response(['error' => 'Unauthorized'], 401),
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        $logMessages = [];

        Log::listen(function ($level, $message, $context) use (&$logMessages) {
            $logMessages[] = $message.' '.json_encode($context, JSON_UNESCAPED_UNICODE);
        });

        $service = new ChatbotService();
        $service->chat('Tes keamanan', []);

        $allLogs = implode(' ', $logMessages);

        $this->assertStringNotContainsString('fake-groq-key', $allLogs);
        $this->assertStringNotContainsString('fake-gemini-key', $allLogs);
    }
}
