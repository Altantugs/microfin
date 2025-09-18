<?php
namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET','POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        // Хэрэв аль хэдийн нэвтэрсэн бол нүүр рүү буцаая (сонголт)
        if ($this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plain));
            // анхдагч роль
            if (method_exists($user, 'setRoles') && empty($user->getRoles())) {
                $user->setRoles(['ROLE_USER']);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Амжилттай бүртгэгдлээ. Одоо нэвтэрч орно уу.');
            return $this->redirectToRoute('app_login');
        }

        // ЭНД form->createView()-г template-д "form" нэрээр дамжуулж байна
        return $this->render('security/register.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
