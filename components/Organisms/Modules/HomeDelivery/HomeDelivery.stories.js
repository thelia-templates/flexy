import HomeDelivery from './HomeDelivery.html.twig';
import { HomeDeliveryAddresses } from '@components/Organisms/Modules/HomeDelivery/HomeDelivery';

export default {
  title: 'Design System/Organisms/Modules/HomeDelivery'
};

const address = {
  label   : 'Domicile',
  name    : 'Eleanor Shellstrop',
  address1: 'Adresse première ligne',
  address2: 'Complément d’adresse',
  zipCode : '00000',
  city    : 'Clermont-Ferrand',
  country : 'Ville-Sur-Fleuve',
  phone   : '06 06 06 06 06'
};

export const Base = {
  render: (args) => HomeDelivery(args),
  play  : () => {
    HomeDeliveryAddresses();
  },
  args  : {
    selected : false,
    title    : 'Livraison à domicile',
    date     : 'JJ/MM',
    price    : '7,80 €',
    addresses: [{ ...address, selected: true, isDefault: true }, address]
  }
};
