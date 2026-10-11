-- Die E-Mail-Adresse der Mitglieder wird nicht mehr abgefragt und nicht mehr gespeichert.
ALTER TABLE users DROP INDEX uq_users_email, DROP COLUMN email;
