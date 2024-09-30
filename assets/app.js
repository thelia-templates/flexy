/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import '@components/base.css';

import './bootstrap.js';

import MobileDrawer from './js/mobileDrawer';
import filterSelect from '@components/Molecules/Filters/FilterSelect/FilterSelect';
import { quantityButton } from '@components/Molecules/Button/button';
import { FieldInputFunction } from '@components/Molecules/Fields/FieldInput/FieldInput';
import { ModalFunction } from '@components/Molecules/Modal/Modal';
import { slider } from '@js/slider';
import PasswordControlsFunction from '@components/Molecules/PasswordControls/PasswordControls';
import headerButtonProfileFunction from '@components/Molecules/HeaderButton/HeaderButtonProfile';

import StepsFunction from '@components/Molecules/Step/Steps.js';

function main() {
  document.body.classList.remove('no-js');

  MobileDrawer();
  filterSelect();
  quantityButton();
  slider();
  PasswordControlsFunction();
  StepsFunction();
  FieldInputFunction();
  ModalFunction();
  headerButtonProfileFunction();
}

document.addEventListener('DOMContentLoaded', () => {
  main();
});
