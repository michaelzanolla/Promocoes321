# Promoções 321

Plataforma de ações entre amigos: promoções com números, reserva atômica, pagamento por Pix ou cartão (Asaas) e comprovante verificável.

- Especificação: [CONTEXT.md](CONTEXT.md)
- Arquitetura, modelo de dados e plano: [docs/fase-1-planejamento.md](docs/fase-1-planejamento.md)

## Stack

Laravel 13 (PHP 8.3) · MySQL 8.0 · Livewire 4 + Flux (telas de conta) · Tailwind · Fortify (autenticação, 2FA).

## Ambiente de desenvolvimento (Windows)

### Pré-requisitos

| Ferramenta | Como instalar |
|---|---|
| PHP 8.3 | `winget install PHP.PHP.8.3` e depois criar o `php.ini` (ver abaixo) |
| Composer | Instalador oficial em <https://getcomposer.org> |
| Node.js 20+ | <https://nodejs.org> |
| Docker Desktop | Roda o MySQL local |

Extensões do PHP que precisam estar ativas no `php.ini`: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `zip`. O jeito mais simples: copiar `php.ini-development` para `php.ini` na pasta do PHP e tirar o `;` dessas linhas e de `extension_dir = "ext"`.

### Primeira instalação

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

No `.env`, defina uma senha para o banco local em `DB_PASSWORD` (qualquer valor; ela só existe na sua máquina). Depois:

```bash
docker compose up -d
php artisan migrate
php artisan storage:link
php artisan db:seed
npm run build
```

O `storage:link` cria o atalho `public/storage`, necessário para exibir as imagens enviadas pelo painel. Os arquivos do painel (Filament) são publicados automaticamente pelo `composer install`.

O `db:seed` cria dados **fictícios** e mostra no terminal as senhas geradas para `admin@demo.test` e `participante@demo.test`. Elas não ficam gravadas em nenhum arquivo; para gerar novas, rode o `db:seed` de novo.

### Rodar a aplicação

```bash
docker compose up -d
composer dev
```

Abra <http://localhost:8000>. E-mails (confirmação, recuperação de senha) vão para `storage/logs/laravel.log` enquanto `MAIL_MAILER=log`.

### Testes e qualidade

```bash
composer test
```

Roda formatação (Pint), análise estática (PHPStan) e os testes (PHPUnit).

O `tests/Feature/ConcurrencyTest.php` prova que um número não pode ser vendido duas vezes: ele dispara cerca de 30 processos PHP ao mesmo tempo, cada um com sua conexão ao MySQL, disputando os mesmos números. Para rodar só ele: `php artisan test --filter=ConcurrencyTest`. Alguns ambientes restritos limitam quantos processos um comando pode criar; se aparecer `CreateProcess failed, error code: 5`, rode esse teste separado do `composer test`. Os testes usam o banco `promocoes_test` no mesmo MySQL do Docker, criado automaticamente na primeira subida do container. Se o container já existia antes disso, recrie-o com `docker compose down -v` e `docker compose up -d` (isso apaga o banco local).

## Perfis de acesso

- **Participante**: todo cadastro feito pelo site.
- **Administrador**: só pode ser criado pelo terminal do servidor:

```bash
php artisan app:create-admin email@exemplo.com
```

Pelo site, só outro administrador (em **Painel > Usuários**) pode mudar o perfil de alguém. Nenhum formulário do site aceita esse campo.

## Painel administrativo

Fica em `/admin` e usa o mesmo login do site (com 2FA, se ativado). Só administradores com conta ativa entram.

- **Painel de controle**: promoções, números, pedidos e valores. Reservas e pagamentos pendentes nunca entram como receita.
- **Promoções**: criar, editar, duplicar, ver no site (inclusive rascunhos, como pré-visualização), publicar, pausar, retomar, encerrar, registrar resultado e cancelar. Preço, numeração e formas de pagamento travam depois do primeiro pedido. Só rascunhos sem pedidos podem ser excluídos.
- **Usuários**: criar (a pessoa recebe um e-mail para definir a própria senha), editar, ativar/desativar e ver o histórico de atividades. Usuários não são apagados.
- **Auditoria**: registro somente leitura de todas as ações administrativas.
- **Configurações**: dados do responsável, contato e textos da página inicial.

## Como funciona a reserva

1. Na página da promoção, **Escolher números** abre a grade. Ela consulta a situação dos números a cada 5 segundos, mas só para informar: quem decide é o servidor.
2. **Reservar e continuar** reserva todos os números escolhidos de uma vez, ou nenhum. Se algum acabou de ser pego por outra pessoa, a tela mostra qual e mantém o resto da seleção.
3. Na página do pedido, a pessoa informa os dados, escolhe a forma de pagamento e aceita as regras enquanto a reserva vale (prazo configurável por promoção).
4. Reservas vencidas são liberadas na hora em que alguém disputa o número e, como limpeza, pelo agendador (`php artisan orders:expire`, a cada minuto via `schedule:run`).

Compradores sem conta acessam o pedido pela mesma sessão do navegador ou pelo link secreto do pedido. Quem não tem acesso recebe "página não encontrada", sem confirmar que o código existe.

## Pagamentos (Asaas)

Configuração no `.env` (nunca no código), com aspas simples porque a chave começa com `$`:

```
ASAAS_ENV=sandbox            # sandbox = testes; production = dinheiro real
ASAAS_API_KEY='$aact_...'
ASAAS_WEBHOOK_TOKEN='...'    # vazio = todos os avisos de webhook são recusados
```

- **Pix:** a página do pedido mostra QR Code, copia e cola, valor e prazo.
- **Cartão:** o comprador paga na fatura hospedada do Asaas; os dados do cartão não passam pela plataforma.
- **Confirmação:** sempre consultando a cobrança na API do Asaas (fonte da verdade). Valor e código do pedido são conferidos. Voltar da página de pagamento não confirma nada.
- **Sem webhook (desenvolvimento):** a página do pedido consulta o Asaas enquanto está aberta, e `php artisan payments:sync` (agendado a cada minuto) concilia o resto.
- **Pagamento depois do prazo:** se os números ainda estiverem livres, o pedido é confirmado; se algum já foi para outra pessoa, o pedido fica **Em análise** no painel para o administrador reembolsar. Números nunca são trocados sozinhos.
- **Reembolso:** pelo painel (Pedidos > Reembolsar). O pedido só vira "reembolsado" quando o Asaas confirma.

### Testar um pagamento no sandbox

Gere o Pix pela página do pedido e, no painel do sandbox do Asaas, abra a cobrança e confirme o pagamento (o sandbox oferece essa opção para testes). A página do pedido atualiza sozinha em alguns segundos.

### Webhook (depois do deploy)

Em Asaas > Integrações > Webhooks: URL `https://SEU-SUBDOMINIO/webhooks/asaas`, versão v3, fila de sincronização ativada, tipo de envio sequencial, e os eventos de **cobranças** (todos os `PAYMENT_*`). Clique em **Gerar Token** e copie o mesmo valor para `ASAAS_WEBHOOK_TOKEN` no `.env` do servidor. O sistema trata avisos repetidos e fora de ordem, e sempre consulta a cobrança no Asaas antes de agir.

## Produção

O roteiro de deploy na HostGator (SSH, subdomínio, cron, SSL) será documentado na Fase 8. Nunca rode `db:seed` em produção: o seeder recusa esse ambiente.
