import {
  pickupPoint,
  pickupPointHours
} from '@components/Organisms/Card/PickupPoint/PickupPoint';
import { PickupPointView } from '@components/Organisms/Modules/PickupPointModule/pickupPointView';

function delivery() {
  pickupPointHours();
  pickupPoint();
  PickupPointView();
}

document.addEventListener('DOMContentLoaded', () => {
  delivery();
});
