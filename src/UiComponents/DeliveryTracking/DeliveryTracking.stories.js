import DeliveryTracking from './DeliveryTracking.html.twig';

export default {
  title: 'Design System/Organisms/Card/DeliveryTracking'
};

export const Base = {
  render: (args) => `<div class='w-[500px]''>${DeliveryTracking(args)}</div>`,
  args: {
    number: '0000',
    status: 1,
  }
};
