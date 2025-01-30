export function pickupPointHours() {
  const pickupPointModule = document.querySelector('.PickupPointModule');
  pickupPointModule.addEventListener('click', function (event) {
    if (event.target.matches('.PickupPoint-hours')) {
      const parent = event.target.closest('.PickupPoint');
      const hoursListing = parent.querySelector('.PickupPoint-hoursListing');
      hoursListing.classList.toggle('md:hidden');
    }
  });
}

export function pickupPoint() {
  const inputs = document.querySelectorAll('.PickupPoint input[type="radio"]');

  inputs.forEach((input) => {
    input.addEventListener('change', function () {
      const allPickupPoints = document.querySelectorAll('.PickupPoint');
      allPickupPoints.forEach((pickupPoint) => {
        pickupPoint.classList.remove('selected');
      });
      const parent = this.closest('.PickupPoint');
      parent.classList.toggle('selected', input.checked);
    });
  });
}
