// @ts-ignore
import Twig from 'twig';
// @ts-ignore
import IconTwig from './IconTwig.twig';
// @ts-ignore
import PathTwig from './PathTwig.html.twig';

Twig.extendFunction('ux_icon', (iconName: string): string => {
  return IconTwig({ icon: iconName });
});

Twig.extendFunction('asset', (path: string): string => {
  return PathTwig({ path: path });
});

Twig.extend(function(Twig: any) {
  Twig.filters.trans = function(value: any) {
    return value;
  };
  Twig.filters.format_currency = function(value: any) {
    return value + ' €';
  };

  Twig.exports.functions.t = function(value: any) {
    return value;
  };

  Twig.exports.functions.resources = function(value: any) {
    return value;
  };

  Twig.exports.functions.stimulus_controller = function() {
    return '';
  };
  Twig.exports.functions.component = function () {
    return '';
  };
});
