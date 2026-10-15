# Progresso do projeto

Atualizado em 2026-10-15. Para retomar, leia este arquivo, o [README](../README.md), o manual ([fonte](manual/manual.html), [PDF](manual-promocoes321.pdf)) e o [plano da Fase 1](fase-1-planejamento.md).

## Onde paramos (2026-10-15)

**SSH liberado; falta o primeiro deploy.** Todo o código está na `main`, igual ao GitHub.

- **Repositório:** https://github.com/michaelzanolla/Promocoes321 (privado). Cada alteração nova começa numa branch a partir da `main` e entra por PR; o CI (`.github/workflows/tests.yml`, MySQL 8.0, `ubuntu-24.04`) roda testes, Pint e PHPStan.
- **Testes:** 195 PHP + 4 Playwright passando (o Playwright não roda no CI).
- **Servidor (conferido em 2026-10-15):** `ssh promocoes` abre o shell (jailshell). PHP 8.3 na linha de comando, git e `mysqldump` 8.0 presentes. Não há Composer no servidor e não precisa: o `deploy/deploy.sh` roda o Composer no PC. `~/promocoes/public` só tem os arquivos do cPanel; `~/malware.txt` é um relatório antigo da HostGator (fev/2026) sem nenhum arquivo infectado.

### Roteiro ao retomar

1. ~~Testar o acesso SSH~~ **Feito em 2026-10-15.**
2. ~~No cPanel~~ **Feito em 2026-10-12**: subdomínio com HTTPS válido (responde 403 até o primeiro deploy); PHP 8.3 e limites de upload (os arquivos gerados pelo cPanel estão em `public/` para o deploy não apagá-los); banco e usuário MySQL `jonesa50_promocoes`; `nao-responda@osrv.com.br` no **Titan** com SPF, DKIM e DMARC dando PASS no Gmail.
3. Criar `~/promocoes-shared/.env` a partir de `deploy/env.production.example` (Asaas em **sandbox**), com permissão 600, e guardar a `APP_KEY` fora da HostGator. O usuário precisa informar: senha do banco, senha do e-mail no Titan, chave do Asaas sandbox e o e-mail do administrador.
4. Primeiro deploy: `bash deploy/deploy.sh promocoes`; depois `php artisan app:create-admin` no servidor.
5. Cron (schedule:run a cada minuto e backup diário), webhook do Asaas, um teste completo no sandbox e a conferência de proxy e gzip (veja "Histórico da Fase 8"). Tudo está no README, seção Produção, e no manual, capítulo 6.
6. Preencher Painel > Configurações com os dados do responsável e do contato.
7. No final, antes de publicar: pendências da seção "Pendências" abaixo, incluindo **Segurança do login** e os itens jurídicos e do Asaas.

### Ambiente local

