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
            'totalTx'    => $totalTx,
        ]);
    }

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Хайлт + хуудаслалт
        $q      = trim((string)$request->query->get('q', ''));
        $page   = max(1, (int)$request->query->get('page', 1));
        $limit  = 10;
        $offset = ($page - 1) * $limit;

        $qb = $em->createQueryBuilder()
            ->from(User::class, 'u')
            ->select('u')
            ->orderBy('u.createdAt', 'DESC');

        if ($q !== '') {
            $qb->andWhere('LOWER(u.email) LIKE :q')
               ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        // Нийт тоо (ORDER BY-г арилгаж тоолно — PG grouping error fix)
        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $total = (int)$countQb->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        // Өгөгдөл
        $items = $qb->setFirstResult($offset)
                    ->setMaxResults($limit)
                    ->getQuery()
                    ->getResult();

        $totalPages = (int)ceil(max(1, $total) / $limit);

        return $this->render('admin/users.html.twig', [
            'items'      => $items,
            'q'          => $q,
            'page'       => $page,
            'total'      => $total,
            'totalPages' => $totalPages,
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
        } else {
            $this->addFlash('success', sprintf('Хэрэглэгч аль хэдийн админ: %s', $user->getEmail()));
        }

        return $this->redirectToRoute('admin_users', [
            'q'    => $request->query->get('q'),
            'page' => $request->query->getInt('page', 1),
        ]);
    }

    // === ADMIN эрх хасах (өөрийгөө хасахаас хамгаална) ===
    #[Route('/users/{id}/revoke-admin', name: 'user_revoke_admin', methods: ['POST'])]
    public function revokeAdmin(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_admin_'.$user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF');
        }

        if ($this->getUser() instanceof User && $this->getUser()->getId() === $user->getId()) {
            $this->addFlash('error', 'Өөрийн админ эрхийг хасах боломжгүй.');
            return $this->redirectToRoute('admin_users', [
                'q'    => $request->query->get('q'),
                'page' => $request->query->getInt('page', 1),
            ]);
        }

        $roles = array_filter($user->getRoles(), fn($r) => $r !== 'ROLE_ADMIN');
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        $user->setRoles(array_values(array_unique($roles)));
        $em->flush();

        $this->addFlash('success', sprintf('Admin эрх хаслаа: %s', $user->getEmail()));

        return $this->redirectToRoute('admin_users', [
            'q'    => $request->query->get('q'),
            'page' => $request->query->getInt('page', 1),
        ]);
    }

    // === Статус солих (ACTIVE/BLOCKED) — өөрийгөө түгжихээс хамгаална ===
    #[Route('/users/{id}/toggle-status', name: 'user_toggle_status', methods: ['POST'])]
    public function toggleStatus(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_status_'.$user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF');
        }

        if ($this->getUser() instanceof User && $this->getUser()->getId() === $user->getId()) {
            $this->addFlash('error', 'Өөрийгөө түгжих боломжгүй.');
            return $this->redirectToRoute('admin_users', [
                'q'    => $request->query->get('q'),
                'page' => $request->query->getInt('page', 1),
            ]);
        }

        $user->setStatus($user->getStatus() === 'ACTIVE' ? 'BLOCKED' : 'ACTIVE');
        $em->flush();

        $this->addFlash('success', sprintf('Статус шинэчлэгдлээ (%s): %s', $user->getStatus(), $user->getEmail()));

        return $this->redirectToRoute('admin_users', [
            'q'    => $request->query->get('q'),
            'page' => $request->query->getInt('page', 1),
        ]);
    }
}
