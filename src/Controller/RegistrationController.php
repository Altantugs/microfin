<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET','POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = new User();

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        // Алдааг ил тод харуулахын тулд form errors-ийг stdout руу логлоно
        if ($form->isSubmitted() && !$form->isValid()) {
            $errs = [];
            foreach ($form->getErrors(true, true) as $e) {
                $errs[] = $e->getMessage();
            }
            if ($errs) {
                error_log('[register] form errors: ' . implode(' | ', $errs));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Нууц үг хешлэх
            $plain = (string)$form->get('plainPassword')->getData();
            $user->setPassword($hasher->hashPassword($user, $plain));

            // Анхдагч ROLE_USER (entity constructor-д байгаа ч баталгаажуулж үлдээнэ)
            $roles = $user->getRoles();
            if (!in_array('ROLE_USER', $roles, true)) {
                $user->setRoles([...$roles, 'ROLE_USER']);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Бүртгэл амжилттай. Одоо нэвтэрч орно уу.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
