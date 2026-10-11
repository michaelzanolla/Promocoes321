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
php artisan db:seed
npm run build
```

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

Roda formatação (Pint), análise estática (PHPStan) e os testes (PHPUnit). Os testes usam o banco `promocoes_test` no mesmo MySQL do Docker, criado automaticamente na primeira subida do container. Se o container já existia antes disso, recrie-o com `docker compose down -v` e `docker compose up -d` (isso apaga o banco local).

## Perfis de acesso

- **Participante**: todo cadastro feito pelo site.
- **Administrador**: só pode ser criado pelo terminal do servidor:

```bash
php artisan app:create-admin email@exemplo.com
```

Não existe tela nem formulário que transforme um participante em administrador.

## Produção

O roteiro de deploy na HostGator (SSH, subdomínio, cron, SSL) será documentado na Fase 8. Nunca rode `db:seed` em produção: o seeder recusa esse ambiente.
