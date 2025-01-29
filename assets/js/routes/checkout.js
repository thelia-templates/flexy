import {
  pickupPoint,
  pickupPointHours
} from '@components/Organisms/Card/PickupPoint/PickupPoint';
import { deliveryModule } from '@utils/delivery';
import { HomeDeliveryAddresses } from '@components/Organisms/Modules/HomeDelivery/HomeDelivery';
import { PickupMap } from '@components/Organisms/Modules/PickupPointModule/pickupMap';
import { PickupPointView } from '@components/Organisms/Modules/PickupPointModule/pickupPointView';

function delivery() {
  console.log('delivery2');
  document.body.classList.remove('no-js');
  deliveryModule();
  pickupPointHours();
  pickupPoint();
  HomeDeliveryAddresses();
  PickupMap();
  PickupPointView();
}

document.addEventListener('DOMContentLoaded', () => {
  delivery();
});