Docker Desktop aberto, `docker compose up -d` e `php artisan serve` (http://localhost:8000). O banco local tem promoções fictícias em todas as situações e um exemplo de ação iniciada fora do site. Para entrar no painel, crie seu administrador com `php artisan app:create-admin seu-email`.

## Histórico da Fase 8

Documentação, GitHub e textos legais (13 a 15/10/2026):

- Manual técnico e de uso do painel em PDF: fonte `docs/manual/manual.html`, gerado por `npm run manual` em `docs/manual-promocoes321.pdf`. Ao mudar configuração, deploy ou painel, atualizar o manual e gerar de novo.
- Repositório no GitHub com CI (PR #1: MySQL 8.0 nos testes; commit `5e712d8`: runner fixo em `ubuntu-24.04`).
- **Exportar compradores** (commit `4a7717f`): em Promoção › Mais ações, baixa um XLSX só com números pagos (pedidos confirmados e vendas fora do site), com as abas "Por comprador" e "Por número". Cada exportação fica na Auditoria. Não dá para repetir o cabeçalho na impressão: o openspout não põe aspas no nome da aba com espaço.
- Textos legais reescritos a partir do funcionamento do sistema (PRs #3 e #4), com a faixa "Versão para revisão jurídica". Rifei e Rifeme serviram só como lista de assuntos.

Teste de estresse e segurança (13/10/2026, relatório em [teste-de-estresse-2026-10-13.md](teste-de-estresse-2026-10-13.md)):

- Corrigidos: corrida entre confirmar dados e gerar Pix (pedido agora travado); IPv6 conta por bloco /64 nos limites; quem compra sem conta pode ter no máximo metade da promoção reservada; erros 500 com campos em lista (link do pedido, login, redefinição de senha, webhook); compressão no `.htaccess`; limite da grade 60 → 300/min por IP; passo do manual que usava `migrate:fresh` (bloqueado em produção).
- Falta: depois do deploy, conferir se há proxy na frente do site (IP registrado na Auditoria) e se as respostas saem com `Content-Encoding: gzip`.

Primeira parte da Fase 8 (commit `52af1a6`):

- PDF do comprovante conferido; ajustado o espaçamento de "Números confirmados".
- `deploy/deploy.sh` (deploy por SSH a partir do PC, sem Node/Composer no servidor; testada a montagem do pacote), `deploy/backup.sh` (mysqldump diário, 7 dias) e `deploy/env.production.example`.
- `public/.htaccess`: redireciona para HTTPS (exceto `.well-known`) e envia cabeçalhos de segurança.
- README: roteiro de produção (SSH, cPanel, `.env`, deploy, rollback, cron, backup, webhook) e checklist de publicação.
- Chave SSH gerada (`~/.ssh/hostgator`) e cadastrada no cPanel; `~/.ssh/config` com Host `promocoes` (promocoes.osrv.com.br, usuário jonesa50, porta 2222). A autenticação funciona, mas o shell está desativado na conta: **chamado aberto na HostGator** para ativar o SSH (e confirmar porta, PHP 8.3 da linha de comando e `mysqldump`).
- Revisão de segurança: upload de imagem não aceita mais SVG (podia levar script); cache de configuração com permissão 600 no deploy; README orienta guardar a `APP_KEY` fora da HostGator (sem ela, os CPFs do backup não são recuperáveis). `composer audit` limpo.
- Revisão de acessibilidade (axe-core, WCAG 2.1 AA, todas as páginas públicas): login/cadastro ganharam `<main>`; formulários do pedido e de consulta marcam campos com erro (`aria-invalid`) e mostram nomes de campo em português; grade de números com uma parada só no Tab e navegação por setas/Home/End. Sem rolagem horizontal em 375 px.
- Dependências npm: `overrides` força `tinypool` 2.1.2 (vulnerabilidade crítica no 2.1.0, usado pelo `vite-plus` 0.3.0). A 0.3.3 do `vite-plus` exige migrar o Vite para o núcleo dele (`vp migrate`); ao fazer isso, remover o `overrides`. `npm audit`: 0.
- Testes ponta a ponta com Playwright (`npm run test:e2e`, 4 testes): compra até os dados confirmados e desistência, erros do formulário, disputa do mesmo número e teclado na grade. Acharam um bug da Fase 3: o cartão da página inicial levava para `/promocoes/{id}` (404); corrigido e coberto também no PHPUnit.

Exemplos em todas as situações e correções (commit `be19213`):

- Página inicial: destaque do título agora é "Concorra a prêmios." (`config/platform.php`; editável em Painel > Configurações).
- `StatusExamplesSeeder` (roda junto com `db:seed`): uma promoção fictícia em cada situação (rascunho, em breve, aberta, pausada, encerrada pelo admin e pela data, resultado publicado, cancelada) e pedidos em todas as situações, criados pelos próprios serviços do sistema. Para só acrescentar ao banco local: `php artisan db:seed --class=StatusExamplesSeeder`.
- Bugs corrigidos com teste: (1) cartão em análise de risco não segurava a reserva (o pedido não ia para "em processamento" e podia expirar no meio da análise); (2) cancelar a promoção não cancelava as reservas abertas, que ainda podiam ser pagas; (3) pagamento atrasado reativava números com a promoção pausada, encerrada ou sorteada, e pagamento em promoção cancelada era confirmado (agora vão para "em análise"); (4) comprador de promoção cancelada não era avisado e o comprovante seguia "válido"; (5) promoções com resultado publicado ou canceladas não abriam no painel (agora abrem só para leitura).
- Exemplos estáveis: a reserva "aguardando pagamento" do exemplo vale 1 ano, "Em breve" começa daqui a 1 ano e as abertas e a pausada vão até daqui a 1 ano, para nenhuma mudar de situação sozinha. Os testes usam o banco `promocoes_test`; o banco local (`promocoes`) nunca é apagado por eles.
- Comprovação do sorteio: além do link (gravação da live, publicação), o administrador pode anexar um arquivo (foto, print ou PDF da ata, até 5 MB), exibido na página pública da promoção. Nada cita mais a Loteria Federal; o exemplo usa uma ata fictícia em PDF. Links só `http`/`https`. No php.ini local, `upload_max_filesize` foi para 8M e `post_max_size` para 10M (cópia do original em `php.ini.bak-upload`).
- Página da promoção: a frase sobre números reservados só aparece com a promoção aberta ou pausada e não diz mais "aguardando pagamento" para pedidos reembolsados ou em análise.
- Regra nova: com a promoção pausada ou encerrada, nenhuma cobrança nova é gerada (a página do pedido avisa; Pix já gerado e pago dentro do prazo continua valendo). Avisos do painel e plano da Fase 1 atualizados.
- Testes novos: exemplo de toda situação existe; todas as telas abrem em todas as situações; o comprador não consegue cancelar, pagar ou alterar dados fora de "aguardando pagamento".

Redefinição de senha nas boas práticas (commit `5820197`):

- O e-mail vem do link e fica travado (somente leitura, com aparência própria). O servidor já recusava trocar a senha de outro e-mail com o link: agora há teste para isso.
- "Esqueci minha senha" responde igual exista ou não a conta (antes dizia "Não existe nenhum usuário com o e-mail indicado").
- Redefinir a senha encerra as sessões abertas em outros aparelhos.

Ação que começou fora do site (commit `5820197`):

- Promoção tem a numeração da ação inteira (`series_start`/`series_end`, ex.: 0001 a 1000) e a faixa vendida no site (`number_start`/`total_numbers`, ex.: 0501 a 1000). A tela de criação pede primeiro e último número e "Vender no site: todos | só uma faixa". Zeros à esquerda seguem a ação inteira. O site mostra só a sua faixa.
- Painel > promoção > "Números vendidos fora do site": marca números da faixa do site (ex.: `507, 512, 600-650`) com anotação interna. Usa a mesma trava da reserva (sem conflito com o site); se algum já estiver reservado/vendido, nada é marcado. Contam como vendidos; não entram como pedidos nem no financeiro. Desfazer: Pedidos > origem "Fora do site" > "Desfazer marcação" (só antes do encerramento).
- Sorteio aceita qualquer número da ação; se for vendido fora, a página diz "Este número foi vendido fora do site".
- Auditoria registra logins: entrada (com "lembrar de mim"), senha errada (só o e-mail, nunca a senha), conta desativada, código de dois fatores errado e bloqueio por excesso de tentativas (no máximo 1 registro por minuto por e-mail+IP). O bloqueio agora mostra aviso na tela de login em vez da página de erro 429.
- Coluna `number_start` ampliada (antes aceitava só até 255). Exemplo novo: "[FICTÍCIA] Iniciada fora do site: moto".

## Situação das fases

| Fase | Situação | Último commit da fase |
|---|---|---|
| 1 — Análise e planejamento | Concluída | `a5e780c` |
| 2 — Ambiente, banco e autenticação | Concluída | `e5c6744` |
| 3 — Sistema de design e páginas públicas | Concluída | `3c0086f` |
| 4 — Painel administrativo (Filament) | Concluída | `d0c6fb3` |
| 5 — Seleção de números, reserva e checkout | Concluída | `72ed127` |
| 6 — Pagamentos (Asaas: Pix e cartão) | Concluída | `666eca8` |
| 7 — Comprovantes, área do participante, e-mails | Concluída | `d24e663` |
| 8 — Preparação para produção | **Em andamento** (esperando o SSH da HostGator) | `41d1404` (até agora) |

As fases foram feitas em branches empilhadas, uma a partir da outra, e integradas à `main` em 2026-10-12. Para ver o código ao fim de uma fase: `git switch --detach COMMIT`.

Situação dos testes ao final da Fase 7: 144 testes passando, Pint e PHPStan sem erros.

## Pendências das fases concluídas

### Bloqueantes para publicar (não são código)

- [ ] **Validação jurídica** do modelo de promoção (rifa/ação entre amigos, autorização, publicidade). Em produção, o sistema exige a "referência legal" para publicar.
- [ ] **Confirmar por escrito com o Asaas** que a atividade (rifa/ação entre amigos) é aceita na conta. A pergunta já feita confirmou as formas de pagamento, não a atividade.
- [ ] **Revisão jurídica dos textos legais** (termos, privacidade, cancelamento e reembolso, regras em `resources/content/*.md`): reescritos em 15/10/2026 a partir do funcionamento real do sistema (o Rifei serviu só de lista de assuntos), com a faixa "Versão para revisão jurídica" no topo; tirar a faixa depois da revisão. Pontos que o responsável e o advogado precisam confirmar:
  - arrependimento de 7 dias só até o sorteio (cancelamento, item 2);
  - pedir o reembolso ao Asaas em até 5 dias úteis (cancelamento, item 4);
  - guardar pedidos e comprovantes por 5 anos após o encerramento e responder pedidos de titulares em 15 dias (privacidade, itens 5 e 6). **O sistema ainda não apaga nem anonimiza nada sozinho**: criar a rotina antes de vencer o primeiro prazo;
  - nome do ganhador só divulgado com a concordância dele (privacidade, item 3);
  - foro do domicílio do participante (termos, item 11).
- [ ] **Dados do responsável e contato** reais em Painel > Configurações.

### Segurança do login (validar no final, antes de publicar)

- [ ] Decidir os reforços contra ataque de senha distribuído (muitos IPs, poucas tentativas cada), que hoje passa pelos limites por IP. Opções: (1) limite por conta vindo de qualquer IP, ex.: 20 tentativas erradas por hora por e-mail (efeito colateral: atacante pode travar o login de uma conta por até 1 h); (2) dois fatores obrigatório para administradores; (3) CAPTCHA Cloudflare Turnstile (serviço externo, conta na Cloudflare). Recomendado: 1 e 2. Antes de decidir, olhar na Auditoria se há "Login recusado" ou "Login bloqueado" em volume.
- [x] "Esqueci minha senha": busca da conta, token e envio acontecem depois da resposta (`app/Http/Controllers/PasswordResetLinkController.php`); o tempo de resposta não revela mais se o e-mail tem conta.

### Identidade e conteúdo

- [ ] Nome e marca definitivos: "Promoções 321" e o logotipo de bilhete são provisórios (`APP_NAME`, `resources/views/components/app-logo-icon.blade.php`).
- [x] E-mails com as cores do site: faixa amarela, quadro do pedido e botão amarelos (`resources/views/vendor/mail/html/themes/default.css`). Logotipo em imagem fica para quando a marca for definitiva.

### Fase 4 — Painel

- [x] O painel usa a fonte Inter servida pelo próprio site (antes vinha do Bunny Fonts).

### Fase 5 — Reserva e checkout

- [x] `composer test` falha no `ConcurrencyTest` só no ambiente do Claude (limite de ~34 processos por comando); ali, rodar `php artisan test` separado do Pint e do PHPStan. Não é defeito do projeto: o CI do GitHub roda tudo e passa.
- [x] Grade de números: navegação por setas do teclado (feito na Fase 8).
- [x] No máximo 3 pedidos aguardando pagamento por pessoa em cada promoção (pela conta ou, sem conta, pelo IP; `ReserveNumbers::MAX_OPEN_ORDERS`). Quem compartilha IP (Wi-Fi, operadora móvel) divide esse limite; se reclamarem, aumentar o número.
- [x] Troca de forma de pagamento (Pix ↔ cartão) na página do pedido, só com a promoção aberta. A cobrança em aberto é cancelada no Asaas antes da troca; se o cancelamento falhar, nada muda. Conferido no sandbox real em 2026-10-12.
- [x] Pagamento em duplicidade (duas cobranças pagas no mesmo pedido) deixa o pedido "em verificação"; reembolsar uma delas devolve o pedido para "confirmado". Antes, o segundo pagamento era ignorado em silêncio.

### Fase 6 — Pagamentos

- [ ] **Webhook do Asaas não cadastrado**: precisa da URL pública (Fase 8). `ASAAS_WEBHOOK_TOKEN` está vazio no `.env` local. Com o token vazio, o sistema recusa todos os avisos, que é o comportamento seguro.
- [x] **Reembolso e cartão** no sandbox real (2026-10-12): cobrança de cartão paga na fatura do Asaas (cartão 4444 4444 4444 4444, CVV 123; a fatura exige reCAPTCHA), confirmada pela conciliação com comprovante e e-mail, depois reembolsada (pedido "reembolsado", comprovante público "não é mais válido"). Cancelamento de cobrança pela conciliação também conferido. O botão Reembolsar do painel chama o mesmo serviço e está coberto pelos testes.
- [x] Cliente do Asaas: era reaproveitado pelo CPF e a fatura mostrava nome e e-mail de quem já tinha comprado com aquele CPF. Agora cada cobrança cria um cliente com os dados do pedido.
- [x] O cancelamento da cobrança Pix no Asaas, quando a reserva expira sozinha, acontece pela conciliação (até ~1 minuto depois); conferido no sandbox real em 2026-10-12. Pagamento nesse intervalo cai no fluxo de pagamento tardio, que está tratado.
- [x] **Pix pago no sandbox real**: conferido localmente em 2026-10-13 (pedido confirmado pela consulta da página, sem webhook; comprovante, verificação pública, PDF e e-mails ok). O Asaas informa só o dia do pagamento, então o painel mostra "Pago em" só com a data; a hora da confirmação está no pedido e no comprovante. O webhook continua para depois do deploy.

### Fase 7 — Comprovantes e e-mails

- [x] PDF do comprovante conferido (Fase 8).
- [x] SMTP de produção: `nao-responda@osrv.com.br` no Titan com SPF, DKIM e DMARC conferidos (2026-10-12). Falta só a senha no `.env` do servidor, no primeiro deploy. Em desenvolvimento os e-mails vão para `storage/logs/laravel.log`.

## Escopo da Fase 8 — Preparação para produção

Hospedagem: HostGator plano M (cPanel), PHP 8.3, MySQL 8.0, subdomínio no mesmo cPanel do WordPress.

1. ~~**Acesso**: cadastrar chave SSH no cPanel e testar o acesso.~~ Feito (shell liberado em 2026-10-15).
2. ~~**Subdomínio** com raiz em `promocoes/public` (código fora de `public_html`); SSL pelo AutoSSL.~~ Feito em 2026-10-12.
3. **Deploy**:
   - código via git;
   - `composer install --no-dev --optimize-autoloader` (publica os arquivos do Filament);
   - assets compilados localmente (`npm run build`);
   - `php artisan migrate --force`, `storage:link`;
   - `config:cache`, `route:cache`, `view:cache`;
   - primeiro admin com `php artisan app:create-admin`.
4. **`.env` de produção**:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` com https;
   - banco com usuário MySQL exclusivo;
   - SMTP com SPF/DKIM;
   - Asaas (primeiro sandbox no servidor, depois produção);
   - permissão 600.
5. **Cron** (cPanel, a cada minuto): `php artisan schedule:run` (cobre `orders:expire` e `payments:sync`).
6. **Webhook do Asaas** com a URL `https://SUBDOMINIO/webhooks/asaas` e o token igual ao do `.env` do servidor (passo a passo no README).
7. **Backup diário** do banco (`mysqldump`, 7 dias) e cópia externa periódica.
8. **Segurança**:
   - revisão geral;
   - WordPress no mesmo cPanel: atualizar, remover plugins sem uso, 2FA no cPanel e no Asaas;
   - verificar se a chave de API do Asaas pode ser restrita (bloquear transferências/saques via API ou limitar por IP).
9. **Acessibilidade e responsividade**: revisão final.
10. **Testes ponta a ponta** com Playwright nos fluxos críticos.
11. **Checklist de publicação**: incluindo as pendências jurídicas e do Asaas acima, e uma execução completa no sandbox dentro da HostGator antes de ativar o Asaas de produção.

## Ambiente local (resumo)

- MySQL no Docker: abra o Docker Desktop e rode `docker compose up -d` (porta 3307).
- Servidor: `composer dev` ou `php artisan serve`.
- Dados de demonstração: `php artisan db:seed` (gera novas senhas, mostradas só no terminal).
- PHP 8.3 (winget) com `php.ini` próprio e certificados raiz (`extras/ssl/cacert.pem`) configurados para HTTPS.
- Asaas sandbox configurado no `.env` (chave de API preenchida; token do webhook vazio de propósito).
