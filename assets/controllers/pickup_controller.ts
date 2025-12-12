import { Controller } from '@hotwired/stimulus';
import { getComponent, Component } from '@symfony/ux-live-component';
import {
  PickupLocationType,
  PickupMap
} from '../../src/UiComponents/Checkout/Delivery/PickupDelivery/pickupMap';
import { PickupPointView } from '../../src/UiComponents/Checkout/Delivery/PickupDelivery/pickupPointView';

export default class extends Controller<HTMLFormElement> {
  private component!: Component;

  async initialize(): Promise<void> {
    this.component = await getComponent(this.element);
    //this.component.action
    PickupMap(this.pickupPointClick.bind(this));
    PickupPointView();
  }

  pickupPointClick(pickup: PickupLocationType): void {
    this.component.action('pickupPointClick', { pickup });
  }
}
