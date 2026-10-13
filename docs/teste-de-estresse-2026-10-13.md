# Teste de estresse e segurança — 13/10/2026

Código testado: commit `e5ae9bc` (`main`). Nenhuma alteração de código foi feita durante o teste.

## Como foi feito

- **Ambiente isolado:** banco `promocoes_stress` (cópia dos dados de demonstração + 84 contas de teste), sem tocar no banco local `promocoes`.
- **4 servidores PHP** ao mesmo tempo (portas 8010–8013) apontando para o mesmo banco, para provocar corridas reais entre processos.
- **Provedor de pagamento simulado** no lugar do Asaas (mesma interface `PaymentGateway`, com latência de 150 ms e cobranças guardadas num arquivo compartilhado). O sandbox do Asaas **não** foi chamado.
- **Webhook** testado num servidor com token configurado.
- `APP_DEBUG=false` em todos os servidores (como em produção).
- 5 baterias de testes automatizados (≈150 verificações), mais leitura do código nos pontos sensíveis, `composer audit` e `npm audit`.

## Resumo

| Gravidade | Qtd. | Achados |
|---|---|---|
| Média | 3 | 1 corrida confirmada, 1 risco de reserva em massa, 1 erro no manual |
| Baixa | 5 | Erros 500 com entrada malformada, webhook malformado, peso da grade, limites por IP compartilhado, proxy a verificar |
| Informativo | 4 | Pacotes abandonados, defesa extra no `/storage`, trava durante a chamada ao Asaas, carga local não representativa |

Nenhuma falha permitiu ver ou alterar pedido de outra pessoa, entrar no painel sem ser administrador, vender um número duas vezes, alterar preço ou confirmar pagamento falso.

## Achados

### M1 — Corrida entre "Confirmar dados" e "Gerar Pix" (média, confirmada)

**Reprodução:** com o pedido já identificado como Pix, enviar ao mesmo tempo `POST /pedido/{c}/pagamento` e `PUT /pedido/{c}/dados` com `payment_method=credit_card`. **Resultado: 6 de 6 tentativas** terminaram com o pedido marcado como **cartão** e uma cobrança **Pix aberta**.

**Consequência:** o comprador vê "cartão" e, ao clicar em pagar, gera também a fatura do cartão. O pedido fica com duas cobranças pagáveis. Se as duas forem pagas, o sistema detecta a duplicidade e põe o pedido em "Em análise", mas o administrador precisa reembolsar uma delas.

**Causa:** `OrderController::identify` verifica "não há cobrança pendente" e grava a forma de pagamento **fora** da trava do pedido; `StartPayment` e `changeMethod` usam `lockForUpdate`, o `identify` não.

**Correção proposta:** fazer a verificação e a gravação do `identify` dentro de `DB::transaction` com `Order::lockForUpdate()`, igual ao `changeMethod`. Teste de regressão: a verificação de cobrança pendente acontece com o pedido travado.

### M2 — Reserva em massa trocando de IP (média, por análise de código)

**Situação:** para quem não tem conta, os limites de reserva (10 por minuto e 3 pedidos abertos por promoção) são contados pelo **IP exato** (`ReserveNumbers::ensureOpenOrdersBelowLimit`, limitador `reserve`). Localmente comprovei que os limites funcionam e que cabeçalhos falsos (`X-Forwarded-For`, `X-Real-IP`) **não** os burlam.

**Risco:** quem controla muitos IPs (um bloco IPv6 /64 tem bilhões de endereços, ou uma rede de proxies) pode reservar 3 × 50 números por IP e **prender a promoção inteira** por 15 minutos, repetindo depois. Não reproduzido aqui (todo o teste sai de um IP só).

**Correção proposta (escolher):**
1. contar IPv6 pelo prefixo /64 nos limites;
2. limite total de números reservados **sem conta** por promoção (ex.: 30% da promoção);
3. exigir conta (com e-mail confirmado) acima de N números por pedido.

Recomendado: 1 + 2.

### M3 — Manual: passo de "zerar os dados de teste" não funciona em produção (média, confirmada)

O capítulo 7 do manual manda rodar `php artisan migrate:fresh --force`. Com `APP_ENV=production`, o sistema bloqueia comandos destrutivos (`DB::prohibitDestructiveCommands`). O comando **só mostra um aviso e termina com sucesso**, sem apagar nada. Quem seguir o manual acharia que zerou o banco, e os pedidos do sandbox continuariam lá.

**Correção proposta:** trocar o passo por: apagar as tabelas pelo phpMyAdmin do cPanel (ou excluir e recriar o banco com o mesmo nome e usuário) e depois `php artisan migrate --force` e `app:create-admin`. Gerar o PDF de novo.

### B1 — Erros 500 com campos enviados como lista (baixa, confirmada)

