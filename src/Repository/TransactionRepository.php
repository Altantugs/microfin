<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
final class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Одоогийн хэрэглэгчийн бүх гүйлгээ (шинэ нь эхэнд)
     * @return Transaction[]
     */
    public function findAllFor(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Нэг гүйлгээг ID-аар нь авч эзэмшигчийг баталгаажуулах
     */
    public function findOneForUser(int $id, User $user): ?Transaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')
            ->andWhere('t.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Ангиллаар нийлбэр (тайлан)
     * @return array<array{category: ?string, total: string}>
     */
    public function summaryFor(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->select('t.category AS category, SUM(t.amount) AS total')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->groupBy('t.category')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * (Сонголт) Хэмнэлттэй save/remove туслахууд
     */
    public function save(Transaction $entity, bool $flush = false): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        if ($flush) { $em->flush(); }
    }

    public function remove(Transaction $entity, bool $flush = false): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        if ($flush) { $em->flush(); }
    }
}
