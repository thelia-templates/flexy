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
use Thelia\Core\Event\Customer\CustomerResetPasswordEvent;
use Thelia\Core\Event\LostPasswordEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Customer\Exception\InvalidPasswordResetTokenException;
use Thelia\Domain\Customer\Service\PasswordResetService;
use Thelia\Form\CustomerResetPasswordForm;
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
    public function forgottenSend(EventDispatcherInterface $eventDispatcher, SessionInterface $session): ?RedirectResponse
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
                // The form only checks the shape of the address, and the shop stays silent
                // about whether it has an account, so what is left here is a genuinely
                // unusable submission the visitor can fix.
                $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
            }
        } else {
            $message = $this->getTranslator()->trans("You're currently logged in. Please log out before requesting a new password.");
        }

        $passwordLost->setErrorMessage($message);
        $this->getParserContext()->addForm($passwordLost);

        if ($passwordLost->hasErrorUrl()) {
            return $this->generateErrorRedirect($passwordLost);
        }

        return null;
    }

    #[Route('/forgotten/confirm', name: 'reset_link', methods: ['GET'])]
    public function confirmResetLink(SessionInterface $session): Response|RedirectResponse
    {
        if (null === $session->get('reset_email')) {
            return $this->generateRedirect($this->generateUrl('password_forgotten'));
        }

        return $this->render('password-forgotten-confirm');
    }

    #[Route('/resend', name: 'resend', methods: ['GET'])]
    public function resendLink(SessionInterface $session, EventDispatcherInterface $eventDispatcher): RedirectResponse
    {
        $email = $session->get('reset_email');

        if (null === $email) {
            return $this->generateRedirect($this->generateUrl('password_forgotten'));
        }

        $event = new LostPasswordEvent($email);
        $eventDispatcher->dispatch($event, TheliaEvents::LOST_PASSWORD);

        return $this->generateRedirect($this->generateUrl('password_reset_link', ['resend_success' => true]));
    }

    /**
     * The page the link in the reset email leads to.
     *
     * The token is checked before the form is shown, so someone holding a link that has
     * expired or has already been used is told so straight away instead of choosing a
     * password the shop is going to refuse.
     */
    #[Route('/reset/{token}', name: 'reset', methods: ['GET'])]
    public function reset(string $token, PasswordResetService $passwordResetService): Response
    {
        return $this->render('reset-password', [
            'token' => $token,
            'token_is_valid' => null !== $passwordResetService->findCustomerForToken($token),
        ]);
    }

    #[Route('/reset/{token}', name: 'reset_action', methods: ['POST'])]
    public function resetAction(string $token, EventDispatcherInterface $eventDispatcher): Response
    {
        $resetForm = $this->createForm(CustomerResetPasswordForm::class);

        try {
            $form = $this->validateForm($resetForm, 'post');

            $eventDispatcher->dispatch(
                new CustomerResetPasswordEvent(
                    (string) $form->get('token')->getData(),
                    (string) $form->get('password')->getData(),
                ),
                TheliaEvents::CUSTOMER_RESET_PASSWORD,
            );

            return $this->generateSuccessRedirect($resetForm);
        } catch (InvalidPasswordResetTokenException) {
            // Nothing the visitor can fix on this page: show it in the state that offers
            // a new link rather than the form.
            return $this->render('reset-password', ['token' => $token, 'token_is_valid' => false]);
        } catch (FormValidationException $e) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $e->getMessage()]);
        }

        $resetForm->setErrorMessage($message);
        $this->getParserContext()->addForm($resetForm);

        return $this->render('reset-password', ['token' => $token, 'token_is_valid' => true]);
    }

    #[Route('/reset', name: 'reset_confirm', methods: ['GET'])]
    public function resetConfirm(): Response
    {
        return $this->render('reset-password-confirm');
    }
}
