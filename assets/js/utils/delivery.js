export function deliveryModule() {
  const inputs = document.querySelectorAll('.DeliveryModule input[type="radio"]');

  inputs.forEach((input) => {
    input.addEventListener('change', function() {
      const allPickupPoints = document.querySelectorAll('.DeliveryModule');
      allPickupPoints.forEach((pickupPoint) => {
        pickupPoint.classList.remove('selected');
      });
      const parent = this.closest('.DeliveryModule');
      parent.classList.toggle('selected', input.checked);
    });
  });
}
