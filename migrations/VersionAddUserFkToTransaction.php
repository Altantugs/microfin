<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class VersionAddUserFkToTransaction extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_id (uuid) to transaction and FK -> users(id) with ON DELETE CASCADE';
    }

    public function up(Schema $schema): void
    {
        // 1) user_id (nullable эхлээд)
        $this->addSql('ALTER TABLE transaction ADD COLUMN user_id UUID NULL');
        // 2) Индекс
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_transaction_user ON transaction (user_id)');
        // 3) FK (устгахад каскад)
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_transaction_user
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT IF EXISTS FK_transaction_user');
        $this->addSql('DROP INDEX IF EXISTS IDX_transaction_user');
        $this->addSql('ALTER TABLE transaction DROP COLUMN user_id');
    }
}
