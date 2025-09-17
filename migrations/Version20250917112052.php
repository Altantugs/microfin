<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250917112052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table and link transactions to users';
    }

    public function up(Schema $schema): void
    {
        // 1) users хүснэгт
        $this->addSql('CREATE TABLE users (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            email VARCHAR(180) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'ACTIVE\',
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
            roles JSON NOT NULL DEFAULT \'["ROLE_USER"]\',
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
        )');

        // gen_random_uuid() ашиглахын тулд
        $this->addSql('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');

        // 2) demo хэрэглэгч нэмнэ (дараа сольж болно)
        $this->addSql("
            INSERT INTO users (email, password, status, roles)
            VALUES (
              'owner@example.com',
              '\$2y\$13\$Qk9H7m6mWnYvF8qjYtqfY.fC3i0m2pKjG3W1d9t0Q0b2pZcGQ7kHy',
              'ACTIVE',
              '[\"ROLE_USER\",\"ROLE_ADMIN\"]'
            )
        ");

        // 3) transaction хүснэгтэд user_id талбар нэмнэ
        $this->addSql('ALTER TABLE transaction ADD COLUMN user_id UUID NULL');

        // 4) хуучин бүх transaction мөрүүдийг demo хэрэглэгчид холбох
        $this->addSql('UPDATE transaction SET user_id = (SELECT id FROM users WHERE email = \'owner@example.com\' LIMIT 1)');

        // 5) FK, индекс, NOT NULL болгож чангална
        $this->addSql('ALTER TABLE transaction
            ADD CONSTRAINT fk_transaction_user
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_transaction_user ON transaction (user_id)');
        $this->addSql('ALTER TABLE transaction ALTER COLUMN user_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT fk_transaction_user');
        $this->addSql('DROP INDEX IF EXISTS idx_transaction_user');
        $this->addSql('ALTER TABLE transaction DROP COLUMN user_id');
        $this->addSql('DROP TABLE users');
    }
}
