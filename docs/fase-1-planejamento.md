# Fase 1 — Análise e planejamento

Status: **aprovado** em 2026-10-10.

---

## 1. Diagnóstico

### Repositório

O projeto está vazio. Só existe `CONTEXT.md` (a especificação). Não há stack, dependências ou funcionalidades anteriores a preservar.

### Ambiente de produção

| Item | Situação |
|---|---|
| Hospedagem | HostGator, plano M (compartilhada, cPanel) |
| Localização | Subdomínio no mesmo cPanel do site WordPress já publicado |
| PHP | 8.3 |
| Banco | MySQL 8.0 (Percona Server) |
| Cron | Disponível, intervalo mínimo de 1 minuto |
| SSH | Disponível, ainda sem chave cadastrada |
| Node.js / processos permanentes | Não disponíveis (hospedagem compartilhada) |
| Pagamento | Asaas (conta existente) |

### Decisões de produto já tomadas

- **Organizador único.** Não há marketplace nem divisão de pagamentos entre vários recebedores. Perfis: administrador e participante.
- **Sorteio fora da plataforma.** O sistema apenas registra e publica o resultado e a prova (número ganhador, data, link do vídeo ou ata).
- **Compra como convidado permitida.** O cadastro é opcional.
- **Código mantido pelo proprietário com ajuda do Claude.** Por isso a stack precisa ser simples de operar e com poucas peças.

---

## 2. Arquitetura recomendada

### Stack

| Camada | Escolha | Por quê |
|---|---|---|
| Linguagem/framework | **Laravel 13** (PHP 8.3) | Roda em hospedagem compartilhada; já traz autenticação, validação, CSRF, rate limiting, migrations, e-mail e agendador. Confirmar a versão estável na data de início. |
| Banco | **MySQL 8 (InnoDB, utf8mb4)** | Já disponível. Suporta transações, chaves estrangeiras, restrições únicas e `SELECT … FOR UPDATE`. |
| Autenticação e área do participante | **Starter kit Livewire oficial do Laravel** | Cadastro, login, recuperação de senha, confirmação de e-mail e perfil prontos e mantidos pela equipe do Laravel. |
| Painel administrativo | **Filament** | Entrega o que a seção 5 pede (layout próprio, menu lateral, dashboard, tabelas com pesquisa, filtros e paginação, formulários validados) com uma fração do código. É a dependência que mais reduz trabalho. |
| Grade de números | **Alpine.js** (já incluído com o Livewire) + endpoint JSON | Leve no navegador mesmo com milhares de números; não recarrega a grade inteira pelo servidor. |
| Estilo | **Tailwind CSS**, compilado no computador de desenvolvimento | O servidor não tem Node; os arquivos compilados são enviados no deploy. |
| PDF do comprovante | **barryvdh/laravel-dompdf** | PHP puro, funciona em hospedagem compartilhada. |
| Integração Asaas | **Cliente HTTP nativo do Laravel** | A API do Asaas é REST simples; um SDK de terceiros não compensa. |
| Testes | **PHPUnit** (unitários e integração, já incluído no Laravel) + **Playwright** (ponta a ponta) | Sem dependência extra para os testes; o Playwright já está disponível neste ambiente. |
| Ambiente local (Windows) | **PHP 8.3 via winget + MySQL 8.0 no Docker** | MySQL na mesma versão da HostGator, sem instalar banco no Windows. Ver README. |

**Deixado de fora de propósito:**

| O quê | Por quê |
|---|---|
| WebSocket | Não roda em hospedagem compartilhada. |
| Filas com worker permanente | Mesmo motivo. |
| Redis | Não disponível. |
| Pacote de permissões | Dois perfis cabem numa coluna `role` com Policies do Laravel. |
| Bibliotecas de imagem | Basta validar tamanho e tipo no envio. |

Quando um item fizer falta de verdade, ele entra.

### Organização no servidor

```
/home/<usuario>/
├── public_html/              ← WordPress (não é tocado)
└── promocoes/                ← aplicação Laravel (fora da pasta pública)
    ├── .env                  ← segredos, permissão 600
    └── public/               ← raiz do subdomínio aponta AQUI
```

O subdomínio (por exemplo `promocoes.seudominio.com.br`) aponta para `promocoes/public`. Assim, código e `.env` nunca ficam acessíveis pela web. O HTTPS vem do AutoSSL da HostGator.

