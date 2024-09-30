/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './components/**/*.{twig,ts,js,json}',
    './form/**/*.twig',
    './*.twig'
  ],
  theme: {
    extend: {
      colors: {
        'theme-dark': 'var(--theme-dark)',
        theme: 'var(--theme)',
        'theme-medium': 'var(--theme-medium)',
        'theme-light': 'var(--theme-light)',
        'theme-lighter': 'var(--theme-lighter)',
        'theme-lightest': 'var(--theme-lightest)',
        black: 'var(--black)',
        white: 'var(--white)',
        'grey-darker': 'var(--grey-darker)',
        'grey-dark': 'var(--grey-dark)',
        grey: 'var(--grey)',
        'grey-lighter': 'var(--grey-lighter)',
        'grey-lightest': 'var(--grey-lightest)',
        'error-dark': 'var(--error-dark)',
        error: 'var(--error)',
        'error-lighter': 'var(--error-lighter)',
        'error-lightest': 'var(--error-lightest)',
        'success-dark': 'var(--success-dark)',
        success: 'var(--success)',
        'success-lightest': 'var(--success-lightest)',
        warning: 'var(--warning)',
        'warning-lightest': 'var(--warning-lightest)',
        validated: 'var(--validated)',
        'validated-lightest': 'var(--validated-lightest)',
        informative: 'var(--informative)',
        'informative-lightest': 'var(--informative-lightest)',
        processing: 'var(--processing)',
        'processing-lightest': 'var(--processing-lightest)',
        'checkbox-focus': 'var(--checkbox-focus)'
      },
      screens: {
        xxs: '320px',
        '3xl': '1920px',
        max2xl: { max: '1680px' },
        maxXl: { max: '1366px' },
        maxLg: { max: '1024px' },
        maxMd: { max: '768px' },
        maxSm: { max: '390px' },
        maxXs: { max: '321px' }
      }
    },
    screens: {
      xs: '390px',
      sm: '640px',
      md: '768px',
      lg: '1024px',
      xl: '1366px',
      '2xl': '1680px'
    },
    fontSize: {
      '2xs': 'var(--font-size-2xs)',
      xs: 'var(--font-size-xs)',
      sm: 'var(--font-size-sm)',
      base: 'var(--font-size-base)',
      lg: 'var(--font-size-lg)',
      xl: 'var(--font-size-xl)',
      '2xl': 'var(--font-size-2xl)',
      '3xl': 'var(--font-size-3xl)',
      '4xl': 'var(--font-size-4xl)'
    }
  },
  plugins: [
    function ({ addComponents }) {
      addComponents({
        '.container': {
          maxWidth: '272px',
          marginLeft: 'auto',
          marginRight: 'auto',
          '@screen xs': {
            maxWidth: 'calc(100% - 56px)'
          },
          '@screen sm': {
            maxWidth: 'calc(100% - 108px)'
          },
          '@screen md': {
            maxWidth: '540px'
          },
          '@screen lg': {
            maxWidth: '864px'
          },
          '@screen xl': {
            maxWidth: '1206px'
          }
        },
        '.container-medium': {
          maxWidth: '100%',
          '@screen xs': {
            maxWidth: '272px'
          },
          '@screen sm': {
            maxWidth: '340px'
          },
          '@screen md': {
            maxWidth: '540px'
          }
        },
        '.container-small': {
          maxWidth: '100%',
          '@screen xs': {
            maxWidth: '272px'
          },
          '@screen sm': {
            maxWidth: '340px'
          },
          '@screen md': {
            maxWidth: '400px'
          }
        }
      });
    }
  ]
};
