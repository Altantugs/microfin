<?php
namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(UserRepository $users, TransactionRepository $tx): Response
    {
        $totalUsers = $users->count([]);
        $totalTx    = $tx->count([]);
        return $this->render('admin/index.html.twig', [
            'totalUsers' => $totalUsers,
            'totalTx' => $totalTx,
        ]);
    }

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(UserRepository $users): Response
    {
        return $this->render('admin/users.html.twig', [
            'items' => $users->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/reports', name: 'reports', methods: ['GET'])]
    public function reports(EntityManagerInterface $em): Response
    {
        $qb = $em->createQueryBuilder()
            ->select('u.email AS email')
            ->addSelect('COUNT(t.id) AS txCount')
            ->addSelect('SUM(CASE WHEN t.isIncome = true  THEN t.amount ELSE 0 END) AS incomeTotal')
            ->addSelect('SUM(CASE WHEN t.isIncome = false THEN t.amount ELSE 0 END) AS expenseTotal')
            ->from('App\Entity\Transaction', 't')
            ->innerJoin('t.user', 'u')
            ->groupBy('u.email')
            ->orderBy('u.email', 'ASC');

        $rows = $qb->getQuery()->getArrayResult();

        return $this->render('admin/reports.html.twig', [
            'rows' => $rows,
        ]);
    }
}
