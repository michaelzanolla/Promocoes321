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

**Testes ponta a ponta** (navegador de verdade, com Playwright): escolha de números, reserva, dados do comprador, desistência, disputa de um mesmo número por duas pessoas e uso da grade pelo teclado.

```bash
npx playwright install chromium   # só na primeira vez
npm run build
npm run test:e2e
```

Eles sobem um servidor próprio na porta 8001 e recriam o banco `promocoes_test` com os dados de demonstração. Não rode ao mesmo tempo que os testes PHP, que usam o mesmo banco. O pagamento não entra nesses testes: depende do Asaas e é coberto pelos testes PHP (provedor simulado) e pela execução manual no sandbox do checklist de publicação.

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

## Comprovantes, área do participante e e-mails

- **Comprovante** (gerado quando o pagamento é confirmado):
  - `/comprovante/{código}`: verificação pública. Mostra promoção, responsável, pedido, números, valores, forma e situação do pagamento, data da confirmação e QR Code. O nome aparece abreviado ("Maria S.") e nunca há CPF, e-mail ou telefone.
  - `/pedido/{código}/comprovante`: versão do comprador, com nome completo, botão de imprimir e PDF.
  - Tudo é montado a partir do banco: editar o PDF ou o HTML não muda o que a verificação mostra. Pedido reembolsado aparece como "não é mais válido".
- **Minhas compras** (`/dashboard`): pedidos feitos logado e os feitos como convidado com o mesmo e-mail, se o e-mail da conta estiver confirmado.
- **Consultar pedido** (`/consultar-pedido`): código + e-mail; o link de acesso vai por e-mail. A resposta é sempre a mesma, exista ou não o pedido.
- **E-mails**: "números reservados" (ao confirmar os dados), "pagamento confirmado" e "link do pedido". São enviados depois que a página já respondeu e nunca interrompem o pagamento se o envio falhar. Em desenvolvimento ficam em `storage/logs/laravel.log` (`MAIL_MAILER=log`); em produção, configure o SMTP no `.env` (Fase 8).

## Produção (HostGator / cPanel)

O código fica fora do `public_html`. Estrutura no servidor:

```
~/promocoes           versão no ar (o subdomínio aponta para ~/promocoes/public)
~/promocoes.old       versão anterior, para voltar atrás
~/promocoes-shared/   .env, storage (sessões, logs, imagens) e backups — sobrevivem aos deploys
```

Nunca rode `db:seed` em produção: o seeder recusa esse ambiente.

### 1. Acesso SSH (uma vez)

No Git Bash do seu PC:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/hostgator
```

Em cPanel > Acesso SSH > Gerenciar chaves SSH > Importar chave: cole o conteúdo de `~/.ssh/hostgator.pub` e depois clique em **Autorizar**. Crie o arquivo `~/.ssh/config` no PC:

```
Host promocoes
    HostName SEU-DOMINIO
    User USUARIO_DO_CPANEL
    Port 2222
    IdentityFile ~/.ssh/hostgator
