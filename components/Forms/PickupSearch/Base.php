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

namespace FlexyBundle\Components\Forms\PickupSearch;

use FlexyBundle\Form\Type\PickupAddressType;
use FlexyBundle\Service\DeliveryService;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\HttpFoundation\Session\Session;

#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?array $initialFormData = null;

    #[LiveProp]
    public array $pickups = [];

    #[LiveProp]
    public ?array $selectedPickup = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly DataAccessService $dataAccessService,
        private readonly DeliveryService $deliveryService,
        private readonly FormFactoryInterface $formFactory,
        private readonly Session $session,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->formFactory->create(PickupAddressType::class, $this->initialFormData);
    }

    public function mount(): void
    {
        if ($this->session->has('pickup')) {
            $this->selectedPickup = $this->session->get('pickup');
        }
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();
        $address = trim((string) ($this->getForm()->getData()['address'] ?? ''));

        // The geocoding API rejects queries shorter than 3 characters.
        if (\strlen($address) < 3) {
            $this->pickups = [];

            return;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api-adresse.data.gouv.fr/search/', [
                'query' => ['q' => $address, 'limit' => 5],
            ]);
            $features = $response->toArray()['features'] ?? [];
        } catch (ExceptionInterface) {
            $this->pickups = [];

            return;
        }

        if ([] === $features) {
            $this->pickups = [];

            return;
        }

        $feature = $features[0];

        // A hamlet sometimes outscores the town of the same name by a hair
        // (e.g. the "Brioude" locality in Savigneux vs the Brioude town):
        // in that ambiguous case only, prefer the town.
        if ('locality' === ($feature['properties']['type'] ?? '')) {
            $municipalities = array_filter(
                $features,
                static fn (array $candidate): bool => 'municipality' === ($candidate['properties']['type'] ?? ''),
            );

            if ([] !== $municipalities) {
                $feature = reset($municipalities);
            }
        }
        $place = $feature['properties'];
        $coordinates = $feature['geometry']['coordinates'];

        $this->pickups = $this->dataAccessService->resources(
            '/api/front/delivery_pickup_locations/'.$place['city'].'/'.$place['postcode'],
            [
                'address' => $place['name'] ?? '',
            ],
        );

        $this->dispatchBrowserEvent('pickuppoint:update', [
            'pickups' => $this->pickups,
            'coordinates' => $coordinates,
        ]);
    }

    #[LiveAction]
    public function pickupPointClick(#[LiveArg] array $pickup): void
    {
        $this->setSelectedPickup($pickup);
    }

    #[LiveAction]
    public function updateOption(#[LiveArg] string $id): void
    {
        $current = array_filter($this->pickups, static fn (array $item): bool => $item['id'] === $id);

        if ([] === $current) {
            return;
        }

        $this->setSelectedPickup(reset($current));

        $this->dispatchBrowserEvent('pickup:selected', ['pickup' => $this->selectedPickup]);
    }

    private function setSelectedPickup(array $pickup): void
    {
        $this->selectedPickup = $pickup;
        $this->deliveryService->setPickupSession($pickup);
        $this->emit('updateNextButton');
    }
}
