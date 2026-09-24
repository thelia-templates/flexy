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

namespace FlexyBundle\Tests\Component;

use FlexyBundle\Components\Molecules\FormErrors\Base;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/** What the component reads off a real submitted form. */
final class FormErrorsCollectTest extends KernelTestCase
{
    private function submittedView(array $data): FormView
    {
        /** @var FormFactoryInterface $factory */
        $factory = self::getContainer()->get('form.factory');

        $form = $factory->createNamedBuilder('probe', FormType::class, null, ['csrf_protection' => false])
            ->add('city', TextType::class, [
                'label' => 'City',
                'constraints' => [new NotBlank(), new Regex('/^[a-z]+$/i'), new Length(min: 3)],
            ])
            // HiddenType bubbles its errors to the parent by design, so a hidden field only
            // reaches `entries()` when the form asks it not to.
            ->add('token', HiddenType::class, [
                'error_bubbling' => false,
                'constraints' => [new NotBlank()],
            ])
            ->getForm();

        $form->submit($data);

        return $form->createView();
    }

    private function component(FormView $view): Base
    {
        $component = new Base(new RequestStack());
        $component->form = $view;

        return $component;
    }

    public function testAFieldCarriesAllItsMessagesInOneEntry(): void
    {
        $entries = $this->component($this->submittedView(['city' => '42', 'token' => 'x']))->entries();

        self::assertCount(1, $entries, 'one entry for the one field in error');
        self::assertSame('City', $entries[0]['label']);
        self::assertCount(2, $entries[0]['messages'], 'Regex and Length both fired on the same field');
    }

    public function testAHiddenFieldGetsNeitherLabelNorAnchor(): void
    {
        $entries = $this->component($this->submittedView(['city' => 'Paris', 'token' => '']))->entries();

        self::assertCount(1, $entries);
        self::assertTrue($entries[0]['hidden']);
        self::assertNull($entries[0]['label']);
        self::assertNull($entries[0]['target']);
    }

    public function testAVisibleFieldAnchorsOnItsId(): void
    {
        $entries = $this->component($this->submittedView(['city' => '', 'token' => 'x']))->entries();

        self::assertSame('probe_city', $entries[0]['target']);
    }

    /** The rule of D3: one visible field reads under itself, two need the summary. */
    public function testTheSummaryFollowsTheNumberOfFieldsInError(): void
    {
        $oneField = $this->component($this->submittedView(['city' => '', 'token' => 'x']));
        $twoFields = $this->component($this->submittedView(['city' => '', 'token' => '']));

        self::assertFalse($oneField->showSummary());
        self::assertTrue($twoFields->showSummary());
    }

    /**
     * A hidden field hands its error to the form. It comes from the violation mapper, so it
     * carries a cause and is read as a failure of the form.
     */
    public function testAHiddenFieldBubblesItsErrorToTheRootByDefault(): void
    {
        /** @var FormFactoryInterface $factory */
        $factory = self::getContainer()->get('form.factory');

        $form = $factory->createNamedBuilder('probe', FormType::class, null, ['csrf_protection' => false])
            ->add('token', HiddenType::class, ['constraints' => [new NotBlank()]])
            ->getForm();

        $form->submit(['token' => '']);
        $component = $this->component($form->createView());

        self::assertSame([], $component->entries(), 'nothing on the field itself');
        self::assertNotSame([], $component->formFailures(), 'the message reaches the form instead');
        self::assertSame([], $component->rootErrors(), 'and it is a failure, not the wrapped message');
        self::assertTrue($component->showSummary());
    }

    /**
     * The case that used to lose the message: one faulty field does not open the summary on its
     * own, so the refusal had no list to appear in.
     */
    public function testAFailureOfTheFormShowsBesideASingleFaultyField(): void
    {
        $factory = self::getContainer()->get('form.factory');
        $form = $factory->createNamedBuilder('probe', FormType::class, null, ['csrf_protection' => false])
            ->add('city', TextType::class, ['label' => 'City', 'constraints' => [new NotBlank()]])
            ->getForm();
        $form->submit(['city' => '']);
        // What CsrfValidationListener does: an error on the root, carrying the token as its cause.
        $form->addError(new FormError('The CSRF token is invalid.', null, [], null, 'token'));

        $component = $this->component($form->createView());

        self::assertSame(['The CSRF token is invalid.'], $component->formFailures());
        self::assertCount(1, $component->entries(), 'still a single faulty field');
        self::assertTrue($component->showSummary(), 'and the summary opens all the same');
    }

