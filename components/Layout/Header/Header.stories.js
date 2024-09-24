import Header from './Header.html.twig';
import HeaderScript from './Header.js';
import HeaderCheckout from './HeaderCheckout.html.twig';

export default {
  title: 'Design System/Layout/Header'
};

const types = ['generic', 'sticky', 'searchbar'];

// generate items
const categories = [];
const numberOfItems = 5;
const numberOfSubItems = 3;

for (let i = 1; i <= numberOfItems; i++) {
  categories.push({
    i18ns: {
      title: `Item ${i}`
    },
    publicUrl: `#`,
    subs1: Array.from({ length: numberOfSubItems }, () => ({
      i18ns: {
        title: `Sous item niv 1`
      },
      publicUrl: '#',
      subs2: Array.from({ length: numberOfSubItems }, () => ({
        i18ns: {
          title: `Sous item niv 2`
        },
        publicUrl: '#'
      }))
    }))
  });
}

export const base = {
  render: (args) => Header(args),
  play: () => {
    HeaderScript();
  },
  args: {
    type: 'generic',
    categories: categories,
    childCount: numberOfItems
  },
  argTypes: {
    type: {
      options: types,
      control: { type: 'radio' }
    }
  }
};

export const checkout = {
  render: (args) => HeaderCheckout(args),
  args: {},
  argTypes: {}
};
