#!/bin/bash

# Configurações
BASE_URL="http://localhost:8000/api"
ID=$(date +%s)
EMAIL="race_${ID}@test.com"
PASSWORD="password123" # Atende ao min:8 do Request

echo "🏎️  Iniciando Race Condition Test (Stress Test)"
echo "------------------------------------------------"

# 1. Cria usuário e pega token
echo "👤 Criando usuário: $EMAIL..."

# Adicionamos 'Accept: application/json' para garantir erros legíveis em JSON
RESPONSE=$(curl -s -X POST "$BASE_URL/auth/register" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"name\":\"Race Runner\",\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")

# Extrai o token do JSON
TOKEN=$(echo $RESPONSE | grep -o '"token":"[^"]*' | cut -d'"' -f4)

# Validação de segurança do script
if [ -z "$TOKEN" ]; then
    echo "❌ Falha ao criar usuário. O servidor respondeu:"
    echo "$RESPONSE"
    exit 1
fi

echo "🔑 Token capturado com sucesso."

# 2. Deposita 1000 (R$ 10,00)
echo "💰 Depositando R$ 10,00 (1000 cents)..."
curl -s -X POST "$BASE_URL/wallet/deposit" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Idempotency-Key: setup-$ID" \
  -d '{"amount": 1000}' > /dev/null

# 3. Dispara 5 saques simultâneos
# Matemática: 5x 300 = 1500 tentado. Saldo = 1000.
# Com Lock: 3 passam (900), 2 falham. Sobra 100.
# Sem Lock: Todos passam. Saldo vira -500.

echo "🚀 Disparando 5 saques simultâneos de R$ 3,00..."
echo "   (Total Tentado: R$ 15,00 | Disponível: R$ 10,00)"

for i in {1..5}
do
   curl -s -X POST "$BASE_URL/wallet/withdraw" \
   -H "Authorization: Bearer $TOKEN" \
   -H "Content-Type: application/json" \
   -H "Accept: application/json" \
   -H "Idempotency-Key: race-$ID-$i" \
   -d '{"amount": 300}' > /dev/null & 
   # O '&' joga pro background permitindo paralelismo real
done

wait # Espera todos os processos acabarem

echo ""
echo "✅ Requests finalizados."
echo "📊 Verificando saldo final (Esperado: 100)..."

# 4. Consulta saldo final
curl -s -X GET "$BASE_URL/wallet/balance" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

echo "" # Quebra de linha estética