### Organização do código

Laravel padrão, sem camadas extras. As regras de negócio críticas ficam em poucas classes de serviço com nomes explícitos:

- `ReserveNumbers`: reserva atômica (seção 4).
- `ExpireOrders`: expiração e liberação.
- `AsaasGateway`: única classe que conversa com o Asaas. É a interface que permite trocar de provedor no futuro.
- `ProcessPaymentEvent`: processamento idempotente dos webhooks.
- `ConfirmOrder`: confirmação, conversão dos números e emissão do comprovante.

Controllers e telas só chamam essas classes. Toda a lógica de dinheiro e concorrência fica em um só lugar, coberta por testes.

### Tarefas agendadas

Uma linha no cron do cPanel, a cada minuto: `php artisan schedule:run`.

**Nada que garante a corretude depende do cron.** Ele só faz limpeza e conciliação:

| Frequência | Tarefa |
|---|---|
| A cada 1 min | Expirar pedidos vencidos e cancelar as cobranças correspondentes no Asaas. |
| A cada 5 min | Reprocessar eventos de webhook que falharam e consultar no Asaas os pagamentos pendentes há muito tempo (cobre webhooks perdidos). |
| Diário | Backup do banco (`mysqldump`) para uma pasta fora da web, mantendo 7 dias. |

> Os termos de uso das hospedagens compartilhadas às vezes limitam a frequência do cron. Se a HostGator exigir intervalos maiores, o sistema continua correto, apenas a limpeza fica mais lenta.

---

## 3. Modelo de dados

Todos os valores em dinheiro ficam em **centavos (inteiro)**. Nunca usar ponto flutuante.

### Tabelas

**users**: `id`, `name`, `email` (único), `password`, `role` (`admin` | `participant`), `is_active`, `email_verified_at`, `phone` (opcional), timestamps.
- O campo `role` **nunca** é preenchido por dados vindos do formulário (proteção contra elevação de privilégio).
- O perfil "responsável por promoções" fica reservado: basta acrescentar um valor ao `role` se a plataforma virar marketplace.

**promotions**
- Identificação e conteúdo: `id`, `slug` (único), `title`, `summary`, `description`, `prize`, `category`.
- Preço e números: `unit_price_cents`, `number_start` (0 ou 1), `total_numbers`.
- Regras do pedido: `min_per_order`, `max_per_order`, `allow_random`, `reservation_minutes`, `payment_methods` (JSON: `pix`, `credit_card`).
- Datas e status: `starts_at`, `ends_at`, `status`.
- Textos: `rules`, `draw_info` (como será a apuração).
- Controle: `legal_reference` (autorização ou observação jurídica), `is_demo`.
- Resultado: `result_number`, `result_published_at`, `result_proof_url`, `result_notes`.
- Meta: `created_by`, timestamps.
- Índices: `(status, starts_at, ends_at)`, `category`.

**promotion_images**: `id`, `promotion_id`, `path`, `alt`, `position`.

**orders**
- Identificação: `id`, `public_code` (10 caracteres aleatórios, único, não sequencial), `access_token_hash`, `checkout_key` (único).
- Vínculos: `promotion_id`, `user_id` (opcional, pois convidado não tem conta).
- Comprador: `buyer_name`, `buyer_email`, `buyer_cpf` (**criptografado**), `buyer_phone`.
- Valores: `quantity`, `unit_price_cents`, `total_cents`.
- Status e prazos: `status`, `reserved_until`, `confirmed_at`, `expired_at`, `cancelled_at`.
- Comprovante: `receipt_code` (único, gerado na confirmação).
- Timestamps.
- Índices: `(promotion_id, status)`, `(status, reserved_until)`, `user_id`, `buyer_email`.

**order_items**: `id`, `order_id`, `promotion_id`, `number`, `active` (`1` ou `NULL`).
- **Restrição única `(promotion_id, number, active)`.** É a garantia contra venda duplicada (detalhes na seção 4).
- Índice: `order_id`.

**payments**
- Vínculo e identificação: `id`, `order_id`, `provider` (`asaas`), `provider_payment_id` (único), `method` (`pix` | `credit_card`).
- Valor e status: `amount_cents`, `status` (normalizado), `provider_status` (original do Asaas).
- Dados da cobrança: `pix_payload` (copia e cola), `invoice_url`, `due_at`.
- Datas: `paid_at`, `refunded_at`, timestamps.

