document.addEventListener("DOMContentLoaded", () => {
  const menuBtn = document.getElementById('menuBtn');
  const menuOverlay = document.getElementById('mobileMenuOverlay');
  const closeMenu = document.getElementById('closeMenu');
  const modal = document.getElementById('modal');
  const closeModal = document.getElementById('modalClose');
  const adjustPopup = document.getElementById('adjustPopup');
  const confirmPopup = document.getElementById('confirmPopup');
  const customPopup = document.getElementById('customPopup');
  const popupClose = document.getElementById('popupClose');

  // 🟦 Mobile menu toggle
  if (menuBtn && menuOverlay) {
    menuBtn.addEventListener('click', () => {
      menuOverlay.style.display = 'flex';
    });
  }

  if (closeMenu && menuOverlay) {
    closeMenu.addEventListener('click', () => {
      menuOverlay.style.display = 'none';
    });
  }

  // 🟥 Close buttons
  if (closeModal && modal) {
    closeModal.addEventListener('click', () => {
      modal.classList.add('hidden');
    });
  }

  if (popupClose && customPopup) {
    popupClose.addEventListener('click', () => {
      customPopup.classList.add('hidden');
    });
  }

  // ❌ Click outside to close overlays/popups
  window.addEventListener('click', (e) => {
    if (e.target === modal && modal) modal.classList.add('hidden');
    if (e.target === adjustPopup && adjustPopup) adjustPopup.classList.add('hidden');
    if (e.target === confirmPopup && confirmPopup) confirmPopup.classList.add('hidden');
    if (e.target === customPopup && customPopup) customPopup.classList.add('hidden');
    if (e.target === menuOverlay && menuOverlay) menuOverlay.style.display = 'none';
  });
});