| Requisição | Origem |
|---|---|
| `GET /pedido/{c}?t[]=x` | `OrderController.php:32` (`(string)` em array) |
| `POST /login` com `email[]=x` | `FortifyServiceProvider.php:117` (limitador de login) |
| `GET /reset-password/{token}?email[]=x` | `resources/views/pages/auth/reset-password.blade.php` |

Nada vaza (página de erro genérica), mas cada erro grava o rastreamento completo no log. O `GET /reset-password` não tem limite de tentativas: um robô pode **encher o disco** da hospedagem com logs.

**Correção proposta:** tratar o valor como texto só quando for texto (`is_string`) nos três pontos. Um teste para cada um.

### B2 — Webhook com campos em formato inesperado (baixa)

Com o **token válido**, `id` ou `event` em lista e `payment.id` com mais de 100 caracteres geram erro 500. Só o Asaas (ou quem tiver o token) consegue enviar. Corrigir junto com B1 (converter com segurança e cortar o tamanho).

### B3 — Peso da consulta da grade em promoções grandes (baixa)

Com 60 mil números vendidos, cada consulta da grade devolve **341 KB** e leva ~2 s quando o cache expira. Cada visitante consulta a cada 5 s: 100 visitantes ≈ 6,8 MB/s. A resposta não é comprimida (`zlib.output_compression = Off` e o `.htaccess` não liga `mod_deflate`).

**Correção proposta:** ligar a compressão para JSON/HTML/CSS/JS no `public/.htaccess` (lista de números comprime bem). Se ainda pesar, enviar faixas (`1-500`) em vez de cada número.

### B4 — Limites por IP compartilhado (baixa)

Operadoras móveis põem muitos clientes atrás do mesmo IP. Hoje:

- grade: 60 consultas/min por IP. Uma aba gasta 12/min, então **5 abas no mesmo IP já estouram**;
- login, cadastro e recuperação de senha: 10/min por IP;
- 3 pedidos abertos por promoção por IP para quem não tem conta.

Num lançamento concorrido, pessoas diferentes podem ser barradas.

**Correção proposta:** subir o limite da grade (a resposta já fica 3 s em cache, o custo é baixo) para ~300/min e acompanhar os 429 nos logs depois do lançamento.

### B5 — Confirmar se há proxy na frente do site na HostGator (verificação)

Se a HostGator tiver um proxy na frente, todos os visitantes aparecem com o IP do proxy e **todos os limites por IP viram limites globais**. Depois do deploy, conferir o IP registrado num login na Auditoria. Se for o do proxy, configurar `trustProxies` só para o IP dele.

### Informativos

- **I1:** `dragon-code/support` e `dragon-code/pretty-array` estão abandonados. Vêm do `laravel-lang` (traduções). Sem vulnerabilidade conhecida; acompanhar.
- **I2:** os arquivos enviados no painel ganham nome aleatório e extensão pelo **conteúdo** (`hashName`/`guessExtension`), então um `.php` disfarçado de imagem vira `.jpg`. Como defesa extra, bloquear a execução de PHP em `public/storage` com um `.htaccess`.
- **I3:** a cobrança é criada no Asaas com o pedido travado no banco (timeout 20 s). Se o Asaas ficar lento, aquele pedido espera; os outros não são afetados. Aceitável.
- **I4:** a carga local (4 processos PHP de 1 thread, sem OPcache) fez ~10 req/s, sem nenhum erro. **Não representa a HostGator.** Repetir uma carga leve no servidor durante o teste no sandbox.

## O que passou