**payment_events**: `id`, `provider`, `provider_event_id` (**único**, garante idempotência), `event_type`, `provider_payment_id`, `payload` (JSON), `received_at`, `processed_at`, `error`.

**audit_logs**: `id`, `user_id`, `action`, `subject_type`, `subject_id`, `changes` (JSON), `ip`, `created_at`.

Também entram as tabelas padrão do Laravel: `sessions`, `cache`, `password_reset_tokens`.

### Simplificações em relação à lista da especificação

| Entidade sugerida | Decisão | Motivo |
|---|---|---|
| Roles / Permissions | Coluna `role` + Policies | Só há dois perfis. Tabelas próprias entram se surgir permissão por usuário. |
| PromotionNumbers | Não pré-gerar números | Só são gravados os números reservados ou vendidos (`order_items`). Os livres são "todos menos esses". Evita criar milhares de linhas por promoção. |
| NumberReservations | O próprio pedido é a reserva | `orders.reserved_until` + status. O estado da reserva é derivado do pedido (tabela na seção 5). Uma tabela a menos para manter em sincronia. |
| Receipts | Coluna `receipt_code` em `orders` | Um pedido tem no máximo um comprovante; o conteúdo é sempre lido do banco, nunca guardado à parte. |
| Notifications | Sem tabela | E-mails enviados direto; o envio fica no log. |
| Refunds | Campos em `payments` | O MVP só faz reembolso integral. Reembolso parcial justificaria uma tabela. |

Registros de pedidos e pagamentos **nunca são apagados** fisicamente: só mudam de status.

---

## 4. Reserva atômica e concorrência

### A garantia

`order_items` tem uma restrição única em `(promotion_id, number, active)`:
- `active = 1`: o número está preso a um pedido vivo (reservado ou vendido).
- `active = NULL`: o pedido expirou ou foi cancelado; a linha fica como histórico.

No MySQL, valores `NULL` não colidem em índice único. Por isso podem existir vários registros históricos do número 0012, mas **nunca dois ativos**. Quem impede a venda duplicada é o banco, não o código da aplicação. Isso vale mesmo com várias requisições chegando no mesmo milissegundo.

### Fluxo de reserva (`ReserveNumbers`)

1. **Validar a promoção:** está aberta e aceita os números pedidos. A quantidade respeita mínimo e máximo, e os números estão dentro do intervalo.
2. **Iniciar a transação.**
3. **Expiração preguiçosa:** pedidos vencidos que prendem algum dos números pedidos são marcados como `expired`, e seus itens recebem `active = NULL`.
4. **Inserir os itens** com `active = 1`, junto com o pedido (`awaiting_payment`, `reserved_until = agora + reservation_minutes`, preço copiado da promoção).
5. **Se o banco recusar por duplicidade:**
   - desfazer tudo (nada é reservado parcialmente);
   - consultar quais números estão ocupados;
   - devolver ao usuário a lista de conflitos para ele revisar a seleção.
6. **Confirmar a transação.**

**Outras garantias do fluxo:**

- **Preço e total:** sempre calculados no servidor. O navegador envia apenas a lista de números.
- **Clique duplo ou recarregamento:** a página de checkout gera um `checkout_key`, e `orders.checkout_key` é único. Repetir o envio devolve o mesmo pedido.
- **Números aleatórios:** o servidor sorteia entre os livres e tenta reservar. Em caso de conflito, tenta de novo com outros números (até um limite).

### Expiração

Uma reserva vencida é tratada como livre **no momento da consulta**: a grade ignora pedidos com `reserved_until` no passado, e a reserva faz a expiração preguiçosa. O cron apenas faz a limpeza e cancela a cobrança no Asaas. Se o cron atrasar, nada quebra.

Pedidos com pagamento **em processamento** (cartão em análise) não expiram automaticamente. Se passarem de um teto (por exemplo, 2 horas), vão para revisão manual.

### Atualização da grade

