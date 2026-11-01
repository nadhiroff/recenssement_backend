-- Données de démonstration ENTIÈREMENT FICTIVES (numéros +22900000xxx, domaines example.test).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO branches (id, nom, description, ordre) VALUES
 (1, 'Branche Houngbo', 'Descendants du fils aîné du fondateur', 1),
 (2, 'Branche Dossou', 'Descendants du deuxième fils du fondateur', 2),
 (3, 'Branche Sènami', 'Descendants de la fille du fondateur', 3);

INSERT INTO personnes (id, sexe, annee_naissance, precision_naissance, annee_deces, precision_deces, est_decede, branche_id, telephone_whatsapp, email, lieu_residence, est_fondateur, est_privee, cree_par_utilisateur_id) VALUES
 -- Génération 0 et 1
 (1,  'M', 1880, 'decennie', 1950, 'environ', 1, NULL, NULL, NULL, 'Sahouè Doutou', 1, 0, 1),
 (2,  'M', 1910, 'environ',  1980, 'environ', 1, 1, NULL, NULL, 'Sahouè Doutou', 0, 0, 1),
 (3,  'M', 1915, 'environ',  1985, 'environ', 1, 2, NULL, NULL, 'Sahouè Doutou', 0, 0, 1),
 (4,  'F', 1920, 'environ',  1990, 'environ', 1, 3, NULL, NULL, 'Sahouè Doutou', 0, 0, 1),
 -- Génération 2 : père à deux épouses (Mawuli, id 5 ; épouses 6 et 7)
 (5,  'M', 1940, 'environ',  2010, 'exacte',  1, 1, NULL, NULL, 'Cotonou', 0, 0, 1),
 (6,  'F', 1945, 'environ',  NULL, 'inconnue',0, 1, '+22900000006', 'afi.demo@example.test', 'Cotonou', 0, 0, 1),
 (7,  'F', 1950, 'environ',  NULL, 'inconnue',0, 1, '+22900000007', NULL, 'Lomé', 0, 0, 1),
 (8,  'M', 1945, 'environ',  NULL, 'inconnue',1, 2, NULL, NULL, 'Sahouè Doutou', 0, 0, 1),
 -- Génération 3 : Kossi ZOCLI né vers 1968, père connu (Mawuli)
 (9,  'M', 1968, 'environ',  NULL, 'inconnue',0, 1, '+22900000009', 'kossi.demo@example.test', 'Cotonou', 0, 0, 1),
 (10, 'F', 1972, 'exacte',   NULL, 'inconnue',0, 1, '+22900000010', 'enyonam.demo@example.test', 'Porto-Novo', 0, 0, 1),
 (11, 'M', 1975, 'exacte',   NULL, 'inconnue',0, 1, '+22900000011', 'sena.demo@example.test', 'Lomé', 0, 0, 1),
 (12, 'M', 1970, 'exacte',   NULL, 'inconnue',0, 2, '+22900000012', 'yao.demo@example.test', 'Cotonou', 0, 0, 1),
 (13, 'F', 1974, 'exacte',   NULL, 'inconnue',0, 2, '+22900000013', 'ayawa.demo@example.test', 'Abomey', 0, 0, 1),
 -- Paire quasi identique ZOCLI / ZOKLI
 (14, 'M', 1978, 'environ',  NULL, 'inconnue',0, 2, NULL, NULL, 'Cotonou', 0, 0, 1),
 (15, 'M', 1979, 'exacte',   NULL, 'inconnue',0, 2, NULL, NULL, 'Paris', 0, 0, 1),
 -- Génération 4
 (16, 'M', 1995, 'exacte',   NULL, 'inconnue',0, 1, '+22900000016', 'edem.demo@example.test', 'Cotonou', 0, 0, 1),
 (17, 'F', 1998, 'exacte',   NULL, 'inconnue',0, 1, '+22900000017', 'dela.demo@example.test', 'Lyon', 0, 0, 1),
 (18, 'M', 2000, 'exacte',   NULL, 'inconnue',0, 2, '+22900000018', 'koffi.demo@example.test', 'Cotonou', 0, 0, 1),
 -- Fiche privée (situation délicate)
 (19, 'F', 1990, 'environ',  NULL, 'inconnue',0, 1, '+22900000019', 'prive.demo@example.test', 'Cotonou', 0, 1, 1),
 -- Autres statuts
 (20, 'F', 1992, 'exacte',   NULL, 'inconnue',0, 1, '+22900000020', 'mawuena.demo@example.test', 'Ouidah', 0, 0, 1),
 (21, 'M', 1994, 'exacte',   NULL, 'inconnue',0, 1, '+22900000021', 'kodjo.demo@example.test', 'Cotonou', 0, 0, 1),
 (22, 'F', 1996, 'exacte',   NULL, 'inconnue',0, 2, '+22900000022', 'abla.demo@example.test', 'Lomé', 0, 0, 1),
 -- Branche 3
 (23, 'M', 1950, 'environ',  NULL, 'inconnue',0, 3, '+22900000023', 'tossou.demo@example.test', 'Sahouè Doutou', 0, 0, 1),
 (24, 'F', 1955, 'environ',  NULL, 'inconnue',0, 3, '+22900000024', 'adjo.demo@example.test', 'Sahouè Doutou', 0, 0, 1),
 -- Administrateur général (fiche distincte de la lignée évaluée)
 (25, 'I', 1980, 'exacte',   NULL, 'inconnue',0, NULL, NULL, 'admin.demo@example.test', 'Cotonou', 0, 0, 1);

