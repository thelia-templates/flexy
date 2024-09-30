import NavigationScript from '../../Organisms/Navigation/Navigation.js';
import Header from './Header.html.twig';
import HeaderCheckout from './HeaderCheckout.html.twig';

export default {
  title: 'Design System/Layout/Header'
};

const types = ['generic', 'sticky', 'searchbar'];

// generate items
const categories = [];
const numberOfItems = 5;

for (let i = 1; i <= numberOfItems; i++) {
  categories.push({
    i18ns: {
      title: `Item Menu ${i}`
    },
    publicUrl: `#`,
    subs1: Array.from({ length: 5 }, () => ({
      i18ns: {
        title: `Sous item niveau 1`
      },
      publicUrl: '#',
      subs2: Array.from(
        {
          length: Math.floor(Math.random() * (6 - 2 + 1)) + 2
        },
        () => ({
          i18ns: {
            title: `Item sous menu niveau 2`
          },
          publicUrl: '#'
        })
      )
    }))
  });
}

export const base = {
  render: (args) => Header(args),
  play: () => {
    NavigationScript();
  },
  args: {
    type: 'generic',
    complexNav: false,
    categories: categories,
    childCount: numberOfItems
  },
  argTypes: {
    type: {
      options: types,
      control: { type: 'radio' }
    },
    complexNav: {
      control: false
    }
  }
};

export const complexNav = {
  render: (args) => Header(args),
  play: () => {
    NavigationScript();
  },
  args: {
    type: 'generic',
    complexNav: true,
    categories: categories,
    childCount: numberOfItems
  },
  argTypes: {
    type: {
      options: types,
      control: { type: 'radio' }
    },
    complexNav: {
      control: false
    }
  }
};

export const checkout = {
  render: (args) => HeaderCheckout(args),
  args: {},
  argTypes: {}
};