```

A porta costuma ser 2222 na HostGator; confira no cPanel. Teste com `ssh promocoes` e, já dentro do servidor, `php -v`: precisa ser 8.3. Se não for, use o caminho completo (geralmente `/opt/cpanel/ea-php83/root/usr/bin/php`) no lugar de `php` em todos os comandos abaixo e no deploy (`REMOTE_PHP=...`).

### 2. Banco, e-mail e subdomínio (cPanel, uma vez)

- **Banco**: Bancos de dados MySQL > crie um banco e um usuário exclusivos e dê ao usuário todos os privilégios só nesse banco.
- **E-mail**: Contas de e-mail > crie `nao-responda@SEU-DOMINIO`. Em Entregabilidade de e-mail, confira se SPF e DKIM estão válidos.
- **Subdomínio**: Domínios > criar, com raiz do documento `promocoes/public` (sem `public_html`). Em seguida, SSL/TLS Status > rodar o AutoSSL para o subdomínio. O `.htaccess` já redireciona tudo para HTTPS.
- No MultiPHP Manager, deixe o subdomínio em PHP 8.3. No MultiPHP INI Editor, use `upload_max_filesize = 8M` e `post_max_size = 10M` (imagens das promoções e comprovação do sorteio, até 5 MB).

### 3. `.env` do servidor (uma vez)

```bash
ssh promocoes "mkdir -p ~/promocoes-shared && chmod 700 ~/promocoes-shared"
scp deploy/env.production.example promocoes:promocoes-shared/.env
ssh promocoes "chmod 600 ~/promocoes-shared/.env"
```

Edite `~/promocoes-shared/.env` no servidor (Gerenciador de arquivos do cPanel ou `nano`) e preencha tudo o que está em MAIÚSCULAS. A `APP_KEY` você gera no PC com `php artisan key:generate --show`. Comece com o Asaas em **sandbox**.

### 4. Deploy

No Git Bash, na raiz do projeto, com tudo commitado:

```bash
bash deploy/deploy.sh promocoes
```

O script compila os assets, monta o último commit com as dependências de produção, envia e, no servidor, roda as migrations, troca a versão e gera os caches (`php artisan optimize`). Use o mesmo comando para cada atualização.

No primeiro deploy, crie o administrador:

```bash
ssh -t promocoes "cd ~/promocoes && php artisan app:create-admin seu-email@exemplo.com"
```

**Voltar para a versão anterior** (as migrations não são desfeitas):

```bash
ssh promocoes "mv ~/promocoes ~/promocoes.bad && mv ~/promocoes.old ~/promocoes && cd ~/promocoes && php artisan optimize"
```

### 5. Cron (cPanel > Trabalhos cron)

| Quando | Comando |
|---|---|
| A cada minuto | `cd ~/promocoes && php artisan schedule:run > /dev/null 2>&1` |
| Uma vez por dia (ex.: 3h) | `bash ~/promocoes/deploy/backup.sh NOME_DO_BANCO` |

O primeiro cobre `orders:expire` e `payments:sync`. O segundo guarda 7 dias de backup em `~/promocoes-shared/backups`; se falhar, o cPanel manda a saída para o e-mail configurado em Trabalhos cron. Antes, crie `~/promocoes-shared/backup.cnf` com permissão 600:

```
[client]
user=USUARIO_DO_BANCO
password="SENHA_DO_BANCO"
```

Uma vez por mês, baixe um backup para fora da HostGator (PC ou nuvem) e teste restaurar em um banco local.

**Guarde a `APP_KEY` do servidor fora da HostGator** (gerenciador de senhas): o CPF dos compradores fica criptografado com ela. Sem ela, o backup do banco não recupera os CPFs. Nunca troque a `APP_KEY` de um sistema em uso.

### 6. Webhook do Asaas

Siga a seção [Webhook](#webhook-depois-do-deploy) com a URL `https://SEU-SUBDOMINIO/webhooks/asaas` e o token igual ao `ASAAS_WEBHOOK_TOKEN` do `.env` do servidor. Depois de mudar o `.env`, rode `php artisan optimize` no servidor (a configuração fica em cache).

### Checklist de publicação

Antes de trocar `ASAAS_ENV` para `production`:

- [ ] Validação jurídica do modelo de promoção e textos legais definitivos (`resources/content/*.md`).
- [ ] Confirmação por escrito do Asaas de que a atividade é aceita na conta.
- [ ] Dados reais do responsável e contato em Painel > Configurações.
- [ ] 2FA no cPanel, no Asaas e na conta de administrador da plataforma.
- [ ] WordPress do mesmo cPanel atualizado, sem plugins e temas sem uso.
- [ ] Chave de API do Asaas restrita, se o Asaas permitir (sem transferências/saques via API ou por IP).
- [ ] Execução completa no sandbox dentro da HostGator: reserva, Pix, cartão, webhook, e-mail recebido, comprovante, reembolso e expiração de reserva.
- [ ] Backup diário rodando e um restore testado.
- [ ] `APP_DEBUG=false` (uma página de erro não pode mostrar detalhes técnicos).
