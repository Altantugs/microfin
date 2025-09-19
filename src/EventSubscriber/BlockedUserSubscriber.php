<?php
declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class BlockedUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Эрэлт ирэх бүрд (controller-аас өмнө) шалгана
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = new \DateTimeImmutable();
        $expired = $user->getExpiresAt() !== null && $user->getExpiresAt() < $now;

        if ($user->getStatus() !== 'ACTIVE' || $expired) {
            $request = $event->getRequest();
            if ($request->hasSession()) {
                $session = $request->getSession();
                $reason = $user->getStatus() !== 'ACTIVE'
                    ? 'Таны бүртгэл идэвхгүй тул нэвтрэх боломжгүй.'
                    : 'Таны бүртгэлийн хугацаа дууссан тул нэвтрэх боломжгүй.';
                $session->getFlashBag()->add('error', $reason);
                $session->invalidate();
            }

            $this->tokenStorage->setToken(null);

            $loginUrl = $this->urlGenerator->generate('app_login');
            $event->setResponse(new RedirectResponse($loginUrl));
        }
    }
}
