// Confirmation des actions sensibles : data-confirmer="message" (double avec data-confirmer2).
document.addEventListener('submit', function (e) {
  var f = e.target;
  var m = f.getAttribute('data-confirmer');
  if (m && !window.confirm(m)) { e.preventDefault(); return; }
  var m2 = f.getAttribute('data-confirmer2');
  if (m2 && !window.confirm(m2)) { e.preventDefault(); }
});
