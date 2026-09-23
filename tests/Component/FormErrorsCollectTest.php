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
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\NotBlank;

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
        $component = new Base();
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

    /** The default the theme actually meets: a hidden field hands its error to the form. */
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
        self::assertNotSame([], $component->rootErrors(), 'the message reaches the form instead');
    }

    public function testAFormWithoutErrorsCollectsNothing(): void
    {
        $component = $this->component($this->submittedView(['city' => 'Paris', 'token' => 'x']));

        self::assertSame([], $component->entries());
        self::assertFalse($component->hasErrors());
    }
}
