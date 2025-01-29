import PickupPointModule from './PickupPointModule.html.twig';
import {
  pickupPoint,
  pickupPointHours
} from '../../Card/PickupPoint/PickupPoint';
import { PickupMap } from '@components/Organisms/Modules/PickupPointModule/pickupMap';

export default {
  title: 'Design System/Organisms/Modules/PickupPoint'
};

const address = {
  address1: 'Adresse première ligne',
  address2: 'Complément d’adresse',
  zipCode: '00000',
  city: 'Clermont-Ferrand',
  country: 'Ville-Sur-Fleuve'
};
const hours = [
  { day: 'lundi', hours: '14h - 19h' },
  { day: 'mardi', hours: '14h - 20h' },
  { day: 'Mercredi', hours: '14h - 20h' },
  { day: 'Jeudi', hours: '14h - 20h' },
  { day: 'Vendredi', hours: '14h - 20h' },
  { day: 'Samedi', hours: '14h - 20h' },
  { day: 'Dimanche', hours: 'Fermé' }
];

export const Base = {
  render: (args) => PickupPointModule(args),
  play: () => {
    pickupPointHours();
    pickupPoint();
    PickupMap();
  },
  args: {
    selected: false,
    title: 'Retrait en point relais',
    price: '7,80€',
    carbone: '0.12kg CO2',
    pickupServices: [
      {
        name: 'Mondial relais',
        value: 'mondial_relais',
        img: '/images/mondialRelay.svg'
      },
      {
        name: 'Relais colis',
        value: 'relais_colis'
      }
    ],
    pickups: [
      {
        selected: false,
        closed: false,
        title: 'Nom du point relais',
        date: 'JJ/MM',
        address,
        price: '7,80€',
        img: { alt: 'Logo Mondial Relay', src: '/images/mondialRelay.svg' },
        hours
      },
      {
        selected: false,
        closed: true,
        title: 'Nom du point relais',
        date: 'JJ/MM',
        address,
        price: '7,80€',
        img: { alt: 'Logo Mondial Relay', src: '/images/mondialRelay.svg' },
        hours
      }
    ]
  },
  argTypes: {}
};
