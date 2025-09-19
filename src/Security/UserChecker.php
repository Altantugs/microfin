<?php
namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->getStatus() !== 'ACTIVE') {
            throw new CustomUserMessageAccountStatusException('Таны бүртгэл идэвхгүй байна.');
        }

        if ($user->getExpiresAt() && $user->getExpiresAt() < new \DateTimeImmutable()) {
            throw new CustomUserMessageAccountStatusException('Таны бүртгэлийн хугацаа дууссан байна.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // no-op
    }
}
