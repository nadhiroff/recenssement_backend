-- Recensement famille ZOCLI — schéma MySQL 8 / MariaDB 10.4+ (InnoDB, utf8mb4)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS file_sync, notifications, rate_limits, taches_execution, journal_actions, fusions,
  paires_distinctes, doublons_suspects, parametres, variantes_noms, sessions_api, otp_codes, consentements,
  invitations_confirmation, declarations_tiers, relais_branche, referents_branche, utilisateurs, unions,
  liens_parente, noms_tokens, noms_personne, personnes, branches;

CREATE TABLE branches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  ancetre_personne_id INT UNSIGNED NULL,
  ordre INT NOT NULL DEFAULT 0,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_branches_nom (nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sexe ENUM('M','F','I') NOT NULL DEFAULT 'I',
  annee_naissance SMALLINT NULL,
  precision_naissance ENUM('exacte','environ','decennie','inconnue') NOT NULL DEFAULT 'inconnue',
  annee_deces SMALLINT NULL,
  precision_deces ENUM('exacte','environ','decennie','inconnue') NOT NULL DEFAULT 'inconnue',
  est_decede TINYINT(1) NOT NULL DEFAULT 0,
  branche_id INT UNSIGNED NULL,
  telephone_whatsapp VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  lieu_residence VARCHAR(160) NULL,
  est_fondateur TINYINT(1) NOT NULL DEFAULT 0,
  est_privee TINYINT(1) NOT NULL DEFAULT 0,
  fusionnee_dans_id INT UNSIGNED NULL,
  statut_fiche ENUM('active','retiree') NOT NULL DEFAULT 'active',
  cree_par_utilisateur_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_personnes_branche_annee (branche_id, annee_naissance),
  KEY idx_personnes_fusion (fusionnee_dans_id),
  CONSTRAINT fk_personnes_branche FOREIGN KEY (branche_id) REFERENCES branches(id),
  CONSTRAINT fk_personnes_fusion FOREIGN KEY (fusionnee_dans_id) REFERENCES personnes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE branches
  ADD CONSTRAINT fk_branches_ancetre FOREIGN KEY (ancetre_personne_id) REFERENCES personnes(id);

CREATE TABLE noms_personne (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_id INT UNSIGNED NOT NULL,
  type ENUM('naissance','vodoun','bapteme','surnom','nom_famille') NOT NULL,
  valeur VARCHAR(160) NOT NULL,
  valeur_normalisee VARCHAR(160) NOT NULL,
  principal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_noms_normalise (valeur_normalisee),
  KEY idx_noms_personne (personne_id),
  CONSTRAINT fk_noms_personne FOREIGN KEY (personne_id) REFERENCES personnes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mots normalisés de chaque nom : « Kossi Jean » se retrouve par KOSSI comme par JEAN.
CREATE TABLE noms_tokens (
  nom_id INT UNSIGNED NOT NULL,
  token VARCHAR(80) NOT NULL,
  PRIMARY KEY (nom_id, token),
  KEY idx_tokens_token (token),
  CONSTRAINT fk_tokens_nom FOREIGN KEY (nom_id) REFERENCES noms_personne(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE utilisateurs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_id INT UNSIGNED NULL,
  telephone_whatsapp VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  role_global ENUM('membre','relais','referent','admin') NOT NULL DEFAULT 'membre',
  mot_de_passe_hash VARCHAR(255) NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  derniere_connexion DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_utilisateurs_tel (telephone_whatsapp),
  KEY idx_utilisateurs_email (email),
  KEY idx_utilisateurs_personne (personne_id),
  CONSTRAINT fk_utilisateurs_personne FOREIGN KEY (personne_id) REFERENCES personnes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE personnes
  ADD CONSTRAINT fk_personnes_createur FOREIGN KEY (cree_par_utilisateur_id) REFERENCES utilisateurs(id);

CREATE TABLE liens_parente (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NOT NULL,
  enfant_id INT UNSIGNED NOT NULL,
  role_parent ENUM('pere','mere') NOT NULL,
  nature_lien ENUM('biologique','adoption','confie') NOT NULL DEFAULT 'biologique',
  statut ENUM('declare','confirme','valide','en_examen','prive','rejete') NOT NULL DEFAULT 'declare',
  declarant_utilisateur_id INT UNSIGNED NULL,
  declare_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirme_par_utilisateur_id INT UNSIGNED NULL,
  confirme_le DATETIME NULL,
  valide_par_utilisateur_id INT UNSIGNED NULL,
  valide_le DATETIME NULL,
  motif_rejet VARCHAR(500) NULL,
  motif_prive VARCHAR(500) NULL,
  derniere_relance_le DATETIME NULL,
  nb_relances TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Un seul lien actif par rôle : NULL (rejeté) échappe à l'unicité.
  enfant_actif_id INT UNSIGNED GENERATED ALWAYS AS (IF(statut = 'rejete', NULL, enfant_id)) VIRTUAL,
  UNIQUE KEY uq_lien_actif (enfant_actif_id, role_parent),
  KEY idx_liens_enfant (enfant_id, role_parent, statut),
  KEY idx_liens_parent (parent_id, statut),
  KEY idx_liens_statut_date (statut, declare_le),
  CONSTRAINT fk_liens_parent FOREIGN KEY (parent_id) REFERENCES personnes(id),
  CONSTRAINT fk_liens_enfant FOREIGN KEY (enfant_id) REFERENCES personnes(id),
  CONSTRAINT fk_liens_declarant FOREIGN KEY (declarant_utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_liens_confirme FOREIGN KEY (confirme_par_utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_liens_valide FOREIGN KEY (valide_par_utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT ck_liens_distincts CHECK (parent_id <> enfant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE unions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  epoux_id INT UNSIGNED NOT NULL,
  epouse_id INT UNSIGNED NOT NULL,
  rang_epouse TINYINT UNSIGNED NOT NULL DEFAULT 1,
  annee_union SMALLINT NULL,
  precision_union ENUM('exacte','environ','decennie','inconnue') NOT NULL DEFAULT 'inconnue',
  statut ENUM('declare','confirme','valide','en_examen','prive','rejete') NOT NULL DEFAULT 'declare',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_unions_epoux (epoux_id),
  KEY idx_unions_epouse (epouse_id),
  CONSTRAINT fk_unions_epoux FOREIGN KEY (epoux_id) REFERENCES personnes(id),
  CONSTRAINT fk_unions_epouse FOREIGN KEY (epouse_id) REFERENCES personnes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE referents_branche (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NOT NULL,
  branche_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_referent (utilisateur_id, branche_id),
  KEY idx_referent_branche (branche_id),
  CONSTRAINT fk_ref_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_ref_branche FOREIGN KEY (branche_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE relais_branche (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NOT NULL,
  branche_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_relais (utilisateur_id, branche_id),
  KEY idx_relais_branche (branche_id),
  CONSTRAINT fk_relais_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_relais_branche FOREIGN KEY (branche_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE declarations_tiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_id INT UNSIGNED NOT NULL,
  declarant_utilisateur_id INT UNSIGNED NOT NULL,
  type ENUM('enfant_mineur','parent_decede','aine','autre') NOT NULL,
  present_lors_saisie TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_decl_personne (personne_id),
  KEY idx_decl_declarant (declarant_utilisateur_id),
  CONSTRAINT fk_decl_personne FOREIGN KEY (personne_id) REFERENCES personnes(id),
  CONSTRAINT fk_decl_user FOREIGN KEY (declarant_utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitations_confirmation (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lien_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  telephone_invite VARCHAR(20) NULL,
  cree_par INT UNSIGNED NOT NULL,
  expire_le DATETIME NOT NULL,
  utilise_le DATETIME NULL,
  reponse ENUM('confirme','refuse') NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invitation_token (token_hash),
  KEY idx_invitation_lien (lien_id),
  CONSTRAINT fk_invit_lien FOREIGN KEY (lien_id) REFERENCES liens_parente(id),
  CONSTRAINT fk_invit_user FOREIGN KEY (cree_par) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consentements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_id INT UNSIGNED NOT NULL,
  utilisateur_id INT UNSIGNED NOT NULL,
  version_texte VARCHAR(40) NOT NULL,
  accepte_le DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  retire_le DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_consent_personne (personne_id),
  CONSTRAINT fk_consent_personne FOREIGN KEY (personne_id) REFERENCES personnes(id),
  CONSTRAINT fk_consent_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifiant VARCHAR(190) NOT NULL,
  email_destination VARCHAR(190) NULL,
  code_hash VARCHAR(255) NOT NULL,
  canal ENUM('email','whatsapp') NOT NULL,
  expire_le DATETIME NOT NULL,
  tentatives TINYINT UNSIGNED NOT NULL DEFAULT 0,
  utilise_le DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_otp_identifiant (identifiant, utilise_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions_api (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expire_le DATETIME NOT NULL,
  user_agent VARCHAR(255) NULL,
  revoque_le DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session_token (token_hash),
  KEY idx_session_user (utilisateur_id),
  CONSTRAINT fk_session_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE variantes_noms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variante VARCHAR(80) NOT NULL,
  forme_canonique VARCHAR(80) NOT NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_variante (variante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parametres (
  cle VARCHAR(60) PRIMARY KEY,
  valeur VARCHAR(255) NOT NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doublons_suspects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_a_id INT UNSIGNED NOT NULL,
  personne_b_id INT UNSIGNED NOT NULL,
  score SMALLINT NOT NULL,
  detail_score JSON NULL,
  statut ENUM('a_traiter','fusionne','distinct') NOT NULL DEFAULT 'a_traiter',
  traite_par INT UNSIGNED NULL,
  traite_le DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doublon (personne_a_id, personne_b_id),
  KEY idx_doublon_statut (statut),
  CONSTRAINT fk_doublon_a FOREIGN KEY (personne_a_id) REFERENCES personnes(id),
  CONSTRAINT fk_doublon_b FOREIGN KEY (personne_b_id) REFERENCES personnes(id),
  CONSTRAINT fk_doublon_user FOREIGN KEY (traite_par) REFERENCES utilisateurs(id),
  CONSTRAINT ck_doublon_ordre CHECK (personne_a_id < personne_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE paires_distinctes (
  personne_a_id INT UNSIGNED NOT NULL,
  personne_b_id INT UNSIGNED NOT NULL,
  declare_par INT UNSIGNED NOT NULL,
  declare_le DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (personne_a_id, personne_b_id),
  CONSTRAINT fk_paire_a FOREIGN KEY (personne_a_id) REFERENCES personnes(id),
  CONSTRAINT fk_paire_b FOREIGN KEY (personne_b_id) REFERENCES personnes(id),
  CONSTRAINT fk_paire_user FOREIGN KEY (declare_par) REFERENCES utilisateurs(id),
  CONSTRAINT ck_paire_ordre CHECK (personne_a_id < personne_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fusions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_conservee_id INT UNSIGNED NOT NULL,
  personne_absorbee_id INT UNSIGNED NOT NULL,
  doublon_id INT UNSIGNED NULL,
  effectuee_par INT UNSIGNED NOT NULL,
  effectuee_le DATETIME NOT NULL,
  snapshot_avant LONGTEXT NOT NULL,
  annulee_par INT UNSIGNED NULL,
  annulee_le DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fusion_conservee (personne_conservee_id),
  KEY idx_fusion_absorbee (personne_absorbee_id),
  CONSTRAINT fk_fusion_conservee FOREIGN KEY (personne_conservee_id) REFERENCES personnes(id),
  CONSTRAINT fk_fusion_absorbee FOREIGN KEY (personne_absorbee_id) REFERENCES personnes(id),
  CONSTRAINT fk_fusion_user FOREIGN KEY (effectuee_par) REFERENCES utilisateurs(id),
  CONSTRAINT fk_fusion_annule FOREIGN KEY (annulee_par) REFERENCES utilisateurs(id),
  CONSTRAINT fk_fusion_doublon FOREIGN KEY (doublon_id) REFERENCES doublons_suspects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entite_type VARCHAR(40) NULL,
  entite_id BIGINT UNSIGNED NULL,
  branche_id INT UNSIGNED NULL,
  details JSON NULL,
  ip VARCHAR(45) NULL,
  cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_journal_entite (entite_type, entite_id),
  KEY idx_journal_branche (branche_id, id),
  KEY idx_journal_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  message VARCHAR(500) NOT NULL,
  lien_id INT UNSIGNED NULL,
  lu_le DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_notif_user (utilisateur_id, lu_le),
  CONSTRAINT fk_notif_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  cle CHAR(64) PRIMARY KEY,
  fenetre_debut INT UNSIGNED NOT NULL,
  compteur INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE taches_execution (
  nom VARCHAR(40) PRIMARY KEY,
  derniere_execution DATETIME NULL,
  statut VARCHAR(20) NULL,
  resume VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE file_sync (
  client_uuid CHAR(36) PRIMARY KEY,
  utilisateur_id INT UNSIGNED NOT NULL,
  recu_le DATETIME NOT NULL,
  resultat JSON NOT NULL,
  KEY idx_sync_user (utilisateur_id),
  CONSTRAINT fk_sync_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Journal non modifiable : UPDATE et DELETE refusés par la base elle-même.
DROP TRIGGER IF EXISTS journal_no_update;
CREATE TRIGGER journal_no_update BEFORE UPDATE ON journal_actions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_actions est non modifiable';
DROP TRIGGER IF EXISTS journal_no_delete;
CREATE TRIGGER journal_no_delete BEFORE DELETE ON journal_actions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_actions est non modifiable';

INSERT INTO parametres (cle, valeur, description) VALUES
 ('score_prenom_commun', '30', 'Points : prénom commun'),
 ('score_meme_branche', '20', 'Points : même branche'),
 ('score_meme_pere', '25', 'Points : même père'),
 ('score_naissance_5ans', '15', 'Points : naissance dans l''écart maximal'),
 ('score_meme_mere', '10', 'Points : même mère'),
 ('seuil_proposition', '50', 'Score minimal pour proposer une fiche à l''inscrit'),
 ('seuil_doublon', '70', 'Score minimal pour signaler un doublon'),
 ('ecart_annees_max', '5', 'Écart maximal entre années de naissance (années)'),
 ('delai_relance_jours', '60', 'Délai avant première relance (jours)'),
 ('delai_relance_suivante_jours', '30', 'Délai entre deux relances (jours)'),
 ('nb_relances_max', '3', 'Nombre maximal de relances'),
 ('generations_lignee_feminine', '-1', 'Point ouvert : générations affichées par les filles (-1 = sans limite)'),
 ('nature_lien_active', '0', 'Point ouvert : activer adoption / enfant confié (1 = oui)'),
 ('arbre_visible_membres', '1', 'Point ouvert : arbre consultable par les membres (1 = oui)');

INSERT INTO variantes_noms (variante, forme_canonique) VALUES
 ('ZOKLI', 'ZOCLI'), ('ZOCKLI', 'ZOCLI'), ('ZOKLY', 'ZOCLI'), ('ZOCLY', 'ZOCLI');

INSERT INTO parametres (cle, valeur, description) VALUES
 ('doublon_prenom_requis', '0', 'Exiger un prénom commun pour signaler un doublon (0 = règle du cahier : score seul)');
