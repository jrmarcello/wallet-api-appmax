<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckIdempotency
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Apenas métodos de escrita precisam de idempotência
        if (!$request->isMethod('POST') && !$request->isMethod('PUT') && !$request->isMethod('PATCH')) {
            return $next($request);
        }

        // 2. Verificar Header
        $key = $request->header('Idempotency-Key');

        if (!$key) {
            // Para fins deste teste, não vamos bloquear se faltar, 
            // mas em prod retornaríamos 400 Bad Request.
            return $next($request);
        }

        $userId = auth('api')->id() ?? 'guest';
        $cacheKey = "idempotency_{$userId}_{$key}";

        // 3. Check Cache (Redis)
        if (Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);
            
            // Retorna a MESMA resposta anterior, mas adiciona um header avisando
            return response()->json(
                $cachedResponse['content'], 
                $cachedResponse['status']
            )->header('X-Idempotency-Hit', 'true');
        }

        // 4. Processar Request
        $response = $next($request);

        // 5. Salvar no Cache (Apenas se foi sucesso 2xx)
        if ($response->isSuccessful()) {
            $content = json_decode($response->getContent(), true);
            
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'content' => $content
            ], now()->addDay()); // TTL 24h

            // Opcional: Salvar no DB também para auditoria (na tabela idempotency_keys que criamos)
            // DB::table('idempotency_keys')->insert(...)
        }

        return $response;
    }
}