UPDATE branches SET ancetre_personne_id = 2 WHERE id = 1;
UPDATE branches SET ancetre_personne_id = 3 WHERE id = 2;
UPDATE branches SET ancetre_personne_id = 4 WHERE id = 3;

INSERT INTO noms_personne (id, personne_id, type, valeur, valeur_normalisee, principal) VALUES
 (1,1,'nom_famille','ZOCLI','ZOCLI',1),(2,1,'naissance','Agbo','AGBO',1),
 (3,2,'nom_famille','ZOCLI','ZOCLI',1),(4,2,'naissance','Houngbo','HOUNGBO',1),
 (5,3,'nom_famille','ZOCLI','ZOCLI',1),(6,3,'naissance','Dossou','DOSSOU',1),
 (7,4,'nom_famille','ZOCLI','ZOCLI',1),(8,4,'naissance','Sènami','SENAMI',1),
 (9,5,'nom_famille','ZOCLI','ZOCLI',1),(10,5,'naissance','Mawuli','MAWULI',1),(11,5,'bapteme','Benoît','BENOIT',0),
 (12,6,'nom_famille','ADJOVI','ADJOVI',1),(13,6,'naissance','Afi','AFI',1),
 (14,7,'nom_famille','HOUNSOU','HOUNSOU',1),(15,7,'naissance','Akouvi','AKOUVI',1),
 (16,8,'nom_famille','ZOCLI','ZOCLI',1),(17,8,'naissance','Comlan','COMLAN',1),
 (18,9,'nom_famille','ZOCLI','ZOCLI',1),(19,9,'naissance','Kossi','KOSSI',1),(20,9,'vodoun','Hounnon','HOUNNON',0),
 (21,10,'nom_famille','ZOCLI','ZOCLI',1),(22,10,'naissance','Enyonam','ENYONAM',1),
 (23,11,'nom_famille','ZOCLI','ZOCLI',1),(24,11,'naissance','Séna','SENA',1),
 (25,12,'nom_famille','ZOCLI','ZOCLI',1),(26,12,'naissance','Yao','YAO',1),
 (27,13,'nom_famille','ZOCLI','ZOCLI',1),(28,13,'naissance','Ayawa','AYAWA',1),
 (29,14,'nom_famille','ZOCLI','ZOCLI',1),(30,14,'naissance','Mensah','MENSAH',1),
 (31,15,'nom_famille','ZOKLI','ZOCLI',1),(32,15,'naissance','Mensah','MENSAH',1),
 (33,16,'nom_famille','ZOCLI','ZOCLI',1),(34,16,'naissance','Edem','EDEM',1),
 (35,17,'nom_famille','ZOCLI','ZOCLI',1),(36,17,'naissance','Dela','DELA',1),
 (37,18,'nom_famille','ZOCLI','ZOCLI',1),(38,18,'naissance','Koffi','KOFFI',1),
 (39,19,'nom_famille','ZOCLI','ZOCLI',1),(40,19,'naissance','Zinsou','ZINSOU',1),
 (41,20,'nom_famille','ZOCLI','ZOCLI',1),(42,20,'naissance','Mawuena','MAWUENA',1),
 (43,21,'nom_famille','ZOCLI','ZOCLI',1),(44,21,'naissance','Kodjo','KODJO',1),
 (45,22,'nom_famille','ZOCLI','ZOCLI',1),(46,22,'naissance','Abla','ABLA',1),
 (47,23,'nom_famille','ZOCLI','ZOCLI',1),(48,23,'naissance','Tossou','TOSSOU',1),
 (49,24,'nom_famille','ZOCLI','ZOCLI',1),(50,24,'naissance','Adjo','ADJO',1),
 (51,25,'nom_famille','GARDIEN','GARDIEN',1),(52,25,'naissance','Admin','ADMIN',1);

