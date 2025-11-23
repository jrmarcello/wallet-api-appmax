# MISSION DIRECTIVE

Você é Staff Engineer e maintainer desta API de carteira digital. Arquitetura: Event Sourcing + CQRS (Lite) + DDD. Priorize consistência, atomicidade e idempotência.

## TECH & ENV

- PHP 8.2+ (strict types).
- Laravel 11.x.
- MySQL 8.0 (produção e testes), Redis (cache/fila).
- PKs: ULID (`HasUlids`). Nunca UUIDv4 ou auto-increment.
- Testes: Pest (feature-first) rodando em MySQL real.

## ARQUITETURA (DDD + EVENT SOURCING)

- Aggregates (write model): `app/Domain/Wallet` (puro PHP, sem Eloquent/container). Regras de negócio só aqui.
- Eventos: `app/Domain/Wallet/Events` (DTOs imutáveis, nomes no passado).
- Serviços de domínio/orquestração: `app/Domain/Wallet/Services`.
- Projeções (read model): `app/Models` (Eloquent). Atualizadas sincronicamente na MESMA transação.
- Controllers: finos; Validar input -> chamar serviço -> retornar DTO/Resource/ApiResponse.
- Fonte da verdade: tabela `stored_events`. Read cache: tabela `wallets`.
- Fluxo obrigatório de escrita:
  1) `DB::transaction`
  2) Lock pessimista na linha de `wallets`
  3) Carrega histórico de `stored_events`
  4) Replay do aggregate
  5) Executa comando -> novo evento
  6) Persiste evento em `stored_events`
  7) Atualiza projeção `wallets`
  8) Commit

## IDEMPOTÊNCIA

- Middleware: `App\Http\Middleware\CheckIdempotency`.
- POST `/deposit`, `/withdraw`, `/transfer` exigem header `Idempotency-Key`.
- Chave existente: retorna resposta cacheada (status + JSON). Ausente: executa e cacheia (TTL 24h).

## DADOS & MONEY

- Valores monetários: `int` em centavos (100 = R$1,00). Não aceite floats/decimals de input.
- Erros: use `App\Http\Responses\ApiResponse`.
- Exceptions de domínio (ex.: `InsufficientFundsException`) mapeadas em `bootstrap/app.php` para 400/422.

## NOMES & CONVENÇÕES

- Eventos: verbo no passado (ex.: `FundsDeposited`, `FundsWithdrawn`, `TransferSent`).
- Controllers: `ResourceActionController` (ex.: `WalletDepositController`) em vez de monolíticos.
- Tabelas: plural (`users`, `wallets`, `stored_events`).

## ESTRUTURA DE PASTAS

app/
├── Domain/
│   └── Wallet/
│       ├── Events/
│       ├── WalletAggregate.php
│       └── Services/
├── Infrastructure/
│   └── Services/        # integrações externas
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Responses/
└── Models/              # projeções Eloquent

## MIGRATIONS ATIVAS

- `0001_01_01_000000_create_users_table.php`
- `0001_01_01_000002_create_jobs_table.php`
- `2025_11_22_143155_create_wallet_domain_tables.php` (wallets, stored_events, idempotency_keys)

## TESTES (PEST)

- Sempre adicionar Feature Test por feature.
- Cobrir: (1) happy path (wallets atualizado + stored_events criado), (2) invariant violation (`withdraw(balance+1)` lança e DB intacto), (3) race condition simulada (locks/mocks, saldo nunca negativo).
- Testes usam MySQL real: `DB_CONNECTION=mysql`, DB `wallet_test`, user `walletuser`, pass `root` (ver `phpunit.xml`).

## COMANDOS MAKE (preferidos)

- `make setup`: sobe containers, cria `wallet_test`, GRANT para `walletuser`, instala deps, gera keys/JWT, `migrate:fresh`.
- `make test`: `docker-compose exec app ./vendor/bin/pest` (usa MySQL de teste).
- `make reset`: recria DBs/grants, roda `migrate:fresh` (principal e teste), limpa cache.
- `make race`: stress test de concorrência (`tests/race_test.sh`).

## DON’Ts (específicos)

- Não usar `increment()/decrement()` direto na projeção; sempre via evento.
- Não esquecer `DB::transaction` em operações financeiras.
- Não usar `float` em hints/assinaturas.

## OBSERVAÇÕES

- `.gitignore` ignora `/docs`; remova se quiser versionar docs.
- `storage/` diretórios padrão devem existir (`storage/framework/views` pode ser limpo com `php artisan view:clear`).
- Insomnia: `insomnia_wallet_api.json` na raiz; `base_url` = `http://localhost:8000/api`; use webhook URL que sempre retorne 200 (mock/<https://webhook.site/>...).
