import CrossSelling from './CrossSelling.html.twig';
import { slider } from '../../../assets/js/slider';

export default {
  title: 'Design System/Layout/CrossSelling'
};

const standardProduct = {
  productTitle: 'Nom du produit',
  productLink: '#',
  secondaryTitle: 'Titre secondaire',
  price: '1000,00€',
  promoPrice: '900,00€',
  rate: 4,
  isNew: true,
  displayWishButton: true,
  reviewCount: 12
};

const products = [];

for (let i = 0; i < 3; i++) {
  products.push(standardProduct);
}

export const base = {
  render: (args) => CrossSelling(args),
  play: () => {
    slider();
  },
  args: {
    products,
    title: 'Derniers produits consultés',
    button: { href: '#', label: 'Tout voir' }
  },
  parameters: {
    backgrounds: { default: 'theme-lighter' }
  },
  argTypes: {}
};
