import { pickupPoint, pickupPointHours } from '@components/Organisms/Card/PickupPoint/PickupPoint';
import { deliveryModule } from '@utils/delivery';
import { HomeDeliveryAddresses } from '@components/Organisms/Modules/HomeDelivery/HomeDelivery';

function delivery() {
  document.body.classList.remove('no-js');
  deliveryModule();
  pickupPointHours();
  pickupPoint();
  HomeDeliveryAddresses();
}

document.addEventListener('DOMContentLoaded', () => {
  delivery();
});
