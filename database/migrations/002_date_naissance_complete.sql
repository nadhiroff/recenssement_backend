-- Jour et mois facultatifs : l'année reste le seul champ de date indispensable.
ALTER TABLE personnes
  ADD COLUMN mois_naissance TINYINT UNSIGNED NULL AFTER annee_naissance,
  ADD COLUMN jour_naissance TINYINT UNSIGNED NULL AFTER mois_naissance;