| Área | Verificado |
|---|---|
| Acesso a pedidos | Estranho e outro usuário logado recebem 404 em ver, situação, comprovante, PDF, cancelar, pagar, alterar dados e trocar forma; link secreto inválido → 404 |
| Painel | Participante e anônimo barrados em todas as telas de `/admin` |
| CSRF | Reserva e login sem token → 419 |
| Atribuição em massa | Cadastro ignora `role`/`email_verified_at`; dados do pedido ignoram `status`, `total_cents`, `user_id`, `receipt_code`; preço sempre calculado no servidor |
| Validação da reserva | Fora da faixa, zero, negativo, texto, decimal, enorme, vazio, 51 números, objeto, nulo, chave inválida → 422; repetidos são unificados |
| Promoções fechadas | Rascunho, em breve, pausada, encerrada (botão e data), resultado publicado, cancelada e faixa vendida fora do site: sem reserva |
| Arquivos | `.env`, `composer.json`, travessia de pasta, `/storage` sem assinatura (GET e PUT): nada exposto |
| XSS | Nome do comprador com `<script>`/`onerror` escapado na página do pedido, comprovante, verificação pública e PDF; textos em Markdown removem HTML e links inseguros |
| Concorrência | 60 pessoas disputando 91 números em 4 servidores: nenhum número com dois donos, nenhum 5xx; duplo clique (8×) cria um pedido; 10 cliques em "Gerar Pix" criam uma cobrança; cancelar × pagar não deixa cobrança aberta; 10 confirmações simultâneas geram um e-mail só; 40 compras simultâneas sem duplicidade |
| Webhook | Sem token, token errado ou vazio → 401; aviso "pago" falso não confirma (consulta o provedor); repetido aceito uma vez; JSON inválido, vazio e 2 MB sem erro; pagamento real confirma; contestação → em análise; estorno → reembolsado e comprovante inválido |
| Pagamento atrasado | Número tomado por outro → pedido em análise; número continua com um dono só |
| Limites | Login bloqueia após 5 erros (inclusive a senha certa logo depois); consulta de pedido, cadastro, recuperação de senha, reservas e grade limitados; `X-Forwarded-For` não burla |
| Sessão | Cookie `HttpOnly` + `SameSite=Lax`; sessão trocada no login; cookie antigo não serve; logout encerra; conta desativada é derrubada e não entra; e-mail não confirmado não abre Minhas compras; trocar o e-mail da conta exige nova confirmação |
| Dados pessoais | CPF e token do link secreto criptografados no banco; CPF nunca no log; verificação pública sem e-mail; página de erro sem detalhes técnicos; consulta de pedido responde igual exista ou não |
| Promoções gigantes | 100 mil e 1 milhão de números: grade (34 KB) abre em ~0,3 s e a reserva de 50 números funciona; painel com 60 mil itens vendidos abre em < 0,6 s |
| Dependências | `composer audit` e `npm audit`: nenhuma vulnerabilidade conhecida |

## Fora do escopo

- Ataques de rede ao servidor da HostGator (firewall, TLS, outros sites do cPanel).
- Fluxos internos do painel via Livewire (as permissões foram verificadas pelas Policies e pelo acesso às telas).
- Verificação em duas etapas e recuperação de senha de ponta a ponta com e-mail real (ficam para o teste no sandbox na HostGator).
- Cabeçalhos de segurança do `.htaccess` (o servidor de teste local não usa Apache). Conferir no servidor após o deploy.

## Situação das correções (13/10/2026)

| Achado | Situação | O que mudou | Teste de regressão |
|---|---|---|---|
| M1 | Corrigido | `OrderController::identify` confere e grava com o pedido travado (`lockForUpdate`), como a geração da cobrança | `CheckoutFlowTest::test_identification_checks_open_charges_with_the_order_locked` |
| M2 | Corrigido | `App\Support\ClientKey`: IPv6 conta pelo bloco /64 em todos os limites por IP e no limite de pedidos abertos; quem compra sem conta pode ter, somando todos, no máximo metade da promoção reservada (`ReserveNumbers::GUEST_RESERVED_SHARE`) | `ClientKeyTest`, `CheckoutFlowTest::test_ipv6_addresses_in_the_same_block_share_the_open_orders_limit`, `ReserveNumbersTest::test_guests_together_can_hold_at_most_half_of_the_promotion` |
| M3 | Corrigido | Manual, cap. 7 passo 1: tabelas apagadas pelo phpMyAdmin + `migrate --force`; aviso de que `migrate:fresh`/`db:wipe` são bloqueados; linha nova em Solução de problemas | — (documentação) |
| B1 | Corrigido | Link secreto, limitador do login e tela de redefinição de senha ignoram valores em lista | `CheckoutFlowTest::test_secret_link_grants_access_and_wrong_token_does_not`, `AuthenticationTest::test_email_sent_as_a_list_is_rejected_without_error`, `PasswordResetTest::test_reset_screen_ignores_an_email_sent_as_a_list` |
| B2 | Corrigido | Webhook converte com segurança `id`, `event` e `payment.id`; ID de cobrança maior que 100 caracteres é descartado | `PaymentsTest::test_webhook_with_unexpected_field_formats_is_stored_without_error` |
| B3 | Corrigido | `public/.htaccess` comprime HTML, CSS, JS e JSON (`mod_deflate`). Conferir no servidor: resposta com `Content-Encoding: gzip` | — (só Apache) |
| B4 | Corrigido | Situação da grade: 60 → 300 consultas/min por IP | — |
| B5 | Verificar depois do deploy | — | — |

**Comprovação:** os 7 testes novos falham com o código anterior e passam com o corrigido. Suíte completa: 191 testes PHP + 4 Playwright passando; Pint e PHPStan sem erros. As baterias de estresse foram repetidas nos 4 servidores: a corrida do M1 caiu de 6/6 para 0/6 e os erros 500 de B1/B2 não aparecem mais.
