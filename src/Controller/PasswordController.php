<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Controller;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Core\Event\LostPasswordEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;

#[Route('/password', name: 'password_')]
class PasswordController extends FlexyController
{
    #[Route('/forgotten', name: 'forgotten', methods: ['GET'])]
    public function forgotten(): Response
    {
        return $this->render('password-forgotten');
    }

    #[Route('/forgotten', name: 'forgotten_send', methods: ['POST'])]
    public function forgottenSend(EventDispatcherInterface $eventDispatcher, SessionInterface $session)
    {
        $passwordLost = $this->createForm('thelia_customer_lost_password');

        if (!$this->getSecurityContext()->hasCustomerUser()) {
            try {
                $form = $this->validateForm($passwordLost);

                $event = new LostPasswordEvent($form->get('email')->getData());

                $eventDispatcher->dispatch($event, TheliaEvents::LOST_PASSWORD);
                $session->set('reset_email', $form->get('email')->getData());

                return $this->generateSuccessRedirect($passwordLost);
            } catch (FormValidationException $e) {
                $message = $this->translator->trans(
                    'Please check your input: %s',
                    [
                        '%s' => $e->getMessage(),
                    ],
                );
            }

            if ($message !== false) {
                Tlog::getInstance()->error(
                    \sprintf(
                        'Error during customer creation process : %s. Exception was %s',
                        $message,
                        $e->getMessage()
                    )
                );
            }
        } else {
            $message = $this->translator->trans(
                "You're currently logged in. Please log out before requesting a new password.",
                [],
            );
        }

        $passwordLost->setErrorMessage($message);

        $this->getParserContext()
          ->addForm($passwordLost)
          ->setGeneralError($message);

        // Redirect to error URL if defined
        if ($passwordLost->hasErrorUrl()) {
            return $this->generateErrorRedirect($passwordLost);
        }
    }

    #[Route('/forgotten/confirm', name: 'reset_link', methods: ['GET'])]
    public function confirmResetLink(SessionInterface $session): Response|RedirectResponse
    {
        if (null == $session->get('reset_email')) {
            return $this->generateRedirect($this->generateUrl('password_forgotten'));
        }

        return $this->render('password-forgotten-confirm');
    }

    #[Route('/resend', name: 'resend', methods: ['GET'])]
    public function resendLink(SessionInterface $session, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $event = new LostPasswordEvent($session->get('reset_email'));

        $eventDispatcher->dispatch($event, TheliaEvents::LOST_PASSWORD);

        return $this->generateRedirect($this->generateUrl('password_reset_link', [
            'resend_success' => true,
        ]));
    }

    #[Route('/reset', name: 'reset_confirm', methods: ['GET'])]
    public function resetConfirm(): Response
    {
        return $this->render('reset-password-confirm');
    }
}
