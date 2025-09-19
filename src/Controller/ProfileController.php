<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/profile', name: 'profile_')]
final class ProfileController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('profile/index.html.twig');
    }

    #[Route('/password', name: 'password', methods: ['GET','POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_password', (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Bad CSRF');
            }

            $current = (string)$request->request->get('current_password', '');
            $new     = (string)$request->request->get('new_password', '');
            $new2    = (string)$request->request->get('new_password_repeat', '');

            if (!$hasher->isPasswordValid($user, $current)) {
                $this->addFlash('error', 'Одоогийн нууц үг буруу.');
                return $this->redirectToRoute('profile_password');
            }
            if (strlen($new) < 8) {
                $this->addFlash('error', 'Шинэ нууц үг хамгийн багадаа 8 тэмдэгт байх ёстой.');
                return $this->redirectToRoute('profile_password');
            }
            if ($new !== $new2) {
                $this->addFlash('error', 'Шинэ нууц үг хоорондоо таарахгүй байна.');
                return $this->redirectToRoute('profile_password');
            }

            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();
            $this->addFlash('success', 'Нууц үг амжилттай шинэчлэгдлээ.');
            return $this->redirectToRoute('profile_index');
        }

        return $this->render('profile/change_password.html.twig');
    }

    #[Route('/email', name: 'email', methods: ['GET','POST'])]
    public function changeEmail(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_email', (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Bad CSRF');
            }

            $password = (string)$request->request->get('current_password', '');
            $newEmail = trim((string)$request->request->get('new_email', ''));

            if (!$hasher->isPasswordValid($user, $password)) {
                $this->addFlash('error', 'Нууц үг буруу.');
                return $this->redirectToRoute('profile_email');
            }

            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Имэйл формат буруу байна.');
                return $this->redirectToRoute('profile_email');
            }

            // Имэйл давхардал шалгах
            if ($users->findOneBy(['email' => $newEmail])) {
                $this->addFlash('error', 'Энэ имэйл аль хэдийн бүртгэлтэй байна.');
                return $this->redirectToRoute('profile_email');
            }

            $user->setEmail($newEmail);
            $em->flush();

            $this->addFlash('success', 'Имэйл амжилттай шинэчлэгдлээ.');
            return $this->redirectToRoute('profile_index');
        }

        return $this->render('profile/change_email.html.twig');
    }
}
