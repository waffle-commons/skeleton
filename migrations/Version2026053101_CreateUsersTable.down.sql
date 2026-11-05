-- Annulation de Version2026053101_CreateUsersTable : `bin/waffle db:rollback` exécute
-- ce script (le même nom, suffixé .down.sql), puis retire la version de waffle_migrations.
DROP TABLE IF EXISTS users;
