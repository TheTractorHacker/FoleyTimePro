document.addEventListener('DOMContentLoaded', function() {
  // Close modal on backdrop click
  ['modal', 'adjustPopup', 'confirmPopup', 'customPopup'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener('click', function(e) {
        if (e.target === el) el.classList.add('hidden');
      });
    }
  });

  // Close modal button
  var closeBtn = document.getElementById('modalClose');
  if (closeBtn) {
    closeBtn.addEventListener('click', function() {
      document.getElementById('modal').classList.add('hidden');
    });
  }

  // Feedback popup close
  var popupClose = document.getElementById('popupClose');
  if (popupClose) {
    popupClose.addEventListener('click', function() {
      document.getElementById('customPopup').classList.add('hidden');
    });
  }

  // Focus PIN field when modal becomes visible (MutationObserver)
  var modal = document.getElementById('modal');
  if (modal) {
    new MutationObserver(function() {
      if (!modal.classList.contains('hidden')) {
        var pin = document.getElementById('modalPIN');
        if (pin) setTimeout(function() { pin.focus(); }, 80);
      }
    }).observe(modal, { attributes: true, attributeFilter: ['class'] });
  }
});
