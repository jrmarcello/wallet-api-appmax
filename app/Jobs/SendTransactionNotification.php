<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTransactionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tenta por 3 vezes se falhar
     */
    public $tries = 3;

    /**
     * Espera exponencialmente (10s, 20s, 40s)
     */
    public $backoff = [10, 20, 40];

    public function __construct(
        public string $payeeId,
        public int $amount
    ) {}

    public function handle(): void
    {
        // URL mockada para simular o serviço externo (ex: Webhook.site)
        // Em prod, pegariamos do banco `webhook_endpoints` baseado no user
        $url = 'https://httpbin.org/post';

        try {
            $response = Http::timeout(5)->post($url, [
                'event' => 'transfer_received',
                'payee_id' => $this->payeeId,
                'amount' => $this->amount,
                'timestamp' => now()->toIso8601String()
            ]);

            if ($response->failed()) {
                throw new \Exception("Webhook failed with status: " . $response->status());
            }

            Log::info("Webhook enviado com sucesso para User {$this->payeeId}");

        } catch (\Exception $e) {
            Log::error("Falha no Webhook: " . $e->getMessage());
            // Lança exceção para o Laravel tentar de novo (Retry)
            throw $e;
        }
    }
}