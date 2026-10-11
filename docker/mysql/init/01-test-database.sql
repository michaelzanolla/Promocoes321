-- Banco separado para os testes automatizados (phpunit.xml).
CREATE DATABASE IF NOT EXISTS promocoes_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON promocoes_test.* TO 'promocoes'@'%';