INSERT INTO noms_tokens (nom_id, token) SELECT id, valeur_normalisee FROM noms_personne;

INSERT INTO utilisateurs (id, personne_id, telephone_whatsapp, email, role_global, mot_de_passe_hash) VALUES
 -- Mot de passe de démonstration : DemoAdmin2026!
 (1, 25, NULL, 'admin.demo@example.test', 'admin', 'y$ZvbBZ3ArLxmCWaymyXrIYOXzYGPtZvjdc0su932H39kpDYRokpXy.'),
 (2, 9,  '+22900000009', 'kossi.demo@example.test', 'referent', NULL),
 (3, 10, '+22900000010', 'enyonam.demo@example.test', 'referent', NULL),
 (4, 12, '+22900000012', 'yao.demo@example.test', 'referent', NULL),
 (5, 13, '+22900000013', 'ayawa.demo@example.test', 'referent', NULL),
 (6, 23, '+22900000023', 'tossou.demo@example.test', 'referent', NULL),
 (7, 24, '+22900000024', 'adjo.demo@example.test', 'referent', NULL),
 (8, 11, '+22900000011', 'sena.demo@example.test', 'relais', NULL),
 (9, 16, '+22900000016', 'edem.demo@example.test', 'membre', NULL),
 (10,17, '+22900000017', 'dela.demo@example.test', 'membre', NULL),
 (11,18, '+22900000018', 'koffi.demo@example.test', 'membre', NULL),
 (12,20, '+22900000020', 'mawuena.demo@example.test', 'membre', NULL);

INSERT INTO referents_branche (utilisateur_id, branche_id) VALUES (2,1),(3,1),(4,2),(5,2),(6,3),(7,3);
INSERT INTO relais_branche (utilisateur_id, branche_id) VALUES (8,1);

INSERT INTO unions (id, epoux_id, epouse_id, rang_epouse, annee_union, precision_union, statut) VALUES
 (1, 5, 6, 1, 1965, 'environ', 'valide'),
 (2, 5, 7, 2, 1971, 'environ', 'valide');

INSERT INTO liens_parente (id, parent_id, enfant_id, role_parent, statut, declarant_utilisateur_id, declare_le, confirme_par_utilisateur_id, confirme_le, valide_par_utilisateur_id, valide_le, motif_prive) VALUES
 (1, 1, 2,  'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (2, 1, 3,  'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (3, 1, 4,  'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (4, 2, 5,  'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (5, 3, 8,  'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (6, 5, 9,  'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (7, 6, 9,  'mere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (8, 5, 10, 'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (9, 6, 10, 'mere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (10,5, 11, 'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (11,7, 11, 'mere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (12,8, 12, 'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (13,8, 13, 'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (14,8, 14, 'pere','valide',1,'2026-01-05 10:00:00',1,'2026-01-06 10:00:00',1,'2026-01-07 10:00:00',NULL),
 (15,8, 15, 'pere','declare',1,'2026-10-01 10:00:00',NULL,NULL,NULL,NULL,NULL),
 -- Conflit d'intérêts : Edem (16) est fils du référent Kossi (9)
 (16,9, 16, 'pere','confirme',9,'2026-10-02 10:00:00',10,'2026-10-03 10:00:00',NULL,NULL,NULL),
 (17,9, 17, 'pere','valide',9,'2026-01-05 10:00:00',10,'2026-01-06 10:00:00',3,'2026-01-07 10:00:00',NULL),
 (18,12,18, 'pere','declare',11,'2026-10-20 10:00:00',NULL,NULL,NULL,NULL,NULL),
 -- Lien privé
 (19,11,19, 'pere','prive',8,'2026-02-01 10:00:00',NULL,NULL,NULL,NULL,'Situation délicate (fictif)'),
 -- En examen
 (20,10,20, 'mere','en_examen',12,'2026-03-01 10:00:00',NULL,NULL,NULL,NULL,NULL),
 -- Rejeté
 (21,11,21, 'pere','rejete',NULL,'2026-03-05 10:00:00',NULL,NULL,NULL,NULL,NULL),
 -- Confirmé validable (ni Kossi ni Enyonam ne sont concernés)
 (22,11,21, 'pere','confirme',8,'2026-04-05 10:00:00',10,'2026-04-06 10:00:00',NULL,NULL,NULL),
 (23,12,22, 'pere','confirme',4,'2026-04-05 10:00:00',5,'2026-04-06 10:00:00',NULL,NULL,NULL);

UPDATE liens_parente SET motif_rejet = 'Erreur de saisie (fictif)' WHERE id = 21;

-- Faire apparaître le fondateur comme parent reste possible : le seed couvre tous les statuts.
SET FOREIGN_KEY_CHECKS = 1;
