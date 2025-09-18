<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(UserRepository $users, TransactionRepository $tx): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

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
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/users.html.twig', [
            'items' => $users->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/reports', name: 'reports', methods: ['GET'])]
    public function reports(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

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

    // === ADMIN эрх олгох ===
    #[Route('/users/{id}/grant-admin', name: 'user_grant_admin', methods: ['POST'])]
    public function grantAdmin(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_admin_'.$user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF');
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
            $user->setRoles(array_values(array_unique($roles)));
            $em->flush();
            $this->addFlash('success', sprintf('Admin эрх олголоо: %s', $user->getEmail()));
        }

        return $this->redirectToRoute('admin_users');
    }

    // === ADMIN эрх хасах ===
    #[Route('/users/{id}/revoke-admin', name: 'user_revoke_admin', methods: ['POST'])]
    public function revokeAdmin(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_admin_'.$user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF');
        }

        $roles = array_filter($user->getRoles(), fn($r) => $r !== 'ROLE_ADMIN');
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        $user->setRoles(array_values(array_unique($roles)));
        $em->flush();

        $this->addFlash('success', sprintf('Admin эрх хаслаа: %s', $user->getEmail()));

        return $this->redirectToRoute('admin_users');
    }

    // === Статус солих (ACTIVE/BLOCKED) ===
    #[Route('/users/{id}/toggle-status', name: 'user_toggle_status', methods: ['POST'])]
    public function toggleStatus(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_status_'.$user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF');
        }

        $user->setStatus($user->getStatus() === 'ACTIVE' ? 'BLOCKED' : 'ACTIVE');
        $em->flush();

        $this->addFlash('success', sprintf('Статус шинэчлэгдлээ (%s): %s', $user->getStatus(), $user->getEmail()));

        return $this->redirectToRoute('admin_users');
    }
}
