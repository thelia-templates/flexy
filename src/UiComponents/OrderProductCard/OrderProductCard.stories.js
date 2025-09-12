import OrderProductCard from './OrderProductCard.html.twig';

export default {
  title: 'Design System/Organisms/OrderProductCard'
};

export const base = {
  render  : (args) => OrderProductCard(args),
  args    : {
    img           : { url: '/images/placeholder2.webp', alt: '' },
    productTitle  : 'Nom du produit',
    secondaryTitle: 'Titre secondaire',
    size          : 'S-34/36',
    quantity      : 1,
    price         : '50,00€'
  },
  argTypes: {}
};