- O endpoint `GET /promocoes/{slug}/numeros` devolve a lista compacta de números reservados e vendidos, com cache de 3 segundos.
- A tela consulta esse endpoint a cada 5 segundos e mostra "atualizado há X s".
- Quando a conexão cai, a tela tenta de novo e avisa que os dados podem estar desatualizados.
- Em promoções grandes, a grade é paginada por faixas (0001–0500, 0501–1000…). Há filtros "só disponíveis" e "meus selecionados", além de busca por número.

A grade é apenas informativa: a decisão final é sempre da reserva no servidor.

### Teste de concorrência (obrigatório)

Duas formas complementares:

1. **Teste automatizado com PHPUnit no MySQL real** (não SQLite): dois pedidos para o mesmo número. O segundo recebe conflito, e o banco termina com exatamente uma linha ativa.
2. **Script de carga local:** dispara dezenas de tentativas simultâneas de reservar os mesmos números, usando vários processos PHP em paralelo, cada um com sua própria conexão ao MySQL (o servidor embutido do PHP no Windows atende uma requisição por vez). Deve sair exatamente um vencedor por número, e nenhum número com dois itens ativos.

---

## 5. Máquinas de estado

### Promoção

Só alguns estados são gravados no banco; os demais são calculados pela data. Assim, a abertura e o encerramento automáticos não dependem do cron.

| Gravado | Condição | Estado exibido |
|---|---|---|
| `draft` | — | Rascunho |
| `published` | agora < `starts_at` | Agendada |
| `published` | `starts_at` ≤ agora < `ends_at` | Aberta |
| `published` | agora ≥ `ends_at` | Encerrada |
| `paused` | — | Pausada |
| `closed` | encerramento manual | Encerrada |
| `drawn` | resultado registrado | Resultado publicado |
| `cancelled` | — | Cancelada |

| Transição | Regra |
|---|---|
| `draft` → `published` | Campos obrigatórios completos. Em produção, exige `legal_reference` preenchido e `is_demo = false`. |
| `published` ↔ `paused` | Pausada não aceita novas reservas nem gera novas cobranças; Pix já gerado e pago dentro do prazo da reserva vale. |
| `published`/`paused` → `closed` | Para novas reservas. |
| Encerrada → `drawn` | Só com **nenhum pedido aguardando ou em revisão**; o painel avisa quais estão pendentes. |
| Qualquer estado (exceto `drawn`) → `cancelled` | Pedidos confirmados vão para fila de reembolso. |

**Depois da primeira venda confirmada, não podem mudar:** preço, quantidade de números, início da numeração e formas de pagamento. Mudanças em textos são permitidas e ficam registradas no log de auditoria.

### Pedido

O estado do pedido é separado do estado do pagamento.

| Estado | Significado |
|---|---|
| `awaiting_payment` | Números reservados, aguardando pagamento |
| `processing` | Pagamento informado pelo provedor, ainda não confirmado (cartão em análise) |
| `confirmed` | Pago e confirmado; números vendidos; comprovante emitido |
| `expired` | Prazo da reserva passou sem pagamento; números liberados |
| `cancelled` | Cancelado pelo comprador ou pelo administrador; números liberados |
| `refunded` | Reembolsado; números **não** voltam automaticamente para venda |
| `needs_review` | Situação que exige decisão humana (ex.: pagamento chegou depois de os números serem vendidos a outra pessoa) |

| De | Para | Gatilho |
|---|---|---|
| `awaiting_payment` | `processing` | Webhook do Asaas indicando análise |
| `awaiting_payment` / `processing` | `confirmed` | Pagamento confirmado e validado |
| `awaiting_payment` | `expired` | `reserved_until` vencido |
| `awaiting_payment` | `cancelled` | Comprador ou administrador |
| `expired` / `cancelled` | `confirmed` | Pagamento tardio **e** os números continuam livres (são reativados) |
| `expired` / `cancelled` | `needs_review` | Pagamento tardio e algum número já foi vendido a outra pessoa |
| `processing` | `needs_review` | Passou do teto de tempo |
| `confirmed` | `refunded` | Reembolso feito pelo administrador |
| `confirmed` | `needs_review` | Contestação (chargeback) |

"Pagamento recusado" não é um estado do pedido. A recusa fica registrada no pagamento, e o pedido continua aguardando, permitindo nova tentativa até a reserva vencer.

### Reserva (derivada do pedido)

