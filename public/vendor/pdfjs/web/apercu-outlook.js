// Barre d'outils façon aperçu Word/Outlook (demande explicite de
// l'utilisateur, 2026-09-07/08 — deux références successives : d'abord
// l'aperçu PDF d'Outlook (pastille flottante en bas), puis précisé avec
// l'aperçu .docx d'Outlook (barre claire en haut, boutons libellés,
// indicateur de page en bas à gauche) — cette seconde référence remplace
// la première. Ne recrée AUCUN comportement de PDF.js : déplace
// uniquement les éléments d'origine (zoom -/+, recherche, rotation, menu
// "...", numéro de page) dans une nouvelle barre — mêmes id, mêmes
// écouteurs d'évènement déjà attachés par viewer.mjs, donc zéro risque sur
// le fonctionnement réel, seul l'emplacement/l'habillage visuel change.
// Script classique (pas de type="module") placé juste avant </body> :
// s'exécute pendant le parsing, donc après que tout le HTML au-dessus (la
// barre d'outils d'origine) existe déjà dans le DOM.
(function () {
  const barre = document.getElementById('apercuBarreOutlook');
  const indicateurPage = document.getElementById('apercuIndicateurPage');
  const zoomOut = document.getElementById('zoomOutButton');
  const zoomIn = document.getElementById('zoomInButton');
  const rechercheBouton = document.getElementById('viewFindButton');
  const rotation = document.getElementById('pageRotateCw');
  const outilsToggle = document.getElementById('secondaryToolbarToggle');
  const pageNumber = document.getElementById('pageNumber');
  const numPages = document.getElementById('numPages');

  // Best-effort : si un futur changement de PDF.js renomme un de ces id,
  // on abandonne proprement plutôt que de laisser une barre à moitié
  // vide — le bandeau d'origine (masqué par apercu-outlook.css) ne
  // réapparaît pas tout seul, mais mieux vaut un aperçu sans barre
  // personnalisée qu'une barre visuellement cassée.
  if (!barre || !indicateurPage || !zoomOut || !zoomIn || !rechercheBouton
      || !rotation || !outilsToggle || !pageNumber || !numPages) {
    return;
  }

  const groupeZoom = document.createElement('div');
  groupeZoom.className = 'apercuGroupe';
  groupeZoom.append(zoomOut, zoomIn);

  const groupeActions = document.createElement('div');
  groupeActions.className = 'apercuGroupe';
  groupeActions.append(
    // Conteneur du bouton recherche ET de son popup (#findbar) : les deux
    // doivent rester ensemble, le popup se positionne relativement à lui.
    rechercheBouton.parentElement,
    rotation,
  );

  barre.append(
    groupeZoom,
    groupeActions,
    // Conteneur du bouton "..." ET de son menu (#secondaryToolbar), même
    // raison que la recherche ci-dessus.
    outilsToggle,
  );

  // "Page X sur Y" en bas à gauche : réutilise le champ de page éditable et
  // le compteur d'origine (navigation par page toujours fonctionnelle),
  // simplement redéplacés et stylés pour ressembler à du texte simple.
  const libellePage = document.createElement('span');
  libellePage.textContent = 'Page';
  libellePage.className = 'apercuLibellePage';

  indicateurPage.append(libellePage, pageNumber, numPages);
})();
