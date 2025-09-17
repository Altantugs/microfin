<?php
namespace App\Controller;

use App\Repository\TransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function __invoke(TransactionRepository $repo): Response
    {
        $u = $this->getUser();

        $transactions = $repo->createQueryBuilder('t')
            ->andWhere('t.user = :u')->setParameter('u', $u)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        return $this->render('home/index.html.twig', [
            'title' => 'MicroFin',
            'transactions' => $transactions,
        ]);
    }
}