| Pedido | Reserva |
|---|---|
| `awaiting_payment` / `processing` dentro do prazo | Ativa |
| `confirmed` | Convertida em venda |
| `expired` | Expirada |
| `cancelled` | Liberada |

### Pagamento

| Estado | Equivale no Asaas |
|---|---|
| `pending` | PENDING, AWAITING_RISK_ANALYSIS |
| `confirmed` | RECEIVED, CONFIRMED |
| `failed` | Recusa de cartão |
| `cancelled` | Cobrança removida ou vencida (DELETED, OVERDUE) |
| `refunded` | REFUNDED |
| `disputed` | CHARGEBACK_REQUESTED, CHARGEBACK_DISPUTE |

Um pedido pode ter vários pagamentos (por exemplo, cartão recusado e depois Pix), mas apenas um pendente por vez.

### Situações especiais previstas

| Situação | Tratamento |
|---|---|
| Webhook repetido | `payment_events.provider_event_id` é único; o segundo é ignorado e responde 200. |
| Webhook fora de ordem | O status só avança; um evento "pendente" depois de um "recebido" é ignorado. |
| Pagamento após a expiração | Tenta reativar os números. Se algum foi vendido a outra pessoa, o pedido vai para `needs_review`: o administrador reembolsa ou oferece outros números com o aceite do comprador. **Nunca há troca automática.** |
| Pedido criado, cobrança não gerada | O pedido fica aguardando com o botão "gerar pagamento"; a reserva expira normalmente. |
| Asaas fora do ar | Mensagem amigável; nova tentativa possível; a conciliação consulta o status depois. |
| Promoção encerrada com pagamentos pendentes | Nenhuma cobrança nova; Pix já gerado e pago dentro do prazo vale. Pagamento depois do prazo vai para análise. O resultado só pode ser registrado depois de todos resolvidos. |

---

## 6. Pagamentos (Asaas)

### Fluxo

1. **Cliente no Asaas:** buscar pelo CPF ou criar. O Asaas exige CPF para gerar cobrança, por isso o checkout pede CPF, com validação dos dígitos.
2. **Criar a cobrança** com `externalReference = public_code` do pedido.
   - **Pix:** mostrar QR Code, copia e cola, valor, prazo e botão de copiar.
   - **Cartão:** redirecionar para a **fatura hospedada do Asaas** (`invoiceUrl`). Os dados do cartão nunca passam pela plataforma, e a autenticação adicional (3DS) fica com o Asaas.
3. **Acompanhar o status:** a página do pedido consulta o status no **nosso** servidor a cada poucos segundos.
4. **Webhook `POST /webhooks/asaas`:**
   - valida o token do cabeçalho `asaas-access-token` com comparação segura;
   - grava o evento e responde 200 imediatamente;
   - processa o evento.
5. **Processamento (`ProcessPaymentEvent`):**
   - **busca a cobrança direto na API do Asaas.** O conteúdo do webhook não é tratado como verdade, só como aviso;
   - trava o pedido (`FOR UPDATE`);
   - confere valor, `externalReference` e ID externo;
   - aplica a transição permitida.

### Detalhes importantes do Asaas

- **A fila de webhooks é pausada por erros.** O Asaas interrompe a fila depois de muitas respostas com erro. Por isso o endpoint grava o evento e sempre responde 200; falhas de processamento são reprocessadas pelo cron.
- **Vencimento do Pix.** A cobrança Pix do Asaas tem vencimento por **data**, não por minutos (confirmar na documentação). Por isso, quando a reserva expira, o sistema **cancela a cobrança no Asaas**. Se mesmo assim chegar um pagamento, entra no fluxo de pagamento tardio.
- **Ambientes separados.** Sandbox (`sandbox.asaas.com`) em desenvolvimento e produção em produção, escolhidos pelo `.env`. Em produção não existe nenhum caminho que aprove pagamento sem o Asaas.
- **Cartão de débito.** Verificar se o Asaas oferece débito online para a conta. Se não oferecer, o MVP sai com Pix e crédito.

---

## 7. Compra como convidado, comprovante e privacidade

### Convidado

- Informa nome, e-mail, CPF e telefone (opcional).
- Ao criar o pedido, recebe um **link secreto** `/pedido/{codigo}?t={token}` na tela e por e-mail. O banco guarda apenas o hash do token.
- **"Consultar pedido":** o usuário informa o código do pedido e o e-mail. O sistema **envia o link por e-mail**, em vez de mostrar o pedido na tela. Assim ninguém consulta pedidos alheios por tentativa e erro, e há limite de tentativas.
- Quem criar conta e confirmar o e-mail passa a ver na área pessoal os pedidos anteriores feitos com aquele e-mail.

