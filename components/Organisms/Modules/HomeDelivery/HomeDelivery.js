export function HomeDeliveryAddresses() {
  const inputs = document.querySelectorAll('.HomeDelivery .AddressCard input[type="radio"]');

  inputs.forEach((input) => {
    input.addEventListener('change', function() {
      const allAddresses = document.querySelectorAll('.HomeDelivery .AddressCard');
      allAddresses.forEach((address) => {
        address.classList.remove('selected');
      });
      const parent = this.closest('.AddressCard');
      parent.classList.toggle('selected', input.checked);
    });
  });
}
