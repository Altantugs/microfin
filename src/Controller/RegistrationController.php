<?php
namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
        UserPasswordHasherInterface $passwordHasher,
        LoggerInterface $logger
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $plain = $form->get('plainPassword')->getData();
                $user->setPassword($passwordHasher->hashPassword($user, $plain));

                if (method_exists($user, 'setRoles') && empty($user->getRoles())) {
                    $user->setRoles(['ROLE_USER']);
                }

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Амжилттай бүртгэгдлээ. Одоо нэвтэрч орно уу.');
                return $this->redirectToRoute('app_login');
            }

            // ✦ Алдаа гарсан үед: дэлгэрэнгүйг лог руу, 1–2 мөрийг UI дээр харуулна
            $messages = [];
            foreach ($form->getErrors(true) as $error) {
                $messages[] = $error->getMessage();
            }
            $logger->error('[register] form invalid', ['errors' => $messages]);

            if (!empty($messages)) {
                $this->addFlash('error', 'Бүртгэл бүтсэнгүй: ' . implode(' | ', array_unique($messages)));
            }

            // 422 статустайгаар формыг буцаая (лог дээр ч ялгаж харагдана)
            return $this->render('security/register.html.twig', [
                'form' => $form->createView(),
            ], new Response(null, 422));
        }

        return $this->render('security/register.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
