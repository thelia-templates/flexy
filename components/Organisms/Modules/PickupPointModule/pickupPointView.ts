export function PickupPointView() {
  const buttonMapView = document.querySelector(
    '.PickupPointModule-buttonMapView'
  );
  const buttonListView = document.querySelector(
    '.PickupPointModule-buttonListView'
  );
  const mapView = document.querySelector('.PickupPointModule-mapView');
  const listView = document.querySelector('.PickupPointModule-listView');

  if (!buttonMapView || !buttonListView || !mapView || !listView) {
    return;
  }

  buttonMapView.addEventListener('click', function () {
    console.log('View');
    mapView.classList.remove('hidden');
    listView.classList.add('hidden');
    buttonListView.classList.add('Button--secondary');
    buttonMapView.classList.remove('Button--secondary');
    const event = new Event('invalidateMap');
    window.dispatchEvent(event);
  });
  buttonListView.addEventListener('click', function () {
    console.log('buttonList');
    listView.classList.remove('hidden');
    mapView.classList.add('hidden');
    buttonMapView.classList.add('Button--secondary');
    buttonListView.classList.remove('Button--secondary');
  });
}
