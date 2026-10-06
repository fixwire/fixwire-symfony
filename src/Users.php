<?php

declare(strict_types=1);

namespace Fixwire\Symfony;

use Fixwire\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** @internal the signed-in user, as Fixwire sends it: the identifier, and the email with send_default_pii */
final class Users
{
    public static function signedIn(TokenStorageInterface $tokens, bool $pii): ?User
    {
        $user = $tokens->getToken()?->getUser();
        if ($user === null) {
            return null;
        }
        $email = $pii && method_exists($user, 'getEmail') && \is_string($user->getEmail()) ? $user->getEmail() : null;

        return new User($user->getUserIdentifier(), $email);
    }
}
