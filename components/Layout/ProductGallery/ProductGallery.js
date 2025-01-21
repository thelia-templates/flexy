import Splide from '@splidejs/splide';

export default function ProductGallery() {
  const galleryNode = document.getElementById('ProductGallery');
  const thumbnails = document.querySelectorAll('.ProductGallery-thumbnail');

  if (!galleryNode) return null;

  const main = new Splide(galleryNode, {
    pagination: false,
    destroy: galleryNode.dataset?.count <= 1,
    breakpoints: {
      768: {
        pagination: true,
        arrows: false
      }
    }
  });
  main.mount();

  if (thumbnails.length > 0) {
    thumbnails.forEach((thumb, index) => {
      thumb.addEventListener('click', function () {
        main.go(index);
        updateActiveThumbnails(index);
      });
    });
  }

  main.on('move', (newIndex) => {
    updateActiveThumbnails(newIndex);
  });

  function updateActiveThumbnails(activeIndex) {
    thumbnails.forEach((t, index) => {
      t.parentNode.classList.toggle('is-active', index === activeIndex);
    });
  }
}
