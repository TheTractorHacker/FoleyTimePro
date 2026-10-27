// CSP-safe replacement for inline onerror="...": swap in data-fallback when an image fails to load.
document.addEventListener('error', function (e) {
  var img = e.target;
  if (img.tagName === 'IMG' && img.dataset.fallback && img.src.indexOf(img.dataset.fallback) === -1) {
    img.src = img.dataset.fallback;
  }
}, true);
