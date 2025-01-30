import { Controller } from '@hotwired/stimulus';
import { getComponent, Component } from '@symfony/ux-live-component';
import {
  PickupLocationType,
  PickupMap
} from '@components/Organisms/Modules/PickupPointModule/pickupMap';

export default class extends Controller<HTMLFormElement> {
  private component!: Component;

  async initialize(): Promise<void> {
    this.component = await getComponent(this.element);
    //this.component.action
    PickupMap(this.pickupPointClick.bind(this));
  }

  pickupPointClick(pickup: PickupLocationType): void {
    console.log({ pickup });
    this.component.action('pickupPointClick', { pickup });
  }
}