### Comprovante

- **Gerado na confirmação:** recebe um `receipt_code` aleatório.
- **Página pública `/comprovante/{codigo}`:** lê **sempre do banco** e mostra:
  - promoção, responsável e pedido;
  - números confirmados, quantidade, valores e forma de pagamento;
  - status atual e data e hora da confirmação;
  - nome do comprador mascarado (ex.: "Mich\*\*\* Z.").
  - Não aparecem CPF, e-mail nem telefone.
- **PDF:** gerado sob demanda a partir dos mesmos dados; não fica guardado.
- **Por que não dá para falsificar:** alterar o PDF ou o HTML não muda o que a página de verificação mostra. Se o pedido for reembolsado, a verificação passa a exibir "reembolsado".
- **QR Code:** o QR com o link de verificação fica para a Fase 7, já que a especificação o trata como opcional.

### LGPD

- O CPF é **criptografado** no banco (cast `encrypted` do Laravel) e nunca vai para logs.
- Os logs registram códigos de pedido, nunca dados pessoais.
- A política de retenção e a política de privacidade devem ser definidas com apoio jurídico; o sistema deixa esses textos configuráveis.

---

## 8. Segurança

| Requisito | Como |
|---|---|
| SQL injection | Eloquent / query builder (consultas parametrizadas) |
| XSS | Escape padrão do Blade; descrição da promoção sanitizada (só formatação básica) |
| CSRF | Middleware padrão do Laravel (o webhook fica isento e é validado por token) |
| Controle de acesso | Policies no servidor; `role` fora do mass assignment; painel restrito a `admin` |
| Dados de outros usuários | Pedido acessível só pelo dono, pelo link com token ou pelo admin |
| Enumeração de pedidos | Códigos aleatórios + token + consulta por e-mail |
| Rate limiting | Login, cadastro, recuperação de senha, reserva, checkout, consulta de pedido |
| Sessão | Cookies `Secure`, `HttpOnly`, `SameSite=Lax`; regeneração no login |
| Segredos | Apenas no `.env` (fora da pasta pública, permissão 600); `.env.example` sem valores reais |
| Auditoria | `audit_logs` para ações administrativas, mudanças de promoção, reembolsos e resolução de `needs_review` |

**Risco específico desta hospedagem: o WordPress no mesmo cPanel.**

O WordPress e o Laravel rodam com o mesmo usuário do sistema. Um plugin vulnerável do WordPress permitiria ler o `.env`, incluindo a chave da API do Asaas. Mitigações:

- manter o WordPress e os plugins atualizados e remover plugins sem uso;
- usar usuário MySQL separado, com acesso só ao banco da aplicação;
- ativar autenticação em dois fatores no cPanel e no Asaas;
- **verificar no Asaas** se é possível restringir a chave de API (bloquear transferências e saques via API ou limitar por IP).

---

## 9. Riscos principais

| # | Risco | Impacto | Mitigação |
|---|---|---|---|
| 1 | **Legalidade do modelo** (rifa paga com prêmio) | Bloqueante para produção | Validação jurídica antes de publicar. O sistema exige `legal_reference` para publicar em produção. |
| 2 | **O Asaas aceitar a atividade**, além das formas de pagamento | Conta bloqueada, saldo retido | Confirmar **por escrito** com o Asaas que "ações entre amigos/rifas" são permitidas na conta. |
| 3 | WordPress comprometido expõe segredos | Perda financeira | Mitigações da seção 8. |
| 4 | Pagamento Pix após a expiração | Conflito de números | Cancelar a cobrança na expiração + estado `needs_review` + reembolso. |
| 5 | Limites da hospedagem compartilhada em picos (lançamento de campanha) | Lentidão ou erros | Cache de 3 s no status da grade, respostas pequenas, página pública leve. Avaliar VPS se o volume crescer. |
| 6 | Webhooks perdidos ou fila pausada pelo Asaas | Pedido pago aparecendo como pendente | Sempre responder 200 + conciliação a cada 5 min. |
| 7 | Contestações de cartão | Prejuízo e risco para a conta | Pix como método principal; cartão opcional por promoção. |
| 8 | E-mails caindo no spam (SMTP da HostGator) | Comprador não recebe o link do pedido | Configurar SPF e DKIM no cPanel; o link sempre aparece também na tela. |
| 9 | Backups insuficientes | Perda de dados | Backup diário + cópia externa periódica. |