    /** The message Thelia wraps around a rejection repeats the fields, so it waits its turn. */
    public function testTheWrappedMessageStaysHiddenWhileAFieldSpeaks(): void
    {
        $factory = self::getContainer()->get('form.factory');
        $form = $factory->createNamedBuilder('probe', FormType::class, null, ['csrf_protection' => false])
            ->add('city', TextType::class, ['label' => 'City', 'constraints' => [new NotBlank()]])
            ->getForm();
        $form->submit(['city' => '']);
        // What TwigEngine's FormService does: a root error with no cause.
        $form->addError(new FormError('Please check your input: [City] …'));

        $component = $this->component($form->createView());

        self::assertSame([], $component->formFailures(), 'it is not a failure of the form');
        self::assertNotSame([], $component->rootErrors(), 'it is read, but kept for later');
        self::assertFalse($component->showSummary(), 'and it does not open the summary on its own');
    }

    /** The sign-in exception of D7: its message rides the same channel and must still be read. */
    public function testTheWrappedMessageShowsWhenNoFieldSpeaks(): void
    {
        $factory = self::getContainer()->get('form.factory');
        $form = $factory->createNamedBuilder('probe', FormType::class, null, ['csrf_protection' => false])
            ->add('city', TextType::class, ['label' => 'City'])
            ->getForm();
        $form->submit(['city' => 'Paris']);
        $form->addError(new FormError('Wrong email or password. Please try again'));

        $component = $this->component($form->createView());

        self::assertSame([], $component->entries());
        self::assertTrue($component->showSummary());
        self::assertSame(['Wrong email or password. Please try again'], $component->rootErrors());
    }

    public function testAFormWithoutErrorsCollectsNothing(): void
    {
        $component = $this->component($this->submittedView(['city' => 'Paris', 'token' => 'x']));

        self::assertSame([], $component->entries());
        self::assertFalse($component->hasErrors());
    }

    private function submissionFor(array $attributes, ?string $submitAction = null): string
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(attributes: $attributes));

        $component = new Base($requestStack);
        $component->submitAction = $submitAction;

        return $component->submission();
    }

    public function testARenderThatOnlySyncsAFieldChangeLeavesTheFocusAlone(): void
    {
        self::assertSame('', $this->submissionFor(['_live_component' => 'Forms:Address:Base']));
        self::assertSame('', $this->submissionFor(['_live_component' => 'Forms:Address:Base', '_live_action' => 'get']));
    }

    public function testALiveActionOrAPageRenderAnswersASubmission(): void
    {
        self::assertNotSame('', $this->submissionFor(['_live_component' => 'Forms:Address:Base', '_live_action' => 'save']));
        self::assertNotSame('', $this->submissionFor([]));
    }

    /** Picking a combination on a product page whose quantity is in error must not pull the focus back. */
    public function testAnotherActionOfTheFormLeavesTheFocusAlone(): void
    {
        $live = ['_live_component' => 'Layouts:ProductDetails:Base'];

        self::assertSame('', $this->submissionFor($live + ['_live_action' => 'updateCurrentCombination'], 'save'));
        self::assertNotSame('', $this->submissionFor($live + ['_live_action' => 'save'], 'save'));
    }

    public function testABatchAnswersASubmissionOnlyWhenItCarriesTheSubmitAction(): void
    {
        $batch = static fn (string ...$names): array => [
            '_live_component' => 'Layouts:ProductDetails:Base',
            '_live_action' => '_batch',
            'actions' => array_map(static fn (string $name): array => ['name' => $name, 'args' => []], $names),
        ];

        self::assertNotSame('', $this->submissionFor($batch('updateCurrentCombination', 'save'), 'save'));
        self::assertSame('', $this->submissionFor($batch('updateCurrentCombination'), 'save'));
    }

    /** The value has to change for a second submission of the same live form to move the focus again. */
    public function testEachSubmissionGetsItsOwnValue(): void
    {
        $action = ['_live_component' => 'Forms:Address:Base', '_live_action' => 'save'];

        self::assertNotSame($this->submissionFor($action), $this->submissionFor($action));
    }
}
