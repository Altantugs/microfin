<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250918112000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sessions table for PDO session handler (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
  sess_id VARCHAR(128) PRIMARY KEY,
  sess_data BYTEA NOT NULL,
  sess_lifetime INTEGER NOT NULL,
  sess_time INTEGER NOT NULL
);
SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sessions_sess_time ON sessions (sess_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sessions');
    }
}
