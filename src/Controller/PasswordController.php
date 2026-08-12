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
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Event\LostPasswordEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Form\Exception\FormValidationException;

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
                $email = $form->get('email')->getData();
                $eventDispatcher->dispatch(new LostPasswordEvent($email), TheliaEvents::LOST_PASSWORD);
                $session->set('reset_email', $email);

                return $this->generateSuccessRedirect($passwordLost);
            } catch (FormValidationException $e) {
                $formData = $this->requestStack->getCurrentRequest()?->request->all('thelia_customer_lost_password');
                $submittedEmail = trim($formData['email'] ?? '');

                // Prevent user enumeration: if the email is syntactically valid, the only
                // reason for failure is the "email not found" Callback constraint — redirect
                // silently to the success page so an attacker cannot distinguish existing
                // from non-existing accounts.
                if (filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
                    $session->set('reset_email', $submittedEmail);

                    return $this->generateSuccessRedirect($passwordLost);
                }

                $message = $this->getTranslator()->trans(
                    'Please check your input: %s',
                    [
                        '%s' => $e->getMessage(),
                    ],
                );
            }
        } else {
            $message = $this->getTranslator()->trans(
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

    // Anyone can put any address in their own session by posting the form above, so
    // this route asks the shop to mail whoever the caller named. The core caps how
    // many of those emails go out, per address and per caller; the page below says
    // the same thing either way, so repeating the request tells a caller nothing
    // about the address it names.
    #[Route('/resend', name: 'resend', methods: ['GET'])]
    public function resendLink(SessionInterface $session, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $email = $session->get('reset_email');

        // Nothing was asked for in this session: send the visitor back to the form,
        // the way the confirmation page does, instead of asking for a password for
        // no address at all.
        if (null === $email) {
            return $this->generateRedirect($this->generateUrl('password_forgotten'));
        }

        $eventDispatcher->dispatch(new LostPasswordEvent($email), TheliaEvents::LOST_PASSWORD);

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
