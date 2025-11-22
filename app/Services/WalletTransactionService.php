<?php

namespace App\Services;

use App\Domain\Wallet\WalletAggregate;
use App\Models\Wallet;
use App\Repositories\WalletRepository;
use Illuminate\Database\DatabaseManager;
use Exception;

class WalletTransactionService
{
    public function __construct(
        protected WalletRepository $repository,
        protected DatabaseManager $db
    ) {}

    /**
     * Executa um Depósito Atômico
     */
    public function deposit(string $userId, int $amount): array
    {
        return $this->db->transaction(function () use ($userId, $amount) {
            // 1. Lock & Load: Buscamos o ID da carteira travando a linha
            // Isso impede que outro processo altere essa carteira agora (Race Condition)
            $walletModel = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $walletId = $walletModel->id;

            // 2. Event Sourcing: Reconstruir o estado
            $history = $this->repository->getHistory($walletId);
            $aggregate = WalletAggregate::retrieve($walletId, $history);

            // 3. Domain Logic: Executar ação
            $newEvent = $aggregate->deposit($amount);

            // 4. Persistência: Salvar evento + Atualizar Projeção
            $this->repository->append($newEvent);
            $this->repository->updateProjection($walletId, $aggregate->getBalance()); // Saldo novo já calculado

            return [
                'wallet_id' => $walletId,
                'new_balance' => $aggregate->getBalance(),
                'transaction_id' => $walletId // Poderíamos retornar o ID do evento também
            ];
        });
    }

    /**
     * Executa um Saque Atômico
     */
    public function withdraw(string $userId, int $amount): array
    {
        return $this->db->transaction(function () use ($userId, $amount) {
            // 1. Lock Pessimista
            $walletModel = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $walletId = $walletModel->id;

            // 2. Replay
            $history = $this->repository->getHistory($walletId);
            $aggregate = WalletAggregate::retrieve($walletId, $history);

            // 3. Domain Logic (Aqui pode estourar Exception de Saldo Insuficiente)
            $newEvent = $aggregate->withdraw($amount);

            // 4. Persistência
            $this->repository->append($newEvent);
            $this->repository->updateProjection($walletId, $aggregate->getBalance());

            return [
                'wallet_id' => $walletId,
                'new_balance' => $aggregate->getBalance()
            ];
        });
    }
    
    /**
     * Consulta de Saldo Rápida (Sem replay, direto da projeção)
     */
    public function getBalance(string $userId): int 
    {
        return Wallet::where('user_id', $userId)->value('balance') ?? 0;
    }

    /**
     * Executa Transferência P2P Atômica
     */
    public function transfer(string $payerUserId, string $payeeUserId, int $amount): array
    {
        // Regra básica: não pode transferir para si mesmo
        if ($payerUserId === $payeeUserId) {
            throw new \InvalidArgumentException("Cannot transfer to self");
        }

        return $this->db->transaction(function () use ($payerUserId, $payeeUserId, $amount) {
            // 1. Lock Strategy (Deadlock Prevention)
            // Precisamos descobrir os IDs das wallets primeiro para saber a ordem de lock
            // Se A manda pra B, e B manda pra A ao mesmo tempo, pode dar Deadlock se não ordenarmos.
            
            // Buscamos os IDs (sem lock ainda, leitura rápida)
            $payerWalletId = Wallet::where('user_id', $payerUserId)->value('id');
            $payeeWalletId = Wallet::where('user_id', $payeeUserId)->value('id');

            if (!$payerWalletId || !$payeeWalletId) {
                throw new \Exception("One or both users do not have a wallet configured.");
            }

            // Ordenamos os IDs para garantir que sempre lockamos na mesma ordem (ex: menor -> maior)
            $idsToLock = [$payerWalletId, $payeeWalletId];
            sort($idsToLock);

            // Agora aplicamos o Lock For Update na ordem correta
            // Isso evita que Processo 1 trave A e queira B, enquanto Processo 2 trava B e quer A.
            $lockedWallets = Wallet::whereIn('id', $idsToLock)->lockForUpdate()->get()->keyBy('id');

            // 2. Replay dos Agregados
            // Payer (Pagador)
            $payerHistory = $this->repository->getHistory($payerWalletId);
            $payerAggregate = WalletAggregate::retrieve($payerWalletId, $payerHistory);

            // Payee (Recebedor)
            $payeeHistory = $this->repository->getHistory($payeeWalletId);
            $payeeAggregate = WalletAggregate::retrieve($payeeWalletId, $payeeHistory);

            // 3. Domain Logic (Ação Dupla)
            // O Pagador tenta enviar (Valida saldo aqui)
            $eventSent = $payerAggregate->sendTransfer($payeeWalletId, $amount);
            
            // O Recebedor aceita
            $eventReceived = $payeeAggregate->receiveTransfer($payerWalletId, $amount);

            // 4. Persistência Atômica
            // Salva Evento de Saída
            $this->repository->append($eventSent);
            $this->repository->updateProjection($payerWalletId, $payerAggregate->getBalance());

            // Salva Evento de Entrada
            $this->repository->append($eventReceived);
            $this->repository->updateProjection($payeeWalletId, $payeeAggregate->getBalance());
            
            // 5. Disparo de Webhook (Fase de Bônus)
            // Vamos adicionar isso na próxima etapa, mas aqui seria o lugar:
            // event(new TransferProcessed($eventReceived));

            return [
                'transaction_id' => $payerWalletId . '-' . time(), // Id fictício de rastreio
                'payer_balance' => $payerAggregate->getBalance(),
                'payee_id' => $payeeUserId
            ];
        });
    }
}
