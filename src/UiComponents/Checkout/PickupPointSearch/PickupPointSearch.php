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

namespace FlexyBundle\UiComponents\Checkout\PickupPointSearch;

use FlexyBundle\Form\Type\PickupAddressType;
use FlexyBundle\Service\DeliveryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\HttpFoundation\Session\Session;

#[AsLiveComponent(name: 'Flexy:Checkout:PickupPointSearch', template: '@UiComponents/Checkout/PickupPointSearch/PickupPointSearch.html.twig')]
class PickupPointSearch extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public $initialFormData;

    #[LiveProp]
    public array $pickups = [];

    #[LiveProp]
    public ?array $selectedPickup = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly DataAccessService $dataAccessService,
        private readonly ?DeliveryService $deliveryService,
        private readonly Session $session
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(PickupAddressType::class, $this->initialFormData);
    }

    public function mount()
    {
        if ($this->session->has('pickup')) {
            $this->selectedPickup = $this->session->get('pickup');
        }
    }

    /**
     * @throws TransportExceptionInterface
     */
    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();
        $data = $this->getForm()->getData();

        $response = $this->httpClient->request('GET', 'https://api-adresse.data.gouv.fr/search/', [
            'query' => [
                'q' => $data['address'],
            ],
        ]);
        $feature = $response->toArray()['features'][0];
        $place = $feature['properties'];
        $coordinates = $feature['geometry']['coordinates'];
        $this->pickups = $this->dataAccessService->resources(
            '/api/front/delivery_pickup_locations/'.$place['city'].'/'.$place['postcode'],
            [
                'address' => $place['name'],
            ]
        );
        $this->dispatchBrowserEvent('pickuppoint:update', ['pickups' => $this->pickups, 'coordinates' => $coordinates]);
    }

    private function setSelectedPickup($pickup)
    {
       $this->selectedPickup = $pickup;
       $this->deliveryService->setPickupSession($pickup);
       $this->emit('updateNextButton');
    }

    #[LiveAction]
    public function pickupPointClick(#[LiveArg] $pickup): void
    {
        $this->setSelectedPickup($pickup);
    }

    #[LiveAction]
    public function updateOption(#[LiveArg] string $id): void
    {
        $current = array_filter($this->pickups, fn ($item) => $item['id'] === $id);

        $this->setSelectedPickup(reset($current));

        $this->dispatchBrowserEvent('pickup:selected', ['pickup' => $this->selectedPickup]);
    }
}
