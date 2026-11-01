// Fenêtres et confirmations du panneau : aucune boîte native du navigateur.
(function () {
  var boite = document.getElementById('confirmation');

  // Les messages passagers s'effacent seuls ; les avertissements permanents restent.
  document.querySelectorAll('.alerte[role]').forEach(function (a) {
    setTimeout(function () {
      a.classList.add('parti');
      setTimeout(function () { a.remove(); }, 300);
    }, 5000);
  });

  function demander(message, oui, non, suite) {
    boite.querySelector('[data-message]').textContent = message;
    var bOui = boite.querySelector('[data-oui]');
    var bNon = boite.querySelector('[data-non]');
    bOui.textContent = oui || 'Oui';
    bNon.textContent = non || 'Non';
    bOui.onclick = function () { boite.close(); suite(); };
    bNon.onclick = function () { boite.close(); };
    boite.showModal();
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    var m = f.getAttribute('data-confirmer');
    if (!m || f.dataset.valide) return;
    e.preventDefault();
    var fin = function () { f.dataset.valide = '1'; f.requestSubmit ? f.requestSubmit() : f.submit(); };
    var m2 = f.getAttribute('data-confirmer2');
    demander(m, f.getAttribute('data-oui'), f.getAttribute('data-non'), function () {
      if (m2) demander(m2, f.getAttribute('data-oui'), f.getAttribute('data-non'), fin);
      else fin();
    });
  });

  document.addEventListener('click', function (e) {
    var ouvrir = e.target.closest('[data-ouvrir]');
    if (ouvrir) document.getElementById(ouvrir.getAttribute('data-ouvrir')).showModal();
    var fermer = e.target.closest('[data-fermer]');
    if (fermer) fermer.closest('dialog').close();
    if (e.target.tagName === 'DIALOG') e.target.close();
  });
})();
