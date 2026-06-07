-- Migration : Création de la table des utilisateurs de base.
-- Le nom de fichier porte la version (Version<AAAAMMJJNN>) ; le runner enregistre
-- chaque version appliquée dans la table waffle_migrations pour la rendre idempotente.
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