---

## 10. Plano de execução

Uma mudança em relação à ordem da especificação: **banco e autenticação vêm antes das telas**. Assim as páginas já nascem lendo dados reais, sem telas preenchidas com dados de mentira que depois precisam ser refeitas. Cada fase termina com testes passando e uma demonstração.

| Fase | Entregas | Como verificar |
|---|---|---|
| **2 — Ambiente, banco e autenticação** | Laravel + MySQL no Docker; migrations de todas as tabelas; cadastro, login, recuperação de senha, confirmação de e-mail; perfis e Policies; seeder de demonstração (promoções marcadas como **FICTÍCIA**; senhas geradas na hora e exibidas só no terminal); `.env.example`; README de instalação | Testes de autenticação, de acesso negado entre perfis e de que `role` não é alterável pelo formulário |
| **3 — Sistema de design e páginas públicas** | Cores, tipografia, espaçamentos e componentes; página inicial (cabeçalho, seção principal, cards, filtros, busca, "como funciona", rodapé); detalhe da promoção; estados de carregamento, vazio e erro | Validação em celular, tablet e desktop; navegação por teclado; contraste |
| **4 — Painel administrativo e promoções** | Filament: dashboard, usuários, promoções (criar, editar, duplicar, pré-visualizar, publicar, pausar, encerrar, registrar resultado), log de auditoria | Testes das regras de publicação e de campos bloqueados após a primeira venda |
| **5 — Seleção, reserva e checkout** | Grade de números, seleção múltipla, aleatório, busca, filtros; `ReserveNumbers`; expiração; checkout em etapas; compra como convidado | **Testes de concorrência** (seção 4), cálculo de valores, expiração |
| **6 — Pagamentos Asaas** | `AsaasGateway`; Pix e cartão (fatura hospedada); webhook; conciliação; cancelamento na expiração | Testes com respostas simuladas do Asaas (webhooks repetidos, fora de ordem, recusa, pagamento tardio) + teste real no sandbox |
| **7 — Comprovantes e área do participante** | Confirmação, comprovante (página + PDF + QR), verificação pública, histórico de pedidos, consulta por convidado, e-mails | Testes do conteúdo do comprovante e de que a verificação pública não mostra dados pessoais |
| **8 — Produção** | Deploy na HostGator (SSH, subdomínio, cron, SSL); backup; revisão de segurança e acessibilidade; testes E2E com Playwright; checklist de publicação (incluindo jurídico e Asaas) | Execução completa no sandbox dentro da HostGator antes de ativar o Asaas de produção |

---

## 11. Decisões para aprovar

1. Stack: Laravel + MySQL + Livewire (autenticação) + **Filament (admin)** + Alpine + Tailwind.
2. Sem tabela de reservas nem pré-geração de números: a restrição única em `order_items` garante a exclusividade.
3. Grade atualizada por consulta a cada 5 s (sem WebSocket).
4. Cartão pela fatura hospedada do Asaas (sem dados de cartão na plataforma).
5. Estados de promoção derivados das datas (sem depender do cron).
6. Ordem das fases ajustada (banco e autenticação antes das telas).

## 12. Informações que ainda faltam

Nenhuma bloqueia o início da Fase 2. Têm valores padrão sugeridos entre parênteses.

| Informação | Padrão sugerido |
|---|---|
| Nome do subdomínio | A definir |
| Nome e marca da plataforma (logotipo, cores) | Identidade provisória neutra, se ainda não houver |
| Prazo padrão da reserva | 15 minutos para Pix |
| Limite de números por pedido | Mínimo 1, máximo 100 |
| Quantidade máxima de números por promoção | 100.000 |
| Dados do responsável exibidos nas páginas e no comprovante | Nome e contato; CPF/CNPJ só se o jurídico indicar |
| Textos legais (termos, privacidade, reembolso) | Ficam configuráveis no painel, com aviso "pendente de revisão jurídica" |
