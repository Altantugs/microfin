<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250915120441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add origin column (nullable) to transaction table';
    }

    public function up(Schema $schema): void
    {
        // Postgres дээр заримдаа "transaction" зарим keyword болдог тул хашилт хэрэглэв
        $this->addSql('ALTER TABLE "transaction" ADD COLUMN origin VARCHAR(16) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "transaction" DROP COLUMN origin');
    }
}
