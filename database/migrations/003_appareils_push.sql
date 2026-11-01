-- Téléphones qui reçoivent les alertes système.
CREATE TABLE appareils_push (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NOT NULL,
  token VARCHAR(190) NOT NULL,
  plateforme ENUM('ios','android') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_appareil_token (token),
  KEY idx_appareil_user (utilisateur_id),
  CONSTRAINT fk_appareil_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